<?php

namespace MetaSyncClient;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use MetaSyncClient\Events\PullCompleted;

/**
 * Pulls pending meta and redirects from MetaSync into the local cache tables
 * and acknowledges what was applied. The local tables are what MetaResolver
 * and the redirects middleware read at request time — no runtime calls to
 * the SaaS.
 */
class SyncService
{
    private const string CURSOR_KEY = 'metasync:last_pull';

    private const string DOMAIN_KEY = 'metasync:project_domain';

    public function __construct(private readonly ApiClient $api) {}

    /**
     * @return array{pages: int, redirects: int}
     */
    public function pull(bool $full = false): array
    {
        $since = $full ? null : Cache::get(self::CURSOR_KEY);

        $this->pullDomains();

        ['applied' => $pageIds, 'deleted' => $deletedPageIds] = $this->pullPages($since);
        ['applied' => $redirectIds, 'deleted' => $deletedRedirectIds] = $this->pullRedirects($since);

        // A full pull is authoritative: rows the server no longer knows about
        // (hard-deleted between pulls) are dropped from the local cache.
        if ($full) {
            DB::table('metasync_pages')->whereNotIn('remote_id', $pageIds)->delete();
            DB::table('metasync_redirects')->whereNotIn('remote_id', $redirectIds)->delete();
        }

        $allIds = [...$pageIds, ...$deletedPageIds];
        $allRedirectIds = [...$redirectIds, ...$deletedRedirectIds];

        if ($allIds !== [] || $allRedirectIds !== []) {
            $this->api->ack($allIds, $allRedirectIds);

            event(new PullCompleted($pageIds, $redirectIds, $deletedPageIds, $deletedRedirectIds));
        }

        $this->reportNotFound();

        return ['pages' => count($pageIds), 'redirects' => count($redirectIds)];
    }

    /**
     * Send 404 hits captured by the redirects middleware to MetaSync and
     * clear the local buffer. Piggybacks on the pull schedule.
     */
    public function reportNotFound(): int
    {
        if (! config('metasync-client.report_404', true) || ! Schema::hasTable('metasync_not_found')) {
            return 0;
        }

        $reported = 0;

        do {
            $rows = DB::table('metasync_not_found')->orderBy('id')->limit(500)->get();

            if ($rows->isEmpty()) {
                break;
            }

            $this->api->reportNotFound($rows->map(fn (object $row): array => array_filter([
                'path' => $row->path,
                'host' => isset($row->host) && $row->host !== '' ? $row->host : null,
                'count' => (int) $row->hits,
                'referer' => $row->referer,
            ], fn ($value) => $value !== null))->all());

            DB::table('metasync_not_found')->whereIn('id', $rows->pluck('id'))->delete();

            $reported += $rows->count();
        } while ($rows->count() === 500);

        return $reported;
    }

    /**
     * The project's own domain as MetaSync knows it — the target for alias
     * traffic without a fallback URL. Null until the first pull.
     */
    public static function projectDomain(): ?string
    {
        $domain = Cache::get(self::DOMAIN_KEY);

        return is_string($domain) && $domain !== '' ? $domain : null;
    }

    /**
     * Mirror the alias hosts from the project info into metasync_domains so
     * the redirects middleware can recognise them without API calls.
     */
    private function pullDomains(): void
    {
        if (! Schema::hasTable('metasync_domains')) {
            return;
        }

        $info = $this->api->projectInfo();
        $data = (array) ($info['data'] ?? []);

        if (is_string($data['domain'] ?? null) && $data['domain'] !== '') {
            Cache::forever(self::DOMAIN_KEY, strtolower((string) preg_replace('/^www\./i', '', $data['domain'])));
        }

        $hosts = [];

        foreach ((array) ($data['domains'] ?? []) as $alias) {
            if (! is_array($alias) || ! is_string($alias['host'] ?? null) || $alias['host'] === '') {
                continue;
            }

            $hosts[] = $alias['host'];

            DB::table('metasync_domains')->updateOrInsert(
                ['host' => $alias['host']],
                [
                    'fallback_url' => $alias['fallback_url'] ?? null,
                    'status_code' => (int) ($alias['status_code'] ?? 301),
                    'is_active' => (bool) ($alias['is_active'] ?? true),
                    'updated_at' => now(),
                    'created_at' => now(),
                ],
            );
        }

        DB::table('metasync_domains')->whereNotIn('host', $hosts)->delete();
    }

    /**
     * @return array{applied: list<int>, deleted: list<int>}
     */
    private function pullPages(?string $since): array
    {
        $ids = [];
        $deletedIds = [];
        $afterId = null;

        do {
            $response = $this->api->pullMeta($since, $afterId);

            foreach ($response['data'] as $row) {
                if (! empty($row['deleted'])) {
                    DB::table('metasync_pages')->where('remote_id', $row['id'])->delete();

                    $deletedIds[] = (int) $row['id'];

                    continue;
                }

                // Same-URL page reissued under a new id would trip the
                // unique index on (url_hash, lang) — clear the stale twin.
                DB::table('metasync_pages')
                    ->where('url_hash', sha1((string) $row['url_path']))
                    ->where('lang', $row['lang'] ?? 'uk')
                    ->where('remote_id', '!=', $row['id'])
                    ->delete();

                DB::table('metasync_pages')->updateOrInsert(
                    ['remote_id' => $row['id']],
                    [
                        'url_path' => $row['url_path'],
                        'url_hash' => sha1((string) $row['url_path']),
                        'lang' => $row['lang'] ?? 'uk',
                        'page_type' => $row['page_type'] ?? null,
                        'title' => $row['title'] ?? null,
                        'description' => $row['description'] ?? null,
                        'h1' => $row['h1'] ?? null,
                        'text' => $row['text'] ?? null,
                        'short_text' => $row['short_text'] ?? null,
                        'og_title' => $row['og_title'] ?? null,
                        'og_description' => $row['og_description'] ?? null,
                        'og_image' => $row['og_image'] ?? null,
                        'canonical_url' => $row['canonical_url'] ?? null,
                        'hreflang' => isset($row['hreflang']) && $row['hreflang'] !== null ? json_encode($row['hreflang']) : null,
                        'schema_json' => isset($row['schema_json']) && $row['schema_json'] !== null ? json_encode($row['schema_json']) : null,
                        'noindex' => (bool) ($row['noindex'] ?? false),
                        'meta_updated_at' => isset($row['meta_updated_at']) ? date('Y-m-d H:i:s', strtotime((string) $row['meta_updated_at'])) : null,
                        'updated_at' => now(),
                        'created_at' => now(),
                    ],
                );

                $ids[] = (int) $row['id'];
            }

            $afterId = $response['next_after_id'];
        } while ($afterId !== null);

        Cache::forever(self::CURSOR_KEY, $response['server_time']);

        return ['applied' => $ids, 'deleted' => $deletedIds];
    }

    /**
     * @return array{applied: list<int>, deleted: list<int>}
     */
    private function pullRedirects(?string $since): array
    {
        $ids = [];
        $deletedIds = [];
        $afterId = null;
        $hostAware = Schema::hasTable('metasync_domains');

        do {
            $response = $this->api->pullRedirects($since, $afterId);

            foreach ($response['data'] as $row) {
                if (! empty($row['deleted'])) {
                    DB::table('metasync_redirects')->where('remote_id', $row['id'])->delete();

                    $deletedIds[] = (int) $row['id'];

                    continue;
                }

                $host = is_string($row['host'] ?? null) ? $row['host'] : '';

                // MetaSync may hard-delete a trashed redirect and reissue the
                // path under a new id; clear the stale twin or the unique
                // index on (host, from_path) rejects the upsert.
                DB::table('metasync_redirects')
                    ->where('from_path', $row['from_path'])
                    ->when($hostAware, fn ($query) => $query->where('host', $host))
                    ->where('remote_id', '!=', $row['id'])
                    ->delete();

                DB::table('metasync_redirects')->updateOrInsert(
                    ['remote_id' => $row['id']],
                    array_filter([
                        'host' => $hostAware ? $host : null,
                        'from_path' => $row['from_path'],
                        'to_url' => $row['to_url'],
                        'status_code' => (int) ($row['status_code'] ?? 301),
                        'is_active' => (bool) ($row['is_active'] ?? true),
                        'updated_at' => now(),
                        'created_at' => now(),
                    ], fn ($value) => $value !== null),
                );

                $ids[] = (int) $row['id'];
            }

            $afterId = $response['next_after_id'];
        } while ($afterId !== null);

        return ['applied' => $ids, 'deleted' => $deletedIds];
    }
}

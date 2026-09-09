<?php

namespace MetaSyncClient;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Pulls pending meta and redirects from MetaSync into the local cache tables
 * and acknowledges what was applied. The local tables are what MetaResolver
 * and the redirects middleware read at request time — no runtime calls to
 * the SaaS.
 */
class SyncService
{
    private const string CURSOR_KEY = 'metasync:last_pull';

    public function __construct(private readonly ApiClient $api) {}

    /**
     * @return array{pages: int, redirects: int}
     */
    public function pull(bool $full = false): array
    {
        $since = $full ? null : Cache::get(self::CURSOR_KEY);

        $pageIds = $this->pullPages($since);
        $redirectIds = $this->pullRedirects($since);

        if ($pageIds !== [] || $redirectIds !== []) {
            $this->api->ack($pageIds, $redirectIds);
        }

        return ['pages' => count($pageIds), 'redirects' => count($redirectIds)];
    }

    /**
     * @return list<int>
     */
    private function pullPages(?string $since): array
    {
        $ids = [];
        $afterId = null;

        do {
            $response = $this->api->pullMeta($since, $afterId);

            foreach ($response['data'] as $row) {
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

        return $ids;
    }

    /**
     * @return list<int>
     */
    private function pullRedirects(?string $since): array
    {
        $ids = [];
        $afterId = null;

        do {
            $response = $this->api->pullRedirects($since, $afterId);

            foreach ($response['data'] as $row) {
                DB::table('metasync_redirects')->updateOrInsert(
                    ['remote_id' => $row['id']],
                    [
                        'from_path' => $row['from_path'],
                        'to_url' => $row['to_url'],
                        'status_code' => (int) ($row['status_code'] ?? 301),
                        'is_active' => (bool) ($row['is_active'] ?? true),
                        'updated_at' => now(),
                        'created_at' => now(),
                    ],
                );

                $ids[] = (int) $row['id'];
            }

            $afterId = $response['next_after_id'];
        } while ($afterId !== null);

        return $ids;
    }
}

<?php

namespace MetaSyncClient;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class ApiClient
{
    private function http(): PendingRequest
    {
        $token = (string) config('metasync-client.token');

        if ($token === '') {
            throw new RuntimeException('METASYNC_TOKEN is not configured.');
        }

        return Http::withToken($token)
            ->acceptJson()
            ->baseUrl(rtrim((string) config('metasync-client.url'), '/').'/api/v1')
            ->timeout(30);
    }

    /**
     * @return array<string, mixed>
     */
    public function projectInfo(): array
    {
        return $this->json($this->http()->get('project'));
    }

    /**
     * @param  list<array<string, mixed>>  $pages
     * @param  bool  $force  overwrite meta already edited in MetaSync with the site's values
     * @return array{new: int, updated: int, overwritten?: int, total: int, skipped: int}
     */
    public function pushPages(array $pages, bool $force = false): array
    {
        /** @var array{new: int, updated: int, overwritten?: int, total: int, skipped: int} */
        return $this->json($this->http()->post('pages/push', array_filter([
            'pages' => $pages,
            'force' => $force ?: null,
        ])));
    }

    /**
     * @param  list<array<string, mixed>>  $redirects
     * @return array{new: int, existing: int, total: int, skipped: int}
     */
    public function pushRedirects(array $redirects): array
    {
        /** @var array{new: int, existing: int, total: int, skipped: int} */
        return $this->json($this->http()->post('redirects/push', ['redirects' => $redirects]));
    }

    /**
     * @return array{data: list<array<string, mixed>>, next_after_id: int|null, server_time: string}
     */
    public function pullMeta(?string $updatedSince = null, ?int $afterId = null): array
    {
        /** @var array{data: list<array<string, mixed>>, next_after_id: int|null, server_time: string} */
        return $this->json($this->http()->get('meta/pull', array_filter([
            'updated_since' => $updatedSince,
            'after_id' => $afterId,
        ])));
    }

    /**
     * @return array{data: list<array<string, mixed>>, next_after_id: int|null, server_time: string}
     */
    public function pullRedirects(?string $updatedSince = null, ?int $afterId = null): array
    {
        /** @var array{data: list<array<string, mixed>>, next_after_id: int|null, server_time: string} */
        return $this->json($this->http()->get('redirects/pull', array_filter([
            'updated_since' => $updatedSince,
            'after_id' => $afterId,
        ])));
    }

    /**
     * @param  list<int>  $pageIds
     * @param  list<int>  $redirectIds
     * @param  list<array{message: string, url_path?: string}>  $errors
     */
    public function ack(array $pageIds, array $redirectIds, array $errors = []): void
    {
        $this->json($this->http()->post('sync/ack', [
            'applied_page_ids' => $pageIds,
            'applied_redirect_ids' => $redirectIds,
            'errors' => $errors,
        ]));
    }

    /**
     * @param  list<array{path: string, count?: int, referer?: string}>  $hits
     * @return array{accepted: int}
     */
    public function reportNotFound(array $hits): array
    {
        /** @var array{accepted: int} */
        return $this->json($this->http()->post('errors/404', ['hits' => $hits]));
    }

    /**
     * @return array{registered: bool, secret: string|null}
     */
    public function registerWebhook(?string $url): array
    {
        /** @var array{registered: bool, secret: string|null} */
        return $this->json($this->http()->post('webhook', ['url' => $url]));
    }

    /**
     * @return array<string, mixed>
     */
    private function json(Response $response): array
    {
        if ($response->failed()) {
            throw new RuntimeException('MetaSync API error '.$response->status().': '.$response->body());
        }

        return (array) $response->json();
    }
}

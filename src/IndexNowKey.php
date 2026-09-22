<?php

namespace MetaSyncClient;

use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * The IndexNow key MetaSync issued for this project. Search engines verify
 * ownership by fetching https://<site>/<key>.txt, which the package serves
 * from this value. Cached for an hour; a request for a key that does not
 * match the cache triggers one re-fetch per minute so a key regenerated in
 * MetaSync is picked up quickly without turning unknown paths into API calls.
 */
class IndexNowKey
{
    private const CACHE_KEY = 'metasync-client:indexnow_key';

    private const REFRESH_LOCK = 'metasync-client:indexnow_key:refreshed';

    public function __construct(private readonly ApiClient $api) {}

    /**
     * True when the site should serve $requested as its IndexNow key.
     */
    public function matches(string $requested): bool
    {
        if (hash_equals($this->current(), $requested)) {
            return true;
        }

        if (! Cache::add(self::REFRESH_LOCK, true, 60)) {
            return false;
        }

        Cache::forget(self::CACHE_KEY);

        return hash_equals($this->current(), $requested);
    }

    /**
     * The key MetaSync currently expects, empty while IndexNow is disabled
     * for the project or MetaSync cannot be reached.
     */
    public function current(): string
    {
        /** @var string */
        return Cache::remember(self::CACHE_KEY, 3600, function (): string {
            try {
                $info = $this->api->projectInfo();
            } catch (Throwable) {
                return '';
            }

            $key = is_array($info['data'] ?? null) ? ($info['data']['indexnow_key'] ?? null) : null;

            return is_string($key) ? $key : '';
        });
    }

    public function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}

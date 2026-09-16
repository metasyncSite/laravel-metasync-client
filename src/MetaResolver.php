<?php

namespace MetaSyncClient;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;

/**
 * Resolves synced meta for the current request from the local cache table.
 * Prefers the exact language and tolerates a trailing-slash mismatch
 * between the site's URLs and MetaSync.
 *
 * Usage in a controller or view composer:
 *
 *     $meta = app(MetaResolver::class)->forRequest();
 *     // $meta?->title, $meta?->description, $meta?->h1, $meta?->noindex …
 *
 * Or render <title>/description/robots in one go with @metasyncHead.
 */
class MetaResolver
{
    /** @var array<string, object|null> */
    private array $cache = [];

    public function forRequest(?string $lang = null): ?object
    {
        return $this->forPath(request()->getPathInfo(), $lang);
    }

    public function forPath(string $path, ?string $lang = null): ?object
    {
        $path = '/'.ltrim($path, '/');
        $lang ??= (string) config('metasync-client.default_lang');
        $key = $lang.':'.$path;

        if (! array_key_exists($key, $this->cache)) {
            $this->cache[$key] = $this->lookup($path, $lang);
        }

        return $this->cache[$key];
    }

    /**
     * <title>, description, robots, Open Graph, canonical, hreflang and
     * JSON-LD tags for the page. Renders nothing when the page has no
     * entry, so host defaults stay in place.
     */
    public function head(?string $path = null, ?string $lang = null): HtmlString
    {
        $meta = $path === null ? $this->forRequest($lang) : $this->forPath($path, $lang);

        if ($meta === null) {
            return new HtmlString('');
        }

        $tags = [];

        if (! empty($meta->title)) {
            $tags[] = '<title>'.e($meta->title).'</title>';
        }

        if (! empty($meta->description)) {
            $tags[] = '<meta name="description" content="'.e($meta->description).'">';
        }

        if (! empty($meta->noindex)) {
            $tags[] = '<meta name="robots" content="noindex, nofollow">';
        }

        if (! empty($meta->canonical_url)) {
            $tags[] = '<link rel="canonical" href="'.e($meta->canonical_url).'">';
        }

        if (! empty($meta->og_title)) {
            $tags[] = '<meta property="og:title" content="'.e($meta->og_title).'">';
        }

        if (! empty($meta->og_description)) {
            $tags[] = '<meta property="og:description" content="'.e($meta->og_description).'">';
        }

        if (! empty($meta->og_image)) {
            $tags[] = '<meta property="og:image" content="'.e($meta->og_image).'">';
        }

        foreach ($this->decodeJson($meta->hreflang ?? null) as $hreflang => $href) {
            if (is_string($hreflang) && is_string($href)) {
                $tags[] = '<link rel="alternate" hreflang="'.e($hreflang).'" href="'.e($href).'">';
            }
        }

        $schema = $this->decodeJson($meta->schema_json ?? null);

        if ($schema !== []) {
            $json = (string) json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            // Guard against markup breaking out of the script element.
            $json = str_ireplace('</script', '<\/script', $json);
            $tags[] = '<script type="application/ld+json">'.$json.'</script>';
        }

        return new HtmlString(implode("\n", $tags));
    }

    /**
     * @return array<array-key, mixed>
     */
    private function decodeJson(?string $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function lookup(string $path, string $lang): ?object
    {
        foreach ($this->pathVariants($path) as $variant) {
            $rows = DB::table('metasync_pages')
                ->where('url_hash', sha1($variant))
                ->orderBy('id')
                ->get();

            if ($rows->isEmpty()) {
                continue;
            }

            return $rows->firstWhere('lang', $lang) ?? $rows->first();
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function pathVariants(string $path): array
    {
        $alternate = $path === '/' ? null : (str_ends_with($path, '/') ? rtrim($path, '/') : $path.'/');

        return array_values(array_filter([$path, $alternate]));
    }
}

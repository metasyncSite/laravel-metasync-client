<?php

namespace MetaSyncClient\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use MetaSyncClient\SyncService;
use Symfony\Component\HttpFoundation\Response;

/**
 * Applies redirects synced from MetaSync before the application routes run
 * and buffers 404 responses locally so the next sync can report them.
 *
 * Requests arriving on an alias host (a drop domain pointed at this site)
 * get that host's own redirects first; anything else goes to the alias
 * fallback URL, or the same path on the site's domain, and the path is
 * buffered so MetaSync can offer a one-to-one redirect for it.
 */
class HandleRedirects
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->isMethod('GET') || ! Schema::hasTable('metasync_redirects')) {
            return $next($request);
        }

        $path = '/'.ltrim($request->getPathInfo(), '/');
        $alternate = $path === '/' ? null : (str_ends_with($path, '/') ? rtrim($path, '/') : $path.'/');
        $hostAware = Schema::hasTable('metasync_domains');
        $alias = $hostAware ? $this->alias($request) : null;

        $redirect = DB::table('metasync_redirects')
            ->whereIn('from_path', array_values(array_filter([$path, $alternate])))
            ->when($hostAware, fn ($query) => $query->where('host', $alias->host ?? ''))
            ->where('is_active', true)
            ->first();

        if ($redirect !== null) {
            return redirect()->away($redirect->to_url, (int) $redirect->status_code);
        }

        if ($alias !== null) {
            $this->recordNotFound($request, $path, $alias->host);

            return redirect()->away($this->fallbackFor($alias, $request), (int) $alias->status_code);
        }

        $response = $next($request);

        if ($response->getStatusCode() === 404) {
            $this->recordNotFound($request, $path);
        }

        return $response;
    }

    private function alias(Request $request): ?object
    {
        $host = strtolower((string) preg_replace('/^www\./i', '', $request->getHost()));

        if ($host === '') {
            return null;
        }

        return DB::table('metasync_domains')->where('host', $host)->where('is_active', true)->first();
    }

    private function fallbackFor(object $alias, Request $request): string
    {
        if (is_string($alias->fallback_url) && $alias->fallback_url !== '') {
            return $alias->fallback_url;
        }

        $domain = SyncService::projectDomain() ?? (string) parse_url((string) config('app.url'), PHP_URL_HOST);
        $query = $request->getQueryString();

        return 'https://'.$domain.$request->getPathInfo().($query !== null && $query !== '' ? '?'.$query : '');
    }

    private function recordNotFound(Request $request, string $path, string $host = ''): void
    {
        if (! config('metasync-client.report_404', true) || ! Schema::hasTable('metasync_not_found')) {
            return;
        }

        $hostAware = Schema::hasTable('metasync_domains');

        if ($host !== '' && ! $hostAware) {
            return;
        }

        try {
            $updated = DB::table('metasync_not_found')
                ->where('path', $path)
                ->when($hostAware, fn ($query) => $query->where('host', $host))
                ->update(['hits' => DB::raw('hits + 1'), 'updated_at' => now()]);

            if ($updated === 0) {
                DB::table('metasync_not_found')->insert(array_filter([
                    'host' => $hostAware ? $host : null,
                    'path' => mb_substr($path, 0, 500),
                    'hits' => 1,
                    'referer' => mb_substr((string) $request->headers->get('referer', ''), 0, 1000) ?: null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ], fn ($value) => $value !== null));
            }
        } catch (\Throwable) {
            // A race on the unique path key or a read-only connection must
            // never break the 404 response itself.
        }
    }
}

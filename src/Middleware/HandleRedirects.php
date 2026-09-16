<?php

namespace MetaSyncClient\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;

/**
 * Applies redirects synced from MetaSync before the application routes run
 * and buffers 404 responses locally so the next sync can report them.
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

        $redirect = DB::table('metasync_redirects')
            ->whereIn('from_path', array_values(array_filter([$path, $alternate])))
            ->where('is_active', true)
            ->first();

        if ($redirect !== null) {
            return redirect()->away($redirect->to_url, (int) $redirect->status_code);
        }

        $response = $next($request);

        if ($response->getStatusCode() === 404) {
            $this->recordNotFound($request, $path);
        }

        return $response;
    }

    private function recordNotFound(Request $request, string $path): void
    {
        if (! config('metasync-client.report_404', true) || ! Schema::hasTable('metasync_not_found')) {
            return;
        }

        try {
            $updated = DB::table('metasync_not_found')
                ->where('path', $path)
                ->update(['hits' => DB::raw('hits + 1'), 'updated_at' => now()]);

            if ($updated === 0) {
                DB::table('metasync_not_found')->insert([
                    'path' => mb_substr($path, 0, 500),
                    'hits' => 1,
                    'referer' => mb_substr((string) $request->headers->get('referer', ''), 0, 1000) ?: null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        } catch (\Throwable) {
            // A race on the unique path key or a read-only connection must
            // never break the 404 response itself.
        }
    }
}

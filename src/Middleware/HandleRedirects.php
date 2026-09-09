<?php

namespace MetaSyncClient\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;

/**
 * Applies redirects synced from MetaSync before the application routes run.
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

        return $next($request);
    }
}

<?php

namespace MetaSyncClient\Tests;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class RedirectMiddlewareTest extends TestCase
{
    protected function defineRoutes($router): void
    {
        $router->get('/{any}', fn (): string => 'page')->where('any', '.*');
    }

    private function makeRedirect(string $fromPath, bool $active = true, int $status = 301, string $host = ''): void
    {
        DB::table('metasync_redirects')->insert([
            'remote_id' => random_int(1, 100000),
            'host' => $host,
            'from_path' => $fromPath,
            'to_url' => 'https://site.test/new',
            'status_code' => $status,
            'is_active' => $active,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_active_redirect_is_applied_by_the_global_middleware(): void
    {
        $this->makeRedirect('/old-page');

        $this->get('/old-page')
            ->assertStatus(301)
            ->assertRedirect('https://site.test/new');
    }

    public function test_trailing_slash_variant_matches(): void
    {
        $this->makeRedirect('/old-page');

        $this->get('/old-page/')->assertStatus(301);
    }

    public function test_inactive_redirect_is_ignored(): void
    {
        $this->makeRedirect('/old-page', active: false);

        $this->get('/old-page')->assertOk()->assertSee('page');
    }

    public function test_302_status_is_respected(): void
    {
        $this->makeRedirect('/temp', status: 302);

        $this->get('/temp')->assertStatus(302);
    }

    private function makeAlias(string $host, ?string $fallback = null, int $status = 301, bool $active = true): void
    {
        DB::table('metasync_domains')->insert([
            'host' => $host,
            'fallback_url' => $fallback,
            'status_code' => $status,
            'is_active' => $active,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_alias_host_uses_its_own_redirects_first(): void
    {
        $this->makeAlias('old-brand.test', 'https://site.test/welcome');
        $this->makeRedirect('/old-page', host: 'old-brand.test');
        $this->makeRedirect('/main-only');

        $this->get('https://www.old-brand.test/old-page')->assertStatus(301)->assertRedirect('https://site.test/new');

        // A main-domain rule does not leak onto the alias: it falls back.
        $this->get('https://old-brand.test/main-only')->assertStatus(301)->assertRedirect('https://site.test/welcome');

        // And an alias rule does not fire on the main domain.
        $this->get('https://site.test/old-page')->assertOk()->assertSee('page');
    }

    public function test_alias_without_fallback_keeps_the_path_on_the_project_domain(): void
    {
        Cache::forever('metasync:project_domain', 'site.test');
        $this->makeAlias('old-brand.test', status: 302);

        $this->get('https://old-brand.test/blog/post?x=1')
            ->assertStatus(302)
            ->assertRedirect('https://site.test/blog/post?x=1');
    }

    public function test_unmatched_alias_paths_are_buffered_with_the_host(): void
    {
        $this->makeAlias('old-brand.test', 'https://site.test/');

        $this->get('https://old-brand.test/gone', ['Referer' => 'https://ref.test/'])->assertStatus(301);
        $this->get('https://old-brand.test/gone')->assertStatus(301);

        $row = DB::table('metasync_not_found')->where('host', 'old-brand.test')->where('path', '/gone')->first();

        $this->assertNotNull($row);
        $this->assertSame(2, (int) $row->hits);
        $this->assertSame('https://ref.test/', $row->referer);
        $this->assertSame(0, DB::table('metasync_not_found')->where('host', '')->count());
    }

    public function test_inactive_alias_is_treated_as_the_main_site(): void
    {
        $this->makeAlias('old-brand.test', 'https://site.test/', active: false);
        $this->makeRedirect('/old-page');

        $this->get('https://old-brand.test/old-page')->assertStatus(301)->assertRedirect('https://site.test/new');
        $this->get('https://old-brand.test/anything')->assertOk()->assertSee('page');
    }
}

<?php

namespace MetaSyncClient\Tests;

use Illuminate\Support\Facades\DB;

class RedirectMiddlewareTest extends TestCase
{
    protected function defineRoutes($router): void
    {
        $router->get('/{any}', fn (): string => 'page')->where('any', '.*');
    }

    private function makeRedirect(string $fromPath, bool $active = true, int $status = 301): void
    {
        DB::table('metasync_redirects')->insert([
            'remote_id' => random_int(1, 100000),
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
}

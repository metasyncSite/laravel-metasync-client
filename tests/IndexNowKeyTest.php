<?php

namespace MetaSyncClient\Tests;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class IndexNowKeyTest extends TestCase
{
    private const KEY = 'abcdef0123456789abcdef0123456789';

    private const OTHER = '0123456789abcdef0123456789abcdef';

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    public function test_serves_the_key_metasync_issued(): void
    {
        Http::fake(['metasync.test/api/v1/project' => Http::response(['data' => ['indexnow_key' => self::KEY]])]);

        $this->get('/'.self::KEY.'.txt')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
            ->assertSee(self::KEY, false);

        Http::assertSentCount(1);
    }

    public function test_unknown_keys_are_not_found_and_do_not_hammer_the_api(): void
    {
        Http::fake(['metasync.test/api/v1/project' => Http::response(['data' => ['indexnow_key' => self::KEY]])]);

        $this->get('/'.self::OTHER.'.txt')->assertNotFound();
        $this->get('/'.self::OTHER.'.txt')->assertNotFound();
        $this->get('/'.self::OTHER.'.txt')->assertNotFound();

        // First hit fills the cache, the mismatch triggers one re-fetch, the
        // rest wait for the refresh window to pass.
        Http::assertSentCount(2);
    }

    public function test_a_regenerated_key_is_picked_up_on_mismatch(): void
    {
        Http::fake(['metasync.test/api/v1/project' => Http::sequence()
            ->push(['data' => ['indexnow_key' => self::KEY]])
            ->push(['data' => ['indexnow_key' => self::OTHER]]),
        ]);

        $this->get('/'.self::KEY.'.txt')->assertOk();
        $this->get('/'.self::OTHER.'.txt')->assertOk()->assertSee(self::OTHER, false);
    }

    public function test_disabled_indexnow_serves_nothing(): void
    {
        Http::fake(['metasync.test/api/v1/project' => Http::response(['data' => ['indexnow_key' => null]])]);

        $this->get('/'.self::KEY.'.txt')->assertNotFound();
    }

    public function test_route_ignores_paths_that_are_not_metasync_keys(): void
    {
        Http::fake();

        $this->get('/robots.txt')->assertNotFound();
        $this->get('/security.txt')->assertNotFound();

        Http::assertNothingSent();
    }
}

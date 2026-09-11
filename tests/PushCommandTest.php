<?php

namespace MetaSyncClient\Tests;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use MetaSyncClient\Contracts\PageCollector;
use MetaSyncClient\Contracts\RedirectCollector;

class PushCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->bind(PageCollector::class, fn () => new class implements PageCollector
        {
            public function collect(): iterable
            {
                yield ['url_path' => '/about', 'lang' => 'uk', 'title' => 'About'];
            }
        });
    }

    public function test_push_sends_pages_only_without_redirect_collector(): void
    {
        Http::fake([
            'https://metasync.test/api/v1/pages/push' => Http::response(['new' => 1, 'updated' => 0, 'total' => 1, 'skipped' => 0]),
        ]);

        $this->artisan('metasync:push')
            ->expectsOutputToContain('1 pages pushed')
            ->assertSuccessful();

        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'redirects/push'));
    }

    public function test_push_sends_redirects_when_collector_is_bound(): void
    {
        $this->app->bind(RedirectCollector::class, fn () => new class implements RedirectCollector
        {
            public function collect(): iterable
            {
                yield ['from_path' => '/old', 'to_url' => '/new', 'status_code' => 301];
                yield ['from_path' => '/temp', 'to_url' => 'https://site.test/final', 'status_code' => 302, 'is_active' => false];
            }
        });

        Http::fake([
            'https://metasync.test/api/v1/pages/push' => Http::response(['new' => 1, 'updated' => 0, 'total' => 1, 'skipped' => 0]),
            'https://metasync.test/api/v1/redirects/push' => Http::response(['new' => 2, 'existing' => 0, 'total' => 2, 'skipped' => 0]),
        ]);

        $this->artisan('metasync:push')
            ->expectsOutputToContain('2 redirects pushed — 2 new, 0 already known.')
            ->assertSuccessful();

        Http::assertSent(function (Request $request): bool {
            return str_contains($request->url(), 'redirects/push')
                && $request['redirects'][0]['from_path'] === '/old'
                && $request['redirects'][1]['status_code'] === 302;
        });
    }

    public function test_dry_run_lists_redirects_without_sending(): void
    {
        $this->app->bind(RedirectCollector::class, fn () => new class implements RedirectCollector
        {
            public function collect(): iterable
            {
                yield ['from_path' => '/old', 'to_url' => '/new'];
            }
        });

        Http::fake();

        $this->artisan('metasync:push --dry-run')
            ->expectsOutputToContain('/old → /new [301]')
            ->expectsOutputToContain('1 pages and 1 redirects would be pushed (dry run).')
            ->assertSuccessful();

        Http::assertNothingSent();
    }
}

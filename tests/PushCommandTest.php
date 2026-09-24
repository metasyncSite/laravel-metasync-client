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

    public function test_push_does_not_send_force_flag_by_default(): void
    {
        Http::fake([
            'https://metasync.test/api/v1/pages/push' => Http::response(['new' => 0, 'updated' => 1, 'total' => 1, 'skipped' => 0]),
        ]);

        $this->artisan('metasync:push')
            ->doesntExpectOutputToContain('force push')
            ->assertSuccessful();

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'pages/push')
            && ! array_key_exists('force', $request->data()));
    }

    public function test_force_push_sends_flag_and_reports_overwrites(): void
    {
        Http::fake([
            'https://metasync.test/api/v1/pages/push' => Http::response(['new' => 0, 'updated' => 1, 'overwritten' => 1, 'total' => 1, 'skipped' => 0]),
        ]);

        $this->artisan('metasync:push --force')
            ->expectsOutputToContain('1 pages pushed')
            ->expectsOutputToContain("1 pages edited in MetaSync were overwritten with the site's values (force push).")
            ->assertSuccessful();

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'pages/push')
            && $request['force'] === true
            && $request['pages'][0]['url_path'] === '/about');
    }

    public function test_force_push_warns_when_server_does_not_support_it(): void
    {
        Http::fake([
            'https://metasync.test/api/v1/pages/push' => Http::response(['new' => 0, 'updated' => 1, 'total' => 1, 'skipped' => 0]),
        ]);

        $this->artisan('metasync:push --force')
            ->expectsOutputToContain('The MetaSync server ignored --force')
            ->doesntExpectOutputToContain('force push).')
            ->assertSuccessful();
    }

    public function test_sync_passes_force_to_push(): void
    {
        Http::fake([
            'https://metasync.test/api/v1/pages/push' => Http::response(['new' => 0, 'updated' => 1, 'overwritten' => 0, 'total' => 1, 'skipped' => 0]),
            'https://metasync.test/api/v1/*' => Http::response(['data' => [], 'next_after_id' => null, 'server_time' => now()->toIso8601String()]),
        ]);

        $this->artisan('metasync:sync --force')->assertSuccessful();

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'pages/push') && $request['force'] === true);
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

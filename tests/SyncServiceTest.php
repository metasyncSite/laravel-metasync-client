<?php

namespace MetaSyncClient\Tests;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use MetaSyncClient\Events\PullCompleted;
use MetaSyncClient\SyncService;
use RuntimeException;

class SyncServiceTest extends TestCase
{
    public function test_pull_stores_meta_and_redirects_and_acknowledges(): void
    {
        Http::fake([
            'https://metasync.test/api/v1/meta/pull*' => Http::response([
                'data' => [
                    ['id' => 11, 'url_path' => '/about', 'lang' => 'uk', 'title' => 'Про нас', 'description' => 'Опис', 'h1' => 'H1', 'noindex' => false, 'meta_updated_at' => '2026-09-09T10:00:00+00:00'],
                    ['id' => 12, 'url_path' => '/contacts', 'lang' => 'uk', 'title' => 'Контакти', 'noindex' => true],
                ],
                'next_after_id' => null,
                'server_time' => '2026-09-09T10:05:00+00:00',
            ]),
            'https://metasync.test/api/v1/redirects/pull*' => Http::response([
                'data' => [
                    ['id' => 5, 'from_path' => '/old', 'to_url' => 'https://site.test/new', 'status_code' => 301, 'is_active' => true],
                ],
                'next_after_id' => null,
                'server_time' => '2026-09-09T10:05:00+00:00',
            ]),
            'https://metasync.test/api/v1/sync/ack' => Http::response(['ok' => true]),
        ]);

        $result = app(SyncService::class)->pull();

        $this->assertSame(['pages' => 2, 'redirects' => 1], $result);

        $this->assertSame(2, DB::table('metasync_pages')->count());
        $this->assertSame('Про нас', DB::table('metasync_pages')->where('url_hash', sha1('/about'))->value('title'));
        $this->assertSame(1, DB::table('metasync_redirects')->where('from_path', '/old')->count());

        $this->assertSame('2026-09-09T10:05:00+00:00', Cache::get('metasync:last_pull'));

        Http::assertSent(function (Request $request): bool {
            return str_contains($request->url(), '/api/v1/sync/ack')
                && $request['applied_page_ids'] === [11, 12]
                && $request['applied_redirect_ids'] === [5];
        });
    }

    public function test_pull_fires_event_with_applied_ids(): void
    {
        Event::fake([PullCompleted::class]);

        Http::fake([
            'https://metasync.test/api/v1/meta/pull*' => Http::response([
                'data' => [['id' => 11, 'url_path' => '/about', 'lang' => 'uk', 'title' => 'Про нас']],
                'next_after_id' => null,
                'server_time' => '2026-09-09T10:05:00+00:00',
            ]),
            'https://metasync.test/api/v1/redirects/pull*' => Http::response([
                'data' => [['id' => 5, 'from_path' => '/old', 'to_url' => 'https://site.test/new']],
                'next_after_id' => null,
                'server_time' => '2026-09-09T10:05:00+00:00',
            ]),
            'https://metasync.test/api/v1/sync/ack' => Http::response(['ok' => true]),
        ]);

        app(SyncService::class)->pull();

        Event::assertDispatched(PullCompleted::class, function (PullCompleted $event): bool {
            return $event->pageIds === [11] && $event->redirectIds === [5];
        });
    }

    public function test_empty_pull_does_not_fire_event(): void
    {
        Event::fake([PullCompleted::class]);

        Http::fake([
            'https://metasync.test/api/v1/*' => Http::response([
                'data' => [], 'next_after_id' => null, 'server_time' => '2026-09-09T12:00:00+00:00',
            ]),
        ]);

        app(SyncService::class)->pull();

        Event::assertNotDispatched(PullCompleted::class);
    }

    public function test_repeated_pull_updates_existing_rows(): void
    {
        DB::table('metasync_pages')->insert([
            'remote_id' => 11,
            'url_path' => '/about',
            'url_hash' => sha1('/about'),
            'lang' => 'uk',
            'title' => 'Стара назва',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Http::fake([
            'https://metasync.test/api/v1/meta/pull*' => Http::response([
                'data' => [['id' => 11, 'url_path' => '/about', 'lang' => 'uk', 'title' => 'Нова назва']],
                'next_after_id' => null,
                'server_time' => '2026-09-09T11:00:00+00:00',
            ]),
            'https://metasync.test/api/v1/redirects/pull*' => Http::response([
                'data' => [], 'next_after_id' => null, 'server_time' => '2026-09-09T11:00:00+00:00',
            ]),
            'https://metasync.test/api/v1/sync/ack' => Http::response(['ok' => true]),
        ]);

        app(SyncService::class)->pull();

        $this->assertSame(1, DB::table('metasync_pages')->count());
        $this->assertSame('Нова назва', DB::table('metasync_pages')->where('remote_id', 11)->value('title'));
    }

    public function test_incremental_pull_sends_saved_cursor(): void
    {
        Cache::forever('metasync:last_pull', '2026-09-09T10:05:00+00:00');

        Http::fake([
            'https://metasync.test/api/v1/*' => Http::response([
                'data' => [], 'next_after_id' => null, 'server_time' => '2026-09-09T12:00:00+00:00',
            ]),
        ]);

        app(SyncService::class)->pull();

        Http::assertSent(function (Request $request): bool {
            return str_contains($request->url(), 'meta/pull')
                && str_contains($request->url(), urlencode('2026-09-09T10:05:00+00:00'));
        });
    }

    public function test_pull_without_token_throws(): void
    {
        config()->set('metasync-client.token', null);

        Http::fake();

        $this->expectException(RuntimeException::class);

        app(SyncService::class)->pull();
    }
}

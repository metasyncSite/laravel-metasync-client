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

    public function test_pull_removes_rows_flagged_deleted(): void
    {
        DB::table('metasync_pages')->insert([
            'remote_id' => 11,
            'url_path' => '/about',
            'url_hash' => sha1('/about'),
            'lang' => 'uk',
            'title' => 'Про нас',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('metasync_redirects')->insert([
            'remote_id' => 5,
            'from_path' => '/old',
            'to_url' => 'https://site.test/new',
            'status_code' => 301,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Http::fake([
            'https://metasync.test/api/v1/meta/pull*' => Http::response([
                'data' => [['id' => 11, 'url_path' => '/about', 'lang' => 'uk', 'deleted' => true]],
                'next_after_id' => null,
                'server_time' => '2026-09-09T10:05:00+00:00',
            ]),
            'https://metasync.test/api/v1/redirects/pull*' => Http::response([
                'data' => [['id' => 5, 'from_path' => '/old', 'to_url' => 'https://site.test/new', 'deleted' => true]],
                'next_after_id' => null,
                'server_time' => '2026-09-09T10:05:00+00:00',
            ]),
            'https://metasync.test/api/v1/sync/ack' => Http::response(['ok' => true]),
        ]);

        Event::fake([PullCompleted::class]);

        app(SyncService::class)->pull();

        $this->assertSame(0, DB::table('metasync_pages')->count());
        $this->assertSame(0, DB::table('metasync_redirects')->count());

        // Deletions are acknowledged so MetaSync stops re-sending them.
        Http::assertSent(function (Request $request): bool {
            return str_contains($request->url(), '/api/v1/sync/ack')
                && $request['applied_page_ids'] === [11]
                && $request['applied_redirect_ids'] === [5];
        });

        Event::assertDispatched(PullCompleted::class, function (PullCompleted $event): bool {
            return $event->pageIds === []
                && $event->redirectIds === []
                && $event->deletedPageIds === [11]
                && $event->deletedRedirectIds === [5];
        });
    }

    public function test_pull_replaces_redirect_reissued_under_new_remote_id(): void
    {
        DB::table('metasync_redirects')->insert([
            'remote_id' => 5,
            'from_path' => '/old',
            'to_url' => 'https://site.test/stale',
            'status_code' => 301,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Http::fake([
            'https://metasync.test/api/v1/meta/pull*' => Http::response([
                'data' => [], 'next_after_id' => null, 'server_time' => '2026-09-09T10:05:00+00:00',
            ]),
            'https://metasync.test/api/v1/redirects/pull*' => Http::response([
                'data' => [['id' => 9, 'from_path' => '/old', 'to_url' => 'https://site.test/fresh', 'status_code' => 301, 'is_active' => true]],
                'next_after_id' => null,
                'server_time' => '2026-09-09T10:05:00+00:00',
            ]),
            'https://metasync.test/api/v1/sync/ack' => Http::response(['ok' => true]),
        ]);

        app(SyncService::class)->pull();

        $rows = DB::table('metasync_redirects')->get();

        $this->assertCount(1, $rows);
        $this->assertSame(9, (int) $rows[0]->remote_id);
        $this->assertSame('https://site.test/fresh', $rows[0]->to_url);
    }

    public function test_full_pull_drops_rows_missing_on_the_server(): void
    {
        DB::table('metasync_pages')->insert([
            'remote_id' => 99,
            'url_path' => '/ghost',
            'url_hash' => sha1('/ghost'),
            'lang' => 'uk',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('metasync_redirects')->insert([
            'remote_id' => 88,
            'from_path' => '/ghost-redirect',
            'to_url' => 'https://site.test/x',
            'status_code' => 301,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Http::fake([
            'https://metasync.test/api/v1/meta/pull*' => Http::response([
                'data' => [['id' => 11, 'url_path' => '/about', 'lang' => 'uk', 'title' => 'Про нас']],
                'next_after_id' => null,
                'server_time' => '2026-09-09T10:05:00+00:00',
            ]),
            'https://metasync.test/api/v1/redirects/pull*' => Http::response([
                'data' => [], 'next_after_id' => null, 'server_time' => '2026-09-09T10:05:00+00:00',
            ]),
            'https://metasync.test/api/v1/sync/ack' => Http::response(['ok' => true]),
        ]);

        app(SyncService::class)->pull(full: true);

        $this->assertSame([11], DB::table('metasync_pages')->pluck('remote_id')->map(fn ($id) => (int) $id)->all());
        $this->assertSame(0, DB::table('metasync_redirects')->count());
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

    public function test_pull_stores_extended_meta(): void
    {
        Http::fake([
            'https://metasync.test/api/v1/meta/pull*' => Http::response([
                'data' => [[
                    'id' => 11,
                    'url_path' => '/about',
                    'lang' => 'uk',
                    'title' => 'Про нас',
                    'og_title' => 'OG назва',
                    'og_image' => 'https://site.test/og.jpg',
                    'canonical_url' => 'https://site.test/about',
                    'hreflang' => ['uk' => 'https://site.test/about'],
                    'schema_json' => ['@type' => 'WebPage'],
                ]],
                'next_after_id' => null,
                'server_time' => '2026-09-12T10:05:00+00:00',
            ]),
            'https://metasync.test/api/v1/redirects/pull*' => Http::response([
                'data' => [], 'next_after_id' => null, 'server_time' => '2026-09-12T10:05:00+00:00',
            ]),
            'https://metasync.test/api/v1/sync/ack' => Http::response(['ok' => true]),
        ]);

        app(SyncService::class)->pull();

        $row = DB::table('metasync_pages')->where('remote_id', 11)->first();

        $this->assertSame('OG назва', $row->og_title);
        $this->assertSame('https://site.test/about', $row->canonical_url);
        $this->assertSame(['uk' => 'https://site.test/about'], json_decode((string) $row->hreflang, true));
        $this->assertSame(['@type' => 'WebPage'], json_decode((string) $row->schema_json, true));
    }

    public function test_pull_reports_buffered_404_hits_and_clears_them(): void
    {
        DB::table('metasync_not_found')->insert([
            ['path' => '/gone', 'hits' => 3, 'referer' => 'https://ref.test', 'created_at' => now(), 'updated_at' => now()],
            ['path' => '/missing', 'hits' => 1, 'referer' => null, 'created_at' => now(), 'updated_at' => now()],
        ]);

        Http::fake([
            'https://metasync.test/api/v1/meta/pull*' => Http::response([
                'data' => [], 'next_after_id' => null, 'server_time' => '2026-09-12T10:05:00+00:00',
            ]),
            'https://metasync.test/api/v1/redirects/pull*' => Http::response([
                'data' => [], 'next_after_id' => null, 'server_time' => '2026-09-12T10:05:00+00:00',
            ]),
            'https://metasync.test/api/v1/errors/404' => Http::response(['accepted' => 2]),
        ]);

        app(SyncService::class)->pull();

        Http::assertSent(function (Request $request): bool {
            return str_contains($request->url(), '/api/v1/errors/404')
                && $request['hits'][0]['path'] === '/gone'
                && $request['hits'][0]['count'] === 3
                && $request['hits'][0]['referer'] === 'https://ref.test'
                && $request['hits'][1] === ['path' => '/missing', 'count' => 1];
        });

        $this->assertSame(0, DB::table('metasync_not_found')->count());
    }

    public function test_404_reporting_can_be_disabled(): void
    {
        config()->set('metasync-client.report_404', false);

        DB::table('metasync_not_found')->insert([
            'path' => '/gone', 'hits' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);

        Http::fake([
            'https://metasync.test/api/v1/*' => Http::response([
                'data' => [], 'next_after_id' => null, 'server_time' => '2026-09-12T10:05:00+00:00',
            ]),
        ]);

        app(SyncService::class)->pull();

        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'errors/404'));
        $this->assertSame(1, DB::table('metasync_not_found')->count());
    }
}

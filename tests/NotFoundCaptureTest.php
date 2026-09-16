<?php

namespace MetaSyncClient\Tests;

use Illuminate\Support\Facades\DB;

class NotFoundCaptureTest extends TestCase
{
    // No catch-all route here — unknown paths respond 404 through the
    // global HandleRedirects middleware.

    public function test_404_responses_are_buffered_locally(): void
    {
        $this->get('/nowhere', ['Referer' => 'https://ref.test/page'])->assertNotFound();
        $this->get('/nowhere')->assertNotFound();

        $row = DB::table('metasync_not_found')->where('path', '/nowhere')->first();

        $this->assertNotNull($row);
        $this->assertSame(2, (int) $row->hits);
        $this->assertSame('https://ref.test/page', $row->referer);
    }

    public function test_capture_respects_the_config_flag(): void
    {
        config()->set('metasync-client.report_404', false);

        $this->get('/nowhere')->assertNotFound();

        $this->assertSame(0, DB::table('metasync_not_found')->count());
    }

    public function test_non_get_requests_are_ignored(): void
    {
        $this->post('/nowhere')->assertNotFound();

        $this->assertSame(0, DB::table('metasync_not_found')->count());
    }
}

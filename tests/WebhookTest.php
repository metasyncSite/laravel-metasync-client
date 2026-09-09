<?php

namespace MetaSyncClient\Tests;

use Illuminate\Support\Facades\Bus;
use MetaSyncClient\Jobs\PullMetaJob;

class WebhookTest extends TestCase
{
    public function test_valid_signature_queues_a_pull(): void
    {
        Bus::fake();

        config()->set('metasync-client.webhook_secret', 'secret-key');

        $body = (string) json_encode(['event' => 'meta.updated', 'project_id' => 1, 'timestamp' => '2026-09-09T10:00:00+00:00']);

        $this->call('POST', '/metasync/webhook', server: [
            'HTTP_X-MetaSync-Signature' => hash_hmac('sha256', $body, 'secret-key'),
            'CONTENT_TYPE' => 'application/json',
        ], content: $body)
            ->assertOk()
            ->assertJson(['queued' => true]);

        Bus::assertDispatched(PullMetaJob::class);
    }

    public function test_invalid_signature_is_rejected(): void
    {
        Bus::fake();

        config()->set('metasync-client.webhook_secret', 'secret-key');

        $this->call('POST', '/metasync/webhook', server: [
            'HTTP_X-MetaSync-Signature' => 'wrong',
            'CONTENT_TYPE' => 'application/json',
        ], content: '{}')
            ->assertForbidden();

        Bus::assertNothingDispatched();
    }

    public function test_unconfigured_webhook_is_rejected(): void
    {
        Bus::fake();

        $this->post('/metasync/webhook')->assertForbidden();

        Bus::assertNothingDispatched();
    }
}

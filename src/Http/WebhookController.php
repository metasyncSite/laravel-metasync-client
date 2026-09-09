<?php

namespace MetaSyncClient\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use MetaSyncClient\Jobs\PullMetaJob;

class WebhookController
{
    /**
     * Receives signed pings from MetaSync and schedules a pull so changes
     * land on the site automatically.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $secret = (string) config('metasync-client.webhook_secret');

        abort_if($secret === '', 403, 'Webhook secret is not configured.');

        $signature = (string) $request->header('X-MetaSync-Signature');

        abort_unless(
            $signature !== '' && hash_equals(hash_hmac('sha256', (string) $request->getContent(), $secret), $signature),
            403,
            'Invalid signature.',
        );

        PullMetaJob::dispatch();

        return response()->json(['queued' => true]);
    }
}

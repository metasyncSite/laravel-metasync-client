<?php

namespace MetaSyncClient\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use MetaSyncClient\SyncService;

class PullMetaJob implements ShouldQueue
{
    use Queueable;

    public function handle(SyncService $sync): void
    {
        $sync->pull();
    }
}

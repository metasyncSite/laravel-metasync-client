<?php

namespace MetaSyncClient\Commands;

use Illuminate\Console\Command;
use MetaSyncClient\SyncService;

class PullCommand extends Command
{
    protected $signature = 'metasync:pull {--full : Ignore the saved cursor and pull everything}';

    protected $description = 'Pull pending meta and redirects from MetaSync into the local cache tables';

    public function handle(SyncService $sync): int
    {
        $result = $sync->pull(full: (bool) $this->option('full'));

        $this->info("Pulled {$result['pages']} pages and {$result['redirects']} redirects.");

        return self::SUCCESS;
    }
}

<?php

namespace MetaSyncClient\Commands;

use Illuminate\Console\Command;

class SyncCommand extends Command
{
    protected $signature = 'metasync:sync';

    protected $description = 'Full sync: push the site\'s pages, then pull pending changes from MetaSync';

    public function handle(): int
    {
        $push = $this->call('metasync:push');

        if ($push !== self::SUCCESS) {
            return $push;
        }

        return $this->call('metasync:pull');
    }
}

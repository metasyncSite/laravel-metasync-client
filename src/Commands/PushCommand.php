<?php

namespace MetaSyncClient\Commands;

use Illuminate\Console\Command;
use MetaSyncClient\ApiClient;
use MetaSyncClient\Contracts\PageCollector;

class PushCommand extends Command
{
    protected $signature = 'metasync:push {--dry-run : List the pages without sending them}';

    protected $description = 'Push the site\'s pages to MetaSync';

    public function handle(ApiClient $api): int
    {
        if (! $this->laravel->bound(PageCollector::class)) {
            $this->error('No '.PageCollector::class.' implementation is bound.');
            $this->line('Bind one in a service provider, e.g.:');
            $this->line('  $this->app->bind(PageCollector::class, AppPageCollector::class);');

            return self::FAILURE;
        }

        /** @var PageCollector $collector */
        $collector = $this->laravel->make(PageCollector::class);

        $pages = [];

        foreach ($collector->collect() as $page) {
            $pages[] = $page;
        }

        if ($pages === []) {
            $this->info('Nothing to push: the collector returned no pages.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            foreach ($pages as $page) {
                $this->line(($page['url_path'] ?? '?').' ['.($page['lang'] ?? 'uk').'] '.($page['title'] ?? ''));
            }

            $this->info(count($pages).' pages would be pushed (dry run).');

            return self::SUCCESS;
        }

        $total = ['new' => 0, 'updated' => 0, 'skipped' => 0];

        foreach (array_chunk($pages, 500) as $chunk) {
            $result = $api->pushPages($chunk);

            $total['new'] += $result['new'];
            $total['updated'] += $result['updated'];
            $total['skipped'] += $result['skipped'] ?? 0;
        }

        $this->info(count($pages)." pages pushed — {$total['new']} new, {$total['updated']} updated.");

        if ($total['skipped'] > 0) {
            $this->warn("{$total['skipped']} pages were skipped: the MetaSync plan page limit is reached.");
        }

        return self::SUCCESS;
    }
}

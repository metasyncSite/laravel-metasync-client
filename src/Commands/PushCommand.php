<?php

namespace MetaSyncClient\Commands;

use Illuminate\Console\Command;
use MetaSyncClient\ApiClient;
use MetaSyncClient\Contracts\PageCollector;
use MetaSyncClient\Contracts\RedirectCollector;

class PushCommand extends Command
{
    protected $signature = 'metasync:push
        {--dry-run : List the pages without sending them}
        {--force : Overwrite meta already edited in MetaSync with the site\'s current values}';

    protected $description = 'Push the site\'s pages and redirects to MetaSync';

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

        $redirects = [];

        if ($this->laravel->bound(RedirectCollector::class)) {
            /** @var RedirectCollector $redirectCollector */
            $redirectCollector = $this->laravel->make(RedirectCollector::class);

            foreach ($redirectCollector->collect() as $redirect) {
                $redirects[] = $redirect;
            }
        }

        if ($pages === [] && $redirects === []) {
            $this->info('Nothing to push: the collectors returned no pages or redirects.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            foreach ($pages as $page) {
                $this->line(($page['url_path'] ?? '?').' ['.($page['lang'] ?? 'uk').'] '.($page['title'] ?? ''));
            }

            foreach ($redirects as $redirect) {
                $this->line(($redirect['from_path'] ?? '?').' → '.($redirect['to_url'] ?? '?').' ['.($redirect['status_code'] ?? 301).']');
            }

            $this->info(count($pages).' pages and '.count($redirects).' redirects would be pushed (dry run).');

            return self::SUCCESS;
        }

        if ($pages !== []) {
            $force = (bool) $this->option('force');
            $total = ['new' => 0, 'updated' => 0, 'overwritten' => 0, 'skipped' => 0];

            foreach (array_chunk($pages, 500) as $chunk) {
                $result = $api->pushPages($chunk, $force);

                $total['new'] += $result['new'];
                $total['updated'] += $result['updated'];
                $total['overwritten'] += $result['overwritten'] ?? 0;
                $total['skipped'] += $result['skipped'] ?? 0;
            }

            $this->info(count($pages)." pages pushed — {$total['new']} new, {$total['updated']} updated.");

            if ($force) {
                $this->warn("{$total['overwritten']} pages edited in MetaSync were overwritten with the site's values (force push).");
            }

            if ($total['skipped'] > 0) {
                $this->warn("{$total['skipped']} pages were skipped: the MetaSync plan page limit is reached.");
            }
        }

        if ($redirects !== []) {
            $total = ['new' => 0, 'existing' => 0, 'skipped' => 0];

            foreach (array_chunk($redirects, 500) as $chunk) {
                $result = $api->pushRedirects($chunk);

                $total['new'] += $result['new'];
                $total['existing'] += $result['existing'];
                $total['skipped'] += $result['skipped'] ?? 0;
            }

            $this->info(count($redirects)." redirects pushed — {$total['new']} new, {$total['existing']} already known.");

            if ($total['skipped'] > 0) {
                $this->warn("{$total['skipped']} redirects were skipped: the MetaSync plan redirect limit is reached.");
            }
        }

        return self::SUCCESS;
    }
}

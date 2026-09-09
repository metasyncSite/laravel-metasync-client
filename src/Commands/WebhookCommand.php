<?php

namespace MetaSyncClient\Commands;

use Illuminate\Console\Command;
use MetaSyncClient\ApiClient;

class WebhookCommand extends Command
{
    protected $signature = 'metasync:webhook {url? : Callback URL (defaults to this app\'s webhook route)} {--remove : Unregister the webhook}';

    protected $description = 'Register this site\'s webhook in MetaSync so changes are delivered instantly';

    public function handle(ApiClient $api): int
    {
        if ($this->option('remove')) {
            $api->registerWebhook(null);
            $this->info('Webhook unregistered. The site will sync via `metasync:pull` on schedule.');

            return self::SUCCESS;
        }

        $url = $this->argument('url') ?? url((string) config('metasync-client.webhook_path'));

        $result = $api->registerWebhook($url);

        $this->info("Webhook registered: {$url}");
        $this->line('Add the secret to your .env so incoming pings can be verified:');
        $this->line('  METASYNC_WEBHOOK_SECRET='.($result['secret'] ?? ''));

        return self::SUCCESS;
    }
}

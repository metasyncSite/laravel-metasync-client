<?php

namespace MetaSyncClient\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use MetaSyncClient\MetaSyncClientServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    /**
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [MetaSyncClientServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('metasync-client.url', 'https://metasync.test');
        $app['config']->set('metasync-client.token', 'test-token');
    }
}

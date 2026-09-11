<?php

namespace MetaSyncClient\Contracts;

/**
 * Implement and bind this contract in your application to tell the package
 * which pages exist on the site. `metasync:push` sends the collected pages
 * to MetaSync. This is the polymorphic point every platform integration
 * (Laravel app, WordPress, OpenCart) provides in its own way.
 */
interface PageCollector
{
    /**
     * @return iterable<array{
     *     url_path: string,
     *     lang?: string,
     *     page_type?: string|null,
     *     title?: string|null,
     *     description?: string|null,
     *     h1?: string|null,
     *     text?: string|null,
     *     short_text?: string|null,
     *     noindex?: bool,
     *     http_status?: int
     * }>
     */
    public function collect(): iterable;
}

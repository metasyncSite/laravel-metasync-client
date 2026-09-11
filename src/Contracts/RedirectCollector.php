<?php

namespace MetaSyncClient\Contracts;

/**
 * Implement and bind this contract in your application to tell the package
 * which redirects already exist on the site. `metasync:push` sends the
 * collected redirects to MetaSync so they can be managed there from then on.
 * Binding it is optional — without a binding, push only sends pages.
 */
interface RedirectCollector
{
    /**
     * @return iterable<array{
     *     from_path: string,
     *     to_url: string,
     *     status_code?: int,
     *     is_active?: bool
     * }>
     */
    public function collect(): iterable;
}

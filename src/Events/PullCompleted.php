<?php

namespace MetaSyncClient\Events;

/**
 * Fired after a pull run has upserted rows into the local cache tables and
 * acknowledged them to MetaSync. Host applications can listen to this event
 * to apply the pulled meta into their own storage (CMS models, caches, etc.).
 */
class PullCompleted
{
    /**
     * @param list<int> $pageIds MetaSync page ids upserted into `metasync_pages`
     * @param list<int> $redirectIds MetaSync redirect ids upserted into `metasync_redirects`
     */
    public function __construct(
        public readonly array $pageIds,
        public readonly array $redirectIds,
    ) {}
}

<?php

namespace MetaSyncClient\Http;

use Illuminate\Http\Response;
use MetaSyncClient\IndexNowKey;

class IndexNowKeyController
{
    /**
     * Serves /<key>.txt so search engines can verify the IndexNow key
     * MetaSync submits URLs with.
     */
    public function __invoke(IndexNowKey $keys, string $key): Response
    {
        abort_unless($keys->matches($key), 404);

        return response($key, 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }
}

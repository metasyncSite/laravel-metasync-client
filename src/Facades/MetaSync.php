<?php

namespace MetaSyncClient\Facades;

use Illuminate\Support\Facades\Facade;
use Illuminate\Support\HtmlString;
use MetaSyncClient\MetaResolver;

/**
 * @method static object|null forRequest(?string $lang = null)
 * @method static object|null forPath(string $path, ?string $lang = null)
 * @method static HtmlString head(?string $path = null, ?string $lang = null)
 *
 * @see MetaResolver
 */
class MetaSync extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return MetaResolver::class;
    }
}

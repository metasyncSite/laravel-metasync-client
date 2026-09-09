<?php

namespace MetaSyncClient\Tests;

use Illuminate\Support\Facades\DB;
use MetaSyncClient\Facades\MetaSync;
use MetaSyncClient\MetaResolver;

class MetaResolverTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeEntry(string $path, string $lang = 'uk', array $attributes = []): void
    {
        DB::table('metasync_pages')->insert([
            'remote_id' => random_int(1, 100000),
            'url_path' => $path,
            'url_hash' => sha1($path),
            'lang' => $lang,
            'title' => 'Title',
            'created_at' => now(),
            'updated_at' => now(),
            ...$attributes,
        ]);
    }

    public function test_lookup_prefers_exact_language_and_falls_back(): void
    {
        $this->makeEntry('/about', 'uk', ['title' => 'Українська']);
        $this->makeEntry('/about', 'en', ['title' => 'English']);

        $resolver = app(MetaResolver::class);

        $this->assertSame('English', $resolver->forPath('/about', 'en')?->title);
        $this->assertSame('Українська', $resolver->forPath('/about', 'de')?->title);
    }

    public function test_lookup_tolerates_trailing_slash_mismatch(): void
    {
        $this->makeEntry('/about');

        $resolver = app(MetaResolver::class);

        $this->assertNotNull($resolver->forPath('/about/'));
        $this->assertNotNull($resolver->forPath('about'));
    }

    public function test_head_renders_escaped_tags(): void
    {
        $this->makeEntry('/about', 'uk', [
            'title' => 'Про <нас>',
            'description' => 'Опис "сторінки"',
            'noindex' => true,
        ]);

        $html = (string) MetaSync::head('/about', 'uk');

        $this->assertStringContainsString('<title>Про &lt;нас&gt;</title>', $html);
        $this->assertStringContainsString('name="description"', $html);
        $this->assertStringContainsString('noindex, nofollow', $html);
        $this->assertStringNotContainsString('<нас>', $html);
    }

    public function test_head_is_empty_for_unknown_page(): void
    {
        $this->assertSame('', (string) MetaSync::head('/missing'));
    }
}

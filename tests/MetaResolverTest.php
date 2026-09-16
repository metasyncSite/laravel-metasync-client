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

    public function test_head_renders_extended_meta(): void
    {
        $this->makeEntry('/about', 'uk', [
            'og_title' => 'OG заголовок',
            'og_description' => 'OG опис',
            'og_image' => 'https://example.com/og.jpg',
            'canonical_url' => 'https://example.com/about',
            'hreflang' => json_encode(['uk' => 'https://example.com/about', 'ru' => 'https://example.com/ru/about']),
            'schema_json' => json_encode(['@context' => 'https://schema.org', '@type' => 'WebPage']),
        ]);

        $html = (string) MetaSync::head('/about', 'uk');

        $this->assertStringContainsString('<meta property="og:title" content="OG заголовок">', $html);
        $this->assertStringContainsString('<meta property="og:description" content="OG опис">', $html);
        $this->assertStringContainsString('<meta property="og:image" content="https://example.com/og.jpg">', $html);
        $this->assertStringContainsString('<link rel="canonical" href="https://example.com/about">', $html);
        $this->assertStringContainsString('<link rel="alternate" hreflang="ru" href="https://example.com/ru/about">', $html);
        $this->assertStringContainsString('<script type="application/ld+json">', $html);
        $this->assertStringContainsString('"@type":"WebPage"', $html);
    }

    public function test_json_ld_cannot_break_out_of_the_script_element(): void
    {
        $this->makeEntry('/about', 'uk', [
            'schema_json' => json_encode(['name' => '</script><script>alert(1)</script>']),
        ]);

        $html = (string) MetaSync::head('/about', 'uk');

        $this->assertStringNotContainsString('</script><script>alert(1)', $html);
    }
}

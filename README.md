# MetaSync Laravel Client

Syncs your Laravel site's meta tags and redirects with [MetaSync](https://metasync.site).

## Installation

```bash
composer require metasyncsite/laravel-metasync-client
php artisan migrate
```

`.env`:

```
METASYNC_URL=https://app.metasync.site
METASYNC_TOKEN=<project token from MetaSync>
```

## Exposing your pages (required for push)

Implement the `PageCollector` contract — it yields the site's pages:

```php
use MetaSyncClient\Contracts\PageCollector;

class AppPageCollector implements PageCollector
{
    public function collect(): iterable
    {
        foreach (Product::query()->cursor() as $product) {
            yield [
                'url_path' => '/products/'.$product->slug,
                'lang' => 'uk',
                'page_type' => 'product',
                'title' => $product->meta_title,
                'description' => $product->meta_description,
                'h1' => $product->name,
                'http_status' => 200,
            ];
        }
    }
}
```

Then bind it in `AppServiceProvider::register()`:

```php
$this->app->bind(PageCollector::class, AppPageCollector::class);
```

## Exposing your redirects (optional)

If the site already has redirects, implement `RedirectCollector` the same way and `metasync:push` will import them into MetaSync so they can be managed there from then on. Redirects already known to MetaSync (same `from_path`) are never overwritten — MetaSync wins conflicts.

```php
use MetaSyncClient\Contracts\RedirectCollector;

class AppRedirectCollector implements RedirectCollector
{
    public function collect(): iterable
    {
        foreach (Redirect::query()->cursor() as $redirect) {
            yield [
                'from_path' => $redirect->from,
                'to_url' => $redirect->to,
                'status_code' => $redirect->code, // 301 or 302
                'is_active' => true,
            ];
        }
    }
}
```

Without a binding, push only sends pages.

## Commands

| Command | Description |
|---|---|
| `metasync:push [--dry-run]` | Push the site's pages and redirects to MetaSync |
| `metasync:pull [--full]` | Pull edited meta and redirects into the local cache tables |
| `metasync:sync` | push + pull |
| `metasync:webhook [url] [--remove]` | Register a webhook for instant change delivery |

`metasync:pull` registers itself on the scheduler every 15 minutes as a fallback for when the webhook cannot reach the site (disable with `METASYNC_SCHEDULE_PULL=false`, change the schedule with `METASYNC_SCHEDULE_CRON`).

Entries deleted in MetaSync arrive in the pull feed with a `deleted` flag and are removed from the local cache tables, so redirects stop firing and cached meta disappears without any manual cleanup. A `--full` pull is authoritative: it also drops any local rows the server no longer knows about.

## Rendering meta on pages

The simplest way is the directive in your layout's `<head>` — it outputs `<title>`, description, robots, Open Graph tags, the canonical link, hreflang alternates and JSON-LD markup, and renders nothing for pages without an entry:

```blade
<head>
    @metasyncHead
</head>
```

Or resolve entries directly via the facade or resolver (prefers the exact language with a fallback to any; tolerates a trailing-slash mismatch):

```php
$meta = MetaSync::forRequest();            // or app(\MetaSyncClient\MetaResolver::class)
$meta = MetaSync::forPath('/about', 'uk');
```

```blade
<title>{{ $meta?->title ?? config('app.name') }}</title>
@if($meta?->description)<meta name="description" content="{{ $meta->description }}">@endif
@if($meta?->noindex)<meta name="robots" content="noindex">@endif

<h1>{{ $meta?->h1 ?? $product->name }}</h1>
```

Extended fields synced from MetaSync are also available on the resolved entry: `og_title`, `og_description`, `og_image`, `canonical_url`, plus JSON-encoded `hreflang` (a `lang => URL` map) and `schema_json` (a JSON-LD object).

## Redirects

The `HandleRedirects` middleware registers automatically (disable with `METASYNC_REDIRECTS=false`) and applies active redirects from MetaSync to all GET requests.

## 404 reporting

When a GET request falls through to a 404, the middleware buffers the path (with hit counter and first referer) in the local `metasync_not_found` table — no HTTP calls on the request path. The next `metasync:pull` reports the buffered hits to MetaSync (`POST /api/v1/errors/404`) and clears them; in MetaSync they appear on the Redirects screen where a redirect can be created in one click. Disable with `METASYNC_REPORT_404=false`.

## Instant change delivery

1. `php artisan metasync:webhook` — registers `POST /metasync/webhook` in MetaSync and prints the secret.
2. Add `METASYNC_WEBHOOK_SECRET=...` to your `.env`.
3. When meta changes in MetaSync, the service pings your site, the package queues a pull and the changes apply automatically.

## IndexNow

Enable IndexNow for the project on MetaSync's Indexing screen. MetaSync then notifies Bing, Yandex, Naver, Seznam and Yep every time this site applies changed meta or new redirects, and after a "Submit all" from the screen.

The search engines verify ownership by fetching `https://<your-site>/<key>.txt`. The package serves that file automatically: it reads the key from `GET /api/v1/project`, caches it for an hour and re-checks once a minute when an unknown key is requested, so a regenerated key is picked up quickly. Keys are 32 hex characters, so the route never shadows `robots.txt`, `security.txt` or similar files. Set `METASYNC_INDEXNOW=false` to not register the route.

## Events

After every pull that applied changes, the package fires `MetaSyncClient\Events\PullCompleted` with the MetaSync ids of the pages and redirects that were upserted into the local cache tables. Listen to it when your application keeps meta in its own models (a CMS, for example) and needs to copy the pulled values there:

```php
use MetaSyncClient\Events\PullCompleted;

Event::listen(PullCompleted::class, function (PullCompleted $event) {
    // $event->pageIds — remote ids, match them against metasync_pages.remote_id
    // $event->redirectIds — same for metasync_redirects.remote_id
    // $event->deletedPageIds / $event->deletedRedirectIds — remote ids whose
    // rows were just removed from the local cache (deleted in MetaSync)
});
```

## Tests

```bash
composer install
composer test
```

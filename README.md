# MetaSync Laravel Client

Синхронізує мета-теги та редиректи вашого Laravel-сайту з [MetaSync](https://metasync.site).

## Встановлення

```bash
composer require metasyncsite/laravel-metasync-client
php artisan migrate
```

`.env`:

```
METASYNC_URL=https://app.metasync.site
METASYNC_TOKEN=<токен проекту з MetaSync>
```

## Підключення сторінок (обов'язково для push)

Реалізуйте контракт `PageCollector` — він віддає список сторінок сайту:

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

І забіндіть у `AppServiceProvider::register()`:

```php
$this->app->bind(PageCollector::class, AppPageCollector::class);
```

## Команди

| Команда | Опис |
|---|---|
| `metasync:push [--dry-run]` | Відправити сторінки сайту в MetaSync |
| `metasync:pull [--full]` | Стягнути змінені мета/редиректи в локальні таблиці |
| `metasync:sync` | push + pull |
| `metasync:webhook [url] [--remove]` | Зареєструвати webhook для миттєвої доставки змін |

`metasync:pull` сам реєструється в шедулері кожні 15 хв як fallback, якщо webhook недоступний (вимкнути: `METASYNC_SCHEDULE_PULL=false`, змінити розклад: `METASYNC_SCHEDULE_CRON`).

## Рендер мета на сторінках

Найпростіше — директива в `<head>` layout'а (виводить `<title>`, description і robots, нічого не рендерить для сторінок без запису):

```blade
<head>
    @metasyncHead
</head>
```

Або точково через фасад чи резолвер (шукає точну мову, фолбек на будь-яку; толерантний до trailing slash):

```php
$meta = MetaSync::forRequest();            // або app(\MetaSyncClient\MetaResolver::class)
$meta = MetaSync::forPath('/about', 'uk');
```

```blade
<title>{{ $meta?->title ?? config('app.name') }}</title>
@if($meta?->description)<meta name="description" content="{{ $meta->description }}">@endif
@if($meta?->noindex)<meta name="robots" content="noindex">@endif

<h1>{{ $meta?->h1 ?? $product->name }}</h1>
```

## Редиректи

Middleware `HandleRedirects` реєструється автоматично (вимкнути: `METASYNC_REDIRECTS=false`) і застосовує активні редиректи з MetaSync до всіх GET-запитів.

## Миттєва доставка змін

1. `php artisan metasync:webhook` — зареєструє `POST /metasync/webhook` у MetaSync і виведе секрет.
2. Додайте `METASYNC_WEBHOOK_SECRET=...` в `.env`.
3. Коли в MetaSync змінюють мета — сервіс пінгує сайт, пакет ставить у чергу pull, зміни застосовуються автоматично.

## Тести

```bash
composer install
composer test
```

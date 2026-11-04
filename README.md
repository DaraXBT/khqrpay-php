# KHQRPay for PHP: official KHQR payments SDK for Cambodia

Accept KHQR payments from ABA, ACLEDA, Wing and every Bakong app, with the money going straight to your ABA account. KHQRPay never holds it. This is the official PHP library for the [KHQRPay](https://khqrpay.dev) API. The full API reference is at [khqrpay.dev/docs](https://khqrpay.dev/docs).

- PHP 7.4 or later, with ext-curl and ext-json. Nothing else.
- Works with Composer, or without it inside a WordPress or WooCommerce plugin.
- Webhook verification that needs no API key.

## Install

```bash
composer require khqrpay/khqrpay-php
```

```php
require __DIR__ . '/vendor/autoload.php';

$khqrpay = new \KHQRPay\Client(getenv('KHQRPAY_SECRET_KEY')); // kp_live_... or kp_test_...
```

### Without Composer

Copy the `src/` folder into your project, then require its autoloader. This is how you bundle the SDK inside a WordPress or WooCommerce plugin.

```php
require_once __DIR__ . '/lib/khqrpay-php/src/autoload.php';

$khqrpay = new \KHQRPay\Client(get_option('my_plugin_khqrpay_secret_key'));
```

### Options

```php
$khqrpay = new \KHQRPay\Client($secretKey, [
    'base_url' => 'https://khqrpay.dev', // the default
    'timeout' => 30,                     // seconds per request, the default
]);
```

The client refuses an empty key, or one that doesn’t start with `kp_live_` or `kp_test_`, with an `\InvalidArgumentException`. Keep the key on your server. The SDK sends it only in the `Authorization` header to `base_url`, never follows redirects, and hides it from `var_dump()` and `print_r()`.

## Create a payment and redirect to checkout

```php
$payment = $khqrpay->payments->create([
    'amount' => '16.88',          // always a string
    'currency' => 'USD',          // or 'KHR', in whole riel: '20000'
    'reference_id' => 'INV-2048', // your order id
    'metadata' => ['cart_id' => 'c_1'],
], ['idempotency_key' => 'INV-2048-attempt-1']); // one per checkout attempt

header('Location: ' . $payment['checkout_url'], true, 303);
exit;
```

Amounts are strings, like `'16.88'` or `'20000'`. A float or int amount throws `\InvalidArgumentException` before anything is sent, because floats lose precision.

Every call returns the decoded JSON as an associative array. The payment has an `id`, a `status` (`pending`, `scanned`, `paid`, `expired` or `failed`), a `checkout_url`, and a `qr_string` if you’d rather draw the KHQR yourself.

`idempotency_key` is optional. Without it, the SDK sends a random UUID v4 as the `Idempotency-Key` header. Pass your own, one per checkout attempt (such as `INV-2048-attempt-1`), so a retry of that attempt can’t create a second payment. Don’t reuse one key for the whole order: a customer who tries again after the QR expired would get the expired payment back.

### Retrieve, list and usage

```php
$payment = $khqrpay->payments->retrieve('pFQ3xV8aN2rL7kTm9sB4cWd6hJ1yZ0eU');

$cursor = null;
do {
    $page = $khqrpay->payments->list(['status' => 'paid', 'limit' => 100, 'cursor' => $cursor]);
    foreach ($page['data'] as $item) {
        // List items leave out metadata. Call retrieve() for every field.
    }
    $cursor = $page['next_cursor'];
} while ($page['has_more']);

$usage = $khqrpay->usage->retrieve(); // plan, limit, used, remaining, period_start, period_end
```

Unset or `null` list params are left out of the query string.

## Verify a webhook

KHQRPay sends `payment.completed`, `payment.scanned`, `payment.expired` and `payment.failed` to your endpoint. Each one is signed in the `X-KHQRPay-Signature` header.

> **Verify the raw body, before any JSON parsing.** Pass the exact bytes you received. A body that was decoded and encoded again has different bytes, and its signature won’t match.

`Webhook::verify()` returns `true` or `false` and never throws. `Webhook::constructEvent()` verifies, then returns the parsed event, or throws `KHQRPayError` with the code `invalid_signature` and status 400. Both reject a timestamp more than 5 minutes off. Neither needs your API key, only the endpoint’s `whsec_` signing secret.

Deliveries can repeat. Deduplicate on the event’s `id`, and answer with a 2xx quickly.

### Laravel

Read the body with `$request->getContent()`, never `$request->all()`:

```php
// routes/web.php
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use KHQRPay\KHQRPayError;
use KHQRPay\Webhook;

Route::post('/webhooks/khqrpay', function (Request $request) {
    try {
        $event = Webhook::constructEvent(
            $request->getContent(), // the raw body
            $request->header('X-KHQRPay-Signature'),
            config('services.khqrpay.webhook_secret')
        );
    } catch (KHQRPayError $e) {
        return response('Invalid signature', 400);
    }

    // A test event or a test-mode payment: nothing to fulfil.
    if (($event['test'] ?? false) === true || ($event['livemode'] ?? false) !== true) {
        return response()->noContent();
    }

    // Handle each event once, however many times it arrives.
    if (Cache::add('khqrpay-event:' . $event['id'], true, now()->addDay())) {
        $payment = $event['data']['payment'];
        if ($event['type'] === 'payment.completed') {
            // Mark the order $payment['reference_id'] as paid.
        }
    }

    return response()->noContent();
});
```

KHQRPay can’t send a CSRF token, so exclude the route from CSRF protection. On Laravel 11 and later, in `bootstrap/app.php`:

```php
->withMiddleware(function (Middleware $middleware) {
    $middleware->validateCsrfTokens(except: [
        'webhooks/khqrpay',
    ]);
})
```

On Laravel 10 and earlier, add it to `app/Http/Middleware/VerifyCsrfToken.php`:

```php
protected $except = [
    'webhooks/khqrpay',
];
```

Keep the secrets in `config/services.php`:

```php
'khqrpay' => [
    'secret_key' => env('KHQRPAY_SECRET_KEY'),
    'webhook_secret' => env('KHQRPAY_WEBHOOK_SECRET'),
],
```

### Plain PHP

```php
<?php
require __DIR__ . '/vendor/autoload.php';

// Read the raw body once, and verify it before json_decode().
$payload = file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_KHQRPAY_SIGNATURE'] ?? '';

if (!\KHQRPay\Webhook::verify($payload, $signature, getenv('KHQRPAY_WEBHOOK_SECRET'))) {
    http_response_code(400);
    exit('Invalid signature');
}

$event = json_decode($payload, true);
if (($event['test'] ?? false) === true || ($event['livemode'] ?? false) !== true) {
    http_response_code(200); // a test event or test-mode payment: nothing to fulfil
    exit;
}
// Deduplicate on $event['id'], then handle $event['type'].

http_response_code(200);
```

In a WordPress REST route, verify `$request->get_body()` with `$request->get_header('X-KHQRPay-Signature')`.

Both methods take two optional arguments after the secret: the tolerance in seconds (default 300) and the current Unix time (default `time()`), which helps in tests.

```php
\KHQRPay\Webhook::verify($payload, $signature, $secret, 300, 1793750410);
```

## Errors and retries

Every failed call throws `\KHQRPay\KHQRPayError`, which extends `\RuntimeException`. Branch on `getErrorCode()`, never on the message.

| Getter | What it holds |
|---|---|
| `getErrorCode()` | The API’s error code, such as `invalid_request`, `amount_too_low`, `quota_exceeded` or `rate_limited` |
| `getMessage()` | A sentence for people. It may change. |
| `getStatus()` | The HTTP status, or 0 when no response arrived |
| `getRetryAfter()` | Seconds from the `Retry-After` header, or `null` |

The SDK adds three codes of its own:

- `network_error`: KHQRPay couldn’t be reached, or the request timed out. The status is 0.
- `invalid_response`: the body wasn’t the JSON we expected, such as an HTML error page from a proxy.
- `invalid_signature`: a webhook failed `constructEvent()`.

The SDK never retries by itself. On 429, 500, 502 and 503, and on `network_error`, it’s safe to retry with the same idempotency key, after `Retry-After` when it’s set:

```php
use KHQRPay\KHQRPayError;

$params = ['amount' => '16.88', 'currency' => 'USD', 'reference_id' => 'INV-2048'];
$options = ['idempotency_key' => 'INV-2048-attempt-1']; // one per checkout attempt, the same on every retry

for ($attempt = 1; ; $attempt++) {
    try {
        $payment = $khqrpay->payments->create($params, $options);
        break;
    } catch (KHQRPayError $e) {
        $retryable = in_array($e->getStatus(), [429, 500, 502, 503], true)
            || $e->getErrorCode() === 'network_error';
        if (!$retryable || $attempt === 3) {
            throw $e;
        }
        sleep($e->getRetryAfter() ?? $attempt);
    }
}
```

Within 24 hours, the same key returns the same payment. Reusing it with a different amount or `reference_id` gives `idempotency_conflict`.

## Test mode

A `kp_test_` key creates simulated payments that never touch a bank. Move one through its life with `simulate()`. It sends the matching webhook, just as a live payment would:

```php
$test = new \KHQRPay\Client(getenv('KHQRPAY_TEST_KEY')); // kp_test_...

$payment = $test->payments->create(['amount' => '1.00', 'reference_id' => 'TEST-1']);
$test->payments->simulate($payment['id'], 'scanned');
$paid = $test->payments->simulate($payment['id'], 'paid'); // sends payment.completed
```

The statuses are `scanned`, `paid`, `expired` and `failed`. A live key gets an error.

## Guide

For a full walkthrough, with checkout, webhooks and order updates in Laravel, read [Accept KHQR payments in PHP and Laravel](https://khqrpay.dev/guides/khqr-api-php-laravel).

## Development

```bash
composer install
vendor/bin/phpunit
```

The tests run every case in `tests/test-vectors.json`, and send real HTTP requests to a local `php -S` server.

## Using your own HTTP layer

By default the SDK sends requests with ext-curl. Hosts with their own HTTP layer can pass a `transport` instead. In WordPress:

```php
$khqrpay = new \KHQRPay\Client($key, [
    'transport' => function ($method, $url, $headers, $body, $timeout) {
        $res = wp_remote_request($url, [
            'method' => $method, 'headers' => $headers, 'body' => $body,
            'timeout' => $timeout, 'redirection' => 0,
        ]);
        if (is_wp_error($res)) {
            throw new \RuntimeException($res->get_error_message());
        }
        return [
            'status' => (int) wp_remote_retrieve_response_code($res),
            'headers' => wp_remote_retrieve_headers($res)->getAll(),
            'body' => wp_remote_retrieve_body($res),
        ];
    },
]);
```

A transport must not follow redirects. A thrown exception becomes a `network_error`.

## License

MIT. See [LICENSE](LICENSE).

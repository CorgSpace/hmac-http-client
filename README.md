# hmac-http-client

[![Latest Version on Packagist](https://img.shields.io/packagist/v/corgspace/hmac-http-client.svg?style=flat-square)](https://packagist.org/packages/corgspace/hmac-http-client)
[![Tests](https://img.shields.io/github/actions/workflow/status/CorgSpace/hmac-http-client/tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/CorgSpace/hmac-http-client/actions/workflows/tests.yml)
[![Total Downloads](https://img.shields.io/packagist/dt/corgspace/hmac-http-client.svg?style=flat-square)](https://packagist.org/packages/corgspace/hmac-http-client)

Laravel package that adds HMAC-signed HTTP requests to the built-in HTTP client via an `Http::hmac('service_name')` macro. Each outgoing request gets `X-Key-Id`, `X-Timestamp`, `X-Nonce`, and `X-Signature` headers added automatically; the caller supplies `X-Idempotency-Key` and the body.

## Install

```sh
composer require corgspace/hmac-http-client
php artisan vendor:publish --tag=hmac-http-client-config
```

## Configure

Add a service to `config/hmac-http-client.php`:

```php
'services' => [
    'example_api' => [
        'base_url'        => env('EXAMPLE_API_URL'),
        'key_id'          => env('EXAMPLE_API_KEY_ID'),
        'secret'          => env('EXAMPLE_API_SECRET'),
        'secret_encoding' => env('EXAMPLE_API_SECRET_ENCODING', 'base64'),
    ],
],
```

`.env`:

```
EXAMPLE_API_URL=https://api.example.com
EXAMPLE_API_KEY_ID=my-app-prod
EXAMPLE_API_SECRET=<base64- or hex-encoded secret>
```

`secret_encoding` is `base64` (default), `hex`, or `raw`. Decoded secret must be at least 32 bytes.

## Use

```php
use Illuminate\Support\Facades\Http;

$response = Http::hmac('example_api')
    ->withHeaders(['X-Idempotency-Key' => $operationId])
    ->post('/v1/resource', [
        'external_ref' => $externalId,
        'source'       => 'direct',
    ]);

if ($response->successful()) {
    $data = $response->json();
}
```

The macro returns a `PendingRequest` with `acceptJson()->asJson()` already applied. Chain any normal HTTP client method after `Http::hmac(...)`.

**The caller must set `X-Idempotency-Key`.** It is part of the signed canonical and should be meaningful to the upstream (a webhook event ID, a logical operation ID, etc.). For read-only calls with no natural key, generate a fresh UUID per call.

## Retries

Laravel's built-in retry re-signs on every attempt — fresh nonce and timestamp, same idempotency key:

```php
Http::hmac('example_api')
    ->withHeaders(['X-Idempotency-Key' => $operationId])
    ->retry(3, 100)
    ->post('/v1/resource', $payload);
```

## Canonical string format

For implementers of the verifier side, or anyone debugging a signature mismatch:

```
{METHOD}\n{REQUEST_TARGET}\n{TIMESTAMP}\n{NONCE}\n{IDEMPOTENCY_KEY}\n{hex(sha256(BODY))}
```

- No trailing newline. Separators are single `\n` (0x0A), never `\r\n`.
- `METHOD` uppercased.
- `REQUEST_TARGET` is the full request target as it appears on the wire — path plus query string, in the exact order the client sends it. Matches PSR-7's `RequestInterface::getRequestTarget()`. Examples: `/v1/users`, `/v1/search?q=widget&sort=asc`. Empty targets are normalized to `/` by PSR-7.
- `BODY` is the raw request body bytes. Empty body hashes to `e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855`.
- Signature is `base64(hmac_sha256(canonical, secret_bytes))`.

Verifier must read the request target from the same source (e.g. the raw request line) and apply the same method-case rules. Any reordering or reformatting of query parameters on either side will invalidate the signature.

## Testing

```sh
composer test         # phpunit
composer analyse      # phpstan (level max, larastan)
composer format-test  # pint --test
```

`composer format` applies pint fixes in place.

## Changelog

See [CHANGELOG](CHANGELOG.md) for a list of recent changes.

## Contributing

Contributions are welcome. Please open an issue or pull request at [github.com/CorgSpace/hmac-http-client](https://github.com/CorgSpace/hmac-http-client). Run `composer test`, `composer analyse`, and `composer format-test` before submitting.

## Security

If you discover a security vulnerability, please report it privately via GitHub's [private vulnerability reporting](https://github.com/CorgSpace/hmac-http-client/security/advisories/new) rather than opening a public issue. See [SECURITY.md](SECURITY.md) for details.

## License

MIT — see [LICENSE](LICENSE).

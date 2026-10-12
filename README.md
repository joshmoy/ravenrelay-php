# RavenRelay PHP SDK

PHP 8.1+ with cURL and JSON extensions. The package name is `ravenrelay/php`. Install from Packagist with:

```sh
composer require ravenrelay/php
```

Load Composer's `vendor/autoload.php` before using the SDK.

```php
use RavenRelay\RavenRelay;
use RavenRelay\Webhooks;

$client = new RavenRelay(getenv('RAVENRELAY_API_KEY'), getenv('RAVENRELAY_API_URL') ?: 'https://api.ravenrelay.com');
$result = $client->emails->send([
    'from' => 'hello@example.com', 'to' => 'user@example.com',
    'subject' => 'Welcome', 'text' => 'Hello',
], idempotencyKey: 'welcome-123');
$email = $client->emails->get($result['id']);
$page = $client->emails->list(status: 'sent', recipient: 'user@example.com');
```

Saved templates use `template`/`variables` instead of subject/body. Responses are associative arrays preserving API field names. `ApiException` exposes `status`/`errorCode`. `TransportException` means acceptance may be uncertain; `ProtocolException` means an invalid successful response. No automatic retries; retry only with the same idempotency key and body. Requests have a 10-second total timeout by default. Redirects are refused. The default origin is `https://api.ravenrelay.com`; supply your deployed API origin explicitly. Keep API keys server-side.

`Webhooks::verify($secret, $timestamp, $signature, $rawBody)` checks original request bytes with HMAC-SHA256, constant-time comparison, and a five-minute tolerance. Verify before parsing JSON and durably deduplicate event IDs. Optional `now` is Unix seconds for tests.

```sh
python3 tests/contract_server.py php tests/contract.php
```

The public distribution repository is https://github.com/joshmoy/ravenrelay-php. Versions come from Git tags; the first release is `v0.1.0`. Source development remains in the RavenRelay monorepo. No reuse license has been granted.

Security: remote origins must use HTTPS. Plain HTTP is accepted only for literal `localhost`, `127.0.0.1` and IPv6 loopback hosts. Credentials/query/fragment, whitespace and backslashes are rejected in API origins. Authentication/idempotency headers must be printable ASCII; `.` and `..` email IDs are rejected. Response bodies are limited to 16 MiB, valid UTF-8 and 64 JSON nesting levels. TLS certificate verification stays enabled. Use vendor-supported runtimes with current security patches; the compatibility floor does not promise that an old runtime is secure. No third-party PHP packages, Composer plugins or install scripts are required.

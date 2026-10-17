<?php
declare(strict_types=1);
namespace RavenRelay;

class ApiException extends \RuntimeException
{
    public function __construct(public readonly int $status, public readonly string $errorCode, string $message)
    {
        parent::__construct($message);
    }
}
class TransportException extends \RuntimeException {}
class ProtocolException extends \RuntimeException {}

final class RavenRelay
{
    public readonly Emails $emails;
    private string $baseUrl;
    public function __construct(private string $apiKey, string $baseUrl = 'https://api.getravenrelay.com', private float $timeout = 10)
    {
        self::validateHeader($apiKey);
        if (preg_match('/[\\\\\x00-\x20\x7f]/', $baseUrl)) throw new \InvalidArgumentException('Invalid baseUrl');
        $url = parse_url($baseUrl);
        if (!$url || !in_array($url['scheme'] ?? '', ['https', 'http'], true) || empty($url['host']) ||
            isset($url['user']) || isset($url['pass']) || isset($url['query']) || isset($url['fragment'])) {
            throw new \InvalidArgumentException('baseUrl must be an absolute HTTP(S) URL without credentials, query or fragment');
        }
        if ($url['scheme'] === 'http' && !in_array($url['host'], ['localhost', '127.0.0.1', '[::1]'], true)) throw new \InvalidArgumentException('Use HTTPS; HTTP is only allowed on literal loopback hosts');
        if (!is_finite($timeout) || $timeout <= 0) throw new \InvalidArgumentException('timeout must be finite and positive');
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->emails = new Emails($this);
    }
    private static function validateHeader(string $value): void
    {
        if ($value === '' || preg_match('/[^\x20-\x7e]/', $value)) throw new \InvalidArgumentException('Header values must be nonempty without control characters');
    }
    /** @internal */
    public function request(string $method, string $path, ?array $body = null, ?string $idempotencyKey = null): array
    {
        $headers = ['Authorization: Bearer '.$this->apiKey, 'Content-Type: application/json'];
        if ($idempotencyKey !== null) {
            self::validateHeader($idempotencyKey);
            $headers[] = 'Idempotency-Key: '.$idempotencyKey;
        }
        $rawBody = $body === null ? null : json_encode((object)$body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $curl = curl_init($this->baseUrl.$path);
        if ($curl === false) throw new TransportException('Could not initialize HTTP transport');
        $raw = '';
        $oversized = false;
        curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT_MS => max(1, (int)ceil($this->timeout * 1000)),
            CURLOPT_TIMEOUT_MS => max(1, (int)ceil($this->timeout * 1000)),
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$raw, &$oversized): int {
                if (strlen($raw) + strlen($chunk) > 16 * 1024 * 1024) { $oversized = true; return 0; }
                $raw .= $chunk;
                return strlen($chunk);
            },
        ]);
        if ($rawBody !== null) curl_setopt($curl, CURLOPT_POSTFIELDS, $rawBody);
        try {
            $success = curl_exec($curl);
            $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            if ($oversized) throw new ProtocolException('RavenRelay response exceeds 16 MiB');
            if ($success === false) throw new TransportException('RavenRelay network request failed; acceptance may be uncertain');
        } finally {
            unset($curl);
        }
        try { $parsed = json_decode($raw, false, 64, JSON_THROW_ON_ERROR); }
        catch (\JsonException $error) { $parsed = null; }
        if ($status < 200 || $status >= 300) {
            $error = $parsed instanceof \stdClass && ($parsed->error ?? null) instanceof \stdClass ? $parsed->error : new \stdClass();
            throw new ApiException($status, is_string($error->code ?? null) ? $error->code : 'unknown_error',
                is_string($error->message ?? null) ? $error->message : 'Request failed');
        }
        if (!$parsed instanceof \stdClass) throw new ProtocolException('RavenRelay response must be a JSON object');
        return json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
    }
}

final class Emails
{
    public function __construct(private RavenRelay $client) {}
    public function send(array $email, ?string $idempotencyKey = null): array { return $this->client->request('POST', '/v1/emails', $email, $idempotencyKey); }
    public function get(string $id): array
    {
        if ($id === '' || $id === '.' || $id === '..') throw new \InvalidArgumentException('Email ID must be nonempty');
        return $this->client->request('GET', '/v1/emails/'.rawurlencode($id));
    }
    public function list(?string $status = null, ?string $recipient = null, ?string $cursor = null): array
    {
        $filters = array_filter(compact('status', 'recipient', 'cursor'), static fn($v) => $v !== null);
        $query = http_build_query($filters, '', '&', PHP_QUERY_RFC3986);
        return $this->client->request('GET', '/v1/emails'.($query === '' ? '' : '?'.$query));
    }
}

final class Webhooks
{
    /** Verify original bytes before parsing JSON; receivers must also deduplicate event IDs. */
    public static function verify(string $secret, ?string $timestamp, ?string $signature, string $body, ?float $now = null): bool
    {
        if ($secret === '' || $timestamp === null || !preg_match('/\A[0-9]{1,12}\z/', $timestamp) ||
            $signature === null || !preg_match('/\Av1=[a-f0-9]{64}\z/', $signature)) return false;
        $now ??= microtime(true);
        if (!is_finite($now) || abs($now - (float)$timestamp) > 300) return false;
        return hash_equals(hash_hmac('sha256', $timestamp.'.'.$body, $secret), substr($signature, 3));
    }
}

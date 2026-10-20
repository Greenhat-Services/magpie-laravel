<?php

namespace Magpie\Laravel\Transport;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use Magpie\Laravel\Dsn;
use Magpie\Laravel\Magpie;
use Magpie\Laravel\Payload;
use Throwable;

final class HttpTransport implements Transport
{
    /** Bodies above this size are gzip-compressed. */
    private const COMPRESS_FROM = 4096;

    private ClientInterface $http;

    private ?string $lastError = null;

    public function __construct(private readonly Dsn $dsn, float $timeout = 3.0, ?ClientInterface $http = null)
    {
        $this->http = $http ?? new Client(['timeout' => $timeout, 'connect_timeout' => min($timeout, 2.0), 'http_errors' => false]);
    }

    public function send(array $payload): bool
    {
        $this->lastError = null;
        $path = ($payload['type'] ?? 'event') === 'transaction' ? 'transactions' : 'events';
        $body = Payload::encode($payload);

        if ($body === null) {
            $this->lastError = 'The payload is larger than '.Payload::MAX_BYTES.' bytes.';

            return false;
        }

        $headers = [
            'Content-Type' => 'application/json',
            'X-Magpie-Auth' => 'Magpie magpie_version=1, magpie_key='.$this->dsn->publicKey.', magpie_client=magpie-laravel/'.Magpie::version(),
        ];

        if (strlen($body) >= self::COMPRESS_FROM && function_exists('gzencode') && ($compressed = gzencode($body)) !== false) {
            $body = $compressed;
            $headers['Content-Encoding'] = 'gzip';
        }

        try {
            $response = $this->http->request('POST', $this->dsn->endpoint($path), ['headers' => $headers, 'body' => $body, 'http_errors' => false]);
        } catch (Throwable $e) {
            // Monitoring must never break the application.
            $this->lastError = $e->getMessage();

            return false;
        }

        $status = $response->getStatusCode();

        if ($status >= 200 && $status < 300) {
            return true;
        }

        $this->lastError = "Magpie responded with HTTP {$status}.";

        return false;
    }

    /** Why the last send() failed, for diagnostics such as `magpie:test`. */
    public function lastError(): ?string
    {
        return $this->lastError;
    }
}

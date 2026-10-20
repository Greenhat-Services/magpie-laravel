<?php

namespace Magpie\Laravel;

use InvalidArgumentException;

/**
 * A Magpie DSN: https://<public key>@<ingest host>/<project number>
 */
final class Dsn
{
    private function __construct(
        public readonly string $scheme,
        public readonly string $host,
        public readonly ?int $port,
        public readonly string $publicKey,
        public readonly string $projectId,
        public readonly string $pathPrefix = '',
    ) {}

    public static function parse(string $dsn): self
    {
        $parts = parse_url(trim($dsn));

        if (! is_array($parts) || empty($parts['scheme']) || empty($parts['host']) || empty($parts['user']) || ! in_array($parts['scheme'], ['http', 'https'], true)) {
            throw new InvalidArgumentException('The Magpie DSN is invalid. Expected https://<key>@<host>/<project>.');
        }

        $segments = array_values(array_filter(explode('/', $parts['path'] ?? ''), fn ($s) => $s !== ''));
        $projectId = array_pop($segments);

        if ($projectId === null || ! ctype_digit($projectId)) {
            throw new InvalidArgumentException('The Magpie DSN is missing its numeric project number.');
        }

        return new self(
            $parts['scheme'],
            $parts['host'],
            $parts['port'] ?? null,
            $parts['user'],
            $projectId,
            $segments ? '/'.implode('/', $segments) : '',
        );
    }

    public function endpoint(string $path = 'events'): string
    {
        $port = $this->port ? ':'.$this->port : '';

        return "{$this->scheme}://{$this->host}{$port}{$this->pathPrefix}/api/{$this->projectId}/{$path}";
    }
}

<?php

namespace Magpie\Laravel;

/**
 * Finds the release of the running code when none is configured: the git commit of the checkout.
 */
final class Release
{
    public static function detect(string $basePath): ?string
    {
        return self::fromGit($basePath.DIRECTORY_SEPARATOR.'.git');
    }

    private static function fromGit(string $gitDir): ?string
    {
        $head = self::read($gitDir.DIRECTORY_SEPARATOR.'HEAD');

        if ($head === null) {
            return null;
        }

        if (self::isSha($head)) {
            return $head;
        }

        if (! str_starts_with($head, 'ref: ')) {
            return null;
        }

        $ref = substr($head, 5);
        $sha = self::read($gitDir.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $ref));

        if ($sha !== null && self::isSha($sha)) {
            return $sha;
        }

        foreach (explode("\n", self::read($gitDir.DIRECTORY_SEPARATOR.'packed-refs') ?? '') as $line) {
            if (str_ends_with(rtrim($line), ' '.$ref) && self::isSha($candidate = substr($line, 0, 40))) {
                return $candidate;
            }
        }

        return null;
    }

    private static function read(string $file): ?string
    {
        if (! is_file($file) || ! is_readable($file)) {
            return null;
        }

        $contents = @file_get_contents($file, false, null, 0, 8192);

        return $contents === false ? null : trim($contents);
    }

    private static function isSha(string $value): bool
    {
        return (bool) preg_match('/^[0-9a-f]{40}$/', $value);
    }
}

<?php

declare(strict_types=1);

namespace Krvh\MinimalPhpAsync;

use RuntimeException;

/**
 * Exception thrown by {@see Async::fetch()} when the HTTP response status is >= 400.
 *
 * Notes:
 * - This is intentionally small: it only exposes the status code. The URL is
 *   included in the exception message for diagnostics.
 * - The status property is readonly so callers can read it, but cannot mutate it.
 *
 * @psalm-suppress PossiblyUnusedProperty
 */
final class HttpException extends RuntimeException
{
    public function __construct(
        public readonly int $status,
        string $url,
    ) {
        parent::__construct("HTTP {$status}: {$url}", $status);
    }
}

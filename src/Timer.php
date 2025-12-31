<?php

declare(strict_types=1);

namespace Krvh\MinimalPhpAsync;

use Fiber;

/**
 * Internal immutable DTO describing a wakeup time for a suspended Fiber.
 *
 * @internal
 * @psalm-internal Krvh\MinimalPhpAsync
 */
final readonly class Timer
{
    public function __construct(
        public float $at,
        public Fiber $fiber,
    ) {
    }
}

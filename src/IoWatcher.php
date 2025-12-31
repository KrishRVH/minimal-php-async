<?php

/**
 * @phan-file-suppress PhanUnusedPublicFinalMethodParameter
 */

declare(strict_types=1);

namespace Krvh\MinimalPhpAsync;

use Fiber;

/**
 * Internal immutable DTO describing a pending I/O operation for a suspended Fiber.
 *
 * - For writes: $buffer is the full payload, $offsetOrMaxBytes is the current write offset.
 * - For reads:  $buffer accumulates the response, $offsetOrMaxBytes is the max allowed bytes.
 *
 * PHP 8.5: clone-with is used to keep the object immutable while updating state.
 *
 * @internal
 * @psalm-internal Krvh\MinimalPhpAsync
 */
final readonly class IoWatcher
{
    public function __construct(
        public mixed $stream,
        public Fiber $fiber,
        public string $buffer = '',
        public int $offsetOrMaxBytes = 0,
    ) {
    }

    /**
     * @psalm-suppress MoreSpecificReturnType
     * @psalm-suppress LessSpecificReturnStatement
     */
    public function with(string $buffer, int $offsetOrMaxBytes): self
    {
        // PHP 8.5 "clone with" syntax.
        return clone($this, [
            'buffer' => $buffer,
            'offsetOrMaxBytes' => $offsetOrMaxBytes,
        ]);
    }
}

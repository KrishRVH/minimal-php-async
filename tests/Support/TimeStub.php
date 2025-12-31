<?php

/**
 * @phan-file-suppress PhanUnextractableAnnotation
 */

declare(strict_types=1);

namespace Krvh\MinimalPhpAsync\Tests\Support;

final class TimeStub
{
    private static bool $force = false;
    private static float $time = 0.0;
    /** @var list<float> */
    private static array $queue = [];

    public static function reset(): void
    {
        self::$force = false;
        self::$time = 0.0;
        self::$queue = [];
    }

    public static function freeze(float $time): void
    {
        self::$force = true;
        self::$time = $time;
        self::$queue = [];
    }

    public static function queue(float ...$times): void
    {
        self::$force = true;
        self::$queue = array_values($times);
        if ($times !== []) {
            self::$time = $times[count($times) - 1];
        }
    }

    /**
     * @return ($asFloat is true ? float : string)
     * @psalm-return ($asFloat is true ? float : string)
     * @phpstan-return ($asFloat is true ? float : string)
     */
    public static function microtime(bool $asFloat = false): string|float
    {
        if (!self::$force) {
            return \microtime($asFloat);
        }

        $time = self::$queue !== [] ? array_shift(self::$queue) : self::$time;

        if ($asFloat) {
            return $time;
        }

        $seconds = (int) $time;
        $micro = $time - (float) $seconds;

        return sprintf('%.8f %d', $micro, $seconds);
    }
}

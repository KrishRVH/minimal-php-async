<?php

declare(strict_types=1);

namespace Krvh\MinimalPhpAsync\Tests\Support;

final class SleepStub
{
    private static bool $force = false;
    private static ?int $lastMicroseconds = null;
    private static int $callCount = 0;

    public static function reset(): void
    {
        self::$force = false;
        self::$lastMicroseconds = null;
        self::$callCount = 0;
    }

    public static function force(bool $force = true): void
    {
        self::$force = $force;
    }

    public static function usleep(int $microseconds): void
    {
        self::$callCount++;
        self::$lastMicroseconds = $microseconds;

        if (self::$force) {
            return;
        }

        \usleep($microseconds);
    }

    public static function callCount(): int
    {
        return self::$callCount;
    }

    public static function lastMicroseconds(): ?int
    {
        return self::$lastMicroseconds;
    }
}

<?php

/**
 * @phan-file-suppress PhanPossiblyNullTypeArgumentInternal
 * @phan-file-suppress PhanTypeMismatchArgumentNullableInternal
 */

declare(strict_types=1);

namespace Krvh\MinimalPhpAsync\Tests\Support;

// phpcs:disable SlevomatCodingStandard.PHP.DisallowReference

final class SelectStub
{
    private static bool $force = false;
    private static int|false $result = 0;
    /** @var array<int, resource>|null */
    private static ?array $read = null;
    /** @var array<int, resource>|null */
    private static ?array $write = null;
    /** @var array<int, resource>|null */
    private static ?array $except = null;
    private static ?int $lastSeconds = null;
    private static ?int $lastMicroseconds = null;

    public static function reset(bool $keepTimeout = false): void
    {
        self::$force = false;
        self::$result = 0;
        self::$read = null;
        self::$write = null;
        self::$except = null;
        if (!$keepTimeout) {
            self::$lastSeconds = null;
            self::$lastMicroseconds = null;
        }
    }

    /**
     * @param array<int, resource>|null $read
     * @param array<int, resource>|null $write
     * @param array<int, resource>|null $except
     */
    public static function forceResult(
        int|false $result,
        ?array $read = null,
        ?array $write = null,
        ?array $except = null,
    ): void {
        self::$force = true;
        self::$result = $result;
        self::$read = $read;
        self::$write = $write;
        self::$except = $except;
    }

    /**
     * @param array<int, resource>|null $read
     * @param array<int, resource>|null $write
     * @param array<int, resource>|null $except
     */
    public static function streamSelect(
        ?array &$read,
        ?array &$write,
        ?array &$except,
        ?int $seconds,
        ?int $microseconds,
    ): int|false {
        self::$lastSeconds = $seconds;
        self::$lastMicroseconds = $microseconds;

        if (!self::$force) {
            /** @psalm-suppress ReferenceConstraintViolation */
            return \stream_select($read, $write, $except, $seconds, $microseconds);
        }

        if (self::$read !== null) {
            $read = self::$read;
        }
        if (self::$write !== null) {
            $write = self::$write;
        }
        if (self::$except !== null) {
            $except = self::$except;
        }

        $result = self::$result;
        self::reset(true);
        return $result;
    }

    /**
     * @return array{seconds: int|null, microseconds: int|null}
     */
    public static function lastTimeout(): array
    {
        return ['seconds' => self::$lastSeconds, 'microseconds' => self::$lastMicroseconds];
    }
}
// phpcs:enable

<?php

/**
 * @phan-file-suppress PhanTypeMismatchDeclaredReturnNullable
 */

declare(strict_types=1);

namespace Krvh\MinimalPhpAsync\Tests\Support;

use Fiber;
use Krvh\MinimalPhpAsync\IoWatcher;
use Krvh\MinimalPhpAsync\Timer;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;

final class TestHelper
{
    /**
     * @param class-string $class
     * @param array<int, mixed> $args
     */
    public static function callPrivateStatic(string $class, string $method, array $args = []): mixed
    {
        $ref = new ReflectionMethod($class, $method);
        return $ref->invokeArgs(null, $args);
    }

    /**
     * @param array<int, mixed> $args
     */
    public static function callPrivate(object $object, string $method, array $args = []): mixed
    {
        $ref = new ReflectionMethod($object, $method);
        return $ref->invokeArgs($object, $args);
    }

    public static function getProperty(object $object, string $property): mixed
    {
        $ref = new ReflectionProperty($object, $property);
        return $ref->getValue($object);
    }

    /**
     * @param array<int, Fiber|IoWatcher|Timer> $value
     */
    public static function setProperty(object $object, string $property, array $value): void
    {
        $ref = new ReflectionProperty($object, $property);
        $ref->setValue($object, $value);
    }

    public static function setPropertyValue(object $object, string $property, mixed $value): void
    {
        $ref = new ReflectionProperty($object, $property);
        $ref->setValue($object, $value);
    }

    public static function newSuspendedFiber(?callable $onResume = null): Fiber
    {
        $fiber = new Fiber(static function () use ($onResume): void {
            if ($onResume === null) {
                Fiber::suspend();
                return;
            }

            $onResume(Fiber::suspend());
        });
        $fiber->start();
        return $fiber;
    }

    /**
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    public static function withTimeout(int $seconds, callable $fn): mixed
    {
        if (
            !function_exists('pcntl_signal')
            || !function_exists('pcntl_alarm')
            || !function_exists('pcntl_async_signals')
        ) {
            return $fn();
        }

        $prevHandler = function_exists('pcntl_signal_get_handler')
            ? pcntl_signal_get_handler(SIGALRM)
            : SIG_DFL;
        if (!is_int($prevHandler) && !is_callable($prevHandler)) {
            $prevHandler = SIG_DFL;
        }
        $prevAsync = pcntl_async_signals(true);

        pcntl_signal(SIGALRM, static function (): never {
            throw new TestTimeoutException('Test timed out');
        });
        pcntl_alarm($seconds);

        try {
            return $fn();
        } finally {
            pcntl_alarm(0);
            pcntl_signal(SIGALRM, $prevHandler);
            pcntl_async_signals($prevAsync);
        }
    }

    public static function closeResource(mixed $resource): void
    {
        if (is_resource($resource)) {
            fclose($resource);
        }
    }

    /**
     * @psalm-return resource
     * @phpstan-return resource
     */
    public static function openStream(string $uri, string $mode = 'r+'): mixed
    {
        $stream = fopen($uri, $mode);
        if (!is_resource($stream)) {
            throw new RuntimeException("Failed to open stream: {$uri}");
        }

        return $stream;
    }

    /**
     * @psalm-return resource
     * @phpstan-return resource
     */
    public static function openTempStream(string $mode = 'r+'): mixed
    {
        return self::openStream('php://temp', $mode);
    }

    public static function newTerminatedFiber(): Fiber
    {
        $fiber = new Fiber(static function (): void {
        });
        $fiber->start();
        return $fiber;
    }
}

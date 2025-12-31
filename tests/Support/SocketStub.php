<?php

/**
 * @phan-file-suppress PhanUnusedPublicFinalMethodParameter
 */

declare(strict_types=1);

namespace Krvh\MinimalPhpAsync\Tests\Support;

// phpcs:disable SlevomatCodingStandard.PHP.DisallowReference

final class SocketStub
{
    private static int $counter = 0;
    private static ?string $nextResponse = null;
    private static bool $failNext = false;
    private static bool $silentFailNext = false;
    private static ?string $lastAddress = null;
    /** @var array<array-key, mixed>|null */
    private static ?array $lastContextOptions = null;

    public static function reset(): void
    {
        self::$counter = 0;
        self::$nextResponse = null;
        self::$failNext = false;
        self::$silentFailNext = false;
        self::$lastAddress = null;
        self::$lastContextOptions = null;
        FakeSocketStream::reset();
    }

    public static function queueResponse(string $response): void
    {
        self::$nextResponse = $response;
        self::$failNext = false;
        FakeSocketStream::register();
    }

    public static function queueFailure(): void
    {
        self::$failNext = true;
        self::$nextResponse = null;
    }

    public static function queueSilentFailure(): void
    {
        self::$silentFailNext = true;
        self::$failNext = false;
        self::$nextResponse = null;
    }

    public static function lastRequest(): ?string
    {
        if (FakeSocketStream::$requests === []) {
            return null;
        }

        /** @var array<string, string> $requests */
        $requests = FakeSocketStream::$requests;
        $key = array_key_last($requests);
        if ($key === null) {
            return null;
        }

        return $requests[$key];
    }

    public static function lastAddress(): ?string
    {
        return self::$lastAddress;
    }

    /**
     * @return array<array-key, mixed>|null
     */
    public static function lastContextOptions(): ?array
    {
        return self::$lastContextOptions;
    }

    /** @psalm-suppress UnusedParam */
    public static function streamSocketClient(
        string $address,
        int &$errno = 0,
        string &$errstr = '',
        float $timeout = 0.0,
        int $flags = STREAM_CLIENT_CONNECT,
        mixed $context = null,
    ): mixed {
        self::$lastAddress = $address;
        self::$lastContextOptions = is_resource($context) ? stream_context_get_options($context) : null;

        if (self::$silentFailNext) {
            self::$silentFailNext = false;
            return false;
        }

        if (self::$failNext) {
            self::$failNext = false;
            $errstr = 'Connect failed';
            $errno = 1;
            return false;
        }

        if (self::$nextResponse === null) {
            $errstr = 'No response configured';
            $errno = 1;
            return false;
        }

        $id = 'conn' . (++self::$counter);
        FakeSocketStream::$responses[$id] = self::$nextResponse;
        self::$nextResponse = null;

        $uri = FakeSocketStream::uriFor($id);
        return fopen($uri, 'r+');
    }
}
// phpcs:enable

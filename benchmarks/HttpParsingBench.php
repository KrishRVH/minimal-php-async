<?php

declare(strict_types=1);

namespace Krvh\MinimalPhpAsync\Bench;

use Krvh\MinimalPhpAsync\Async;
use PhpBench\Attributes as Bench;
use ReflectionMethod;

#[Bench\Iterations(10)]
#[Bench\Revs(1000)]
#[Bench\Warmup(2)]
#[Bench\RetryThreshold(5.0)]
#[Bench\OutputTimeUnit('microseconds')]
final class HttpParsingBench
{
    private const RAW_OK = "HTTP/1.1 200 OK\r\nX-Test: 1\r\n\r\nbody";
    private const RAW_CHUNKED = "HTTP/1.1 200 OK\r\nTransfer-Encoding: chunked\r\n\r\n"
        . "3\r\nabc\r\n0\r\n\r\n";
    private const CHUNKED_BODY = "3\r\nabc\r\n0\r\n\r\n";

    private static ?ReflectionMethod $parseResponse = null;
    private static ?ReflectionMethod $decodeChunked = null;

    public function benchParseResponse(): void
    {
        self::parseResponseMethod()->invoke(null, self::RAW_OK, 'http://example.test');
    }

    public function benchParseChunked(): void
    {
        self::parseResponseMethod()->invoke(null, self::RAW_CHUNKED, 'http://example.test');
    }

    public function benchDecodeChunked(): void
    {
        self::decodeChunkedMethod()->invoke(null, self::CHUNKED_BODY);
    }

    private static function parseResponseMethod(): ReflectionMethod
    {
        return self::$parseResponse ??= new ReflectionMethod(Async::class, 'parseResponse');
    }

    private static function decodeChunkedMethod(): ReflectionMethod
    {
        return self::$decodeChunked ??= new ReflectionMethod(Async::class, 'decodeChunked');
    }
}

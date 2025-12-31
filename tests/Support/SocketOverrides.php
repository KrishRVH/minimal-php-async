<?php

declare(strict_types=1);

namespace Krvh\MinimalPhpAsync;

use Krvh\MinimalPhpAsync\Tests\Support\SelectStub;
use Krvh\MinimalPhpAsync\Tests\Support\SocketStub;

// phpcs:disable SlevomatCodingStandard.PHP.DisallowReference

function stream_socket_client(
    string $address,
    int &$errno = 0,
    string &$errstr = '',
    float $timeout = 0.0,
    int $flags = STREAM_CLIENT_CONNECT,
    mixed $context = null,
): mixed {
    return SocketStub::streamSocketClient($address, $errno, $errstr, $timeout, $flags, $context);
}

/**
 * @param array<int, resource>|null $read
 * @param array<int, resource>|null $write
 * @param array<int, resource>|null $except
 */
function stream_select(
    ?array &$read,
    ?array &$write,
    ?array &$except,
    ?int $seconds,
    ?int $microseconds = null,
): int|false {
    return SelectStub::streamSelect($read, $write, $except, $seconds, $microseconds);
}
// phpcs:enable

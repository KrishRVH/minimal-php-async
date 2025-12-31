<?php

declare(strict_types=1);

use Krvh\MinimalPhpAsync\Async;
use PhpFuzzer\Config;

require __DIR__ . '/../vendor/autoload.php';

/** @var Config $config */

// Keep maxLen within int-safe digit ranges for php-fuzzer's ASCII int mutator.
$maxLen = max(1, strlen((string) PHP_INT_MAX) - 1);
$maxLenEnv = getenv('FUZZ_MAX_LEN');
if ($maxLenEnv !== false && $maxLenEnv !== '') {
    $maxLen = max(1, (int) $maxLenEnv);
}
$config->setMaxLen($maxLen);
$config->setAllowedExceptions([RuntimeException::class]);
$config->addDictionary(__DIR__ . '/http.dict');

$parseResponse = new ReflectionMethod(Async::class, 'parseResponse');
$decodeChunked = new ReflectionMethod(Async::class, 'decodeChunked');

$config->setTarget(
    static function (string $input) use ($parseResponse, $decodeChunked): void {
        if ($input === '') {
            $parseResponse->invoke(null, "HTTP/1.1 200 OK\r\n\r\n", 'http://example.test');
            return;
        }

        $mode = ord($input[0]) % 3;
        $body = substr($input, 1);

        if ($mode === 0) {
            $raw = "HTTP/1.1 200 OK\r\n\r\n" . $body;
            $parseResponse->invoke(null, $raw, 'http://example.test');
            return;
        }

        if ($mode === 1) {
            $raw = "HTTP/1.1 200 OK\r\nTransfer-Encoding: chunked\r\n\r\n"
                . $body;
            $parseResponse->invoke(null, $raw, 'http://example.test');
            return;
        }

        $decodeChunked->invoke(null, $body);
    },
);

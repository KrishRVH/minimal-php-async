<?php

/**
 * @phan-file-suppress PhanUnextractableAnnotation
 */

declare(strict_types=1);

namespace Krvh\MinimalPhpAsync;

use Krvh\MinimalPhpAsync\Tests\Support\SleepStub;
use Krvh\MinimalPhpAsync\Tests\Support\TimeStub;

function usleep(int $microseconds): void
{
    SleepStub::usleep($microseconds);
}

/**
 * @return ($asFloat is true ? float : string)
 * @psalm-return ($asFloat is true ? float : string)
 * @phpstan-return ($asFloat is true ? float : string)
 */
function microtime(bool $asFloat = false): string|float
{
    return TimeStub::microtime($asFloat);
}

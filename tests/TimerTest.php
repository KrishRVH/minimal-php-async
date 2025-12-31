<?php

/**
 * @phan-file-suppress PhanAccessMethodInternal
 * @phan-file-suppress PhanUnreferencedClass
 */

declare(strict_types=1);

namespace Krvh\MinimalPhpAsync\Tests;

use Krvh\MinimalPhpAsync\Tests\Support\AsyncTestCase;
use Krvh\MinimalPhpAsync\Tests\Support\TestHelper;
use Krvh\MinimalPhpAsync\Timer;

/** @psalm-suppress UnusedClass */
final class TimerTest extends AsyncTestCase
{
    public function testTimerStoresValues(): void
    {
        $fiber = TestHelper::newSuspendedFiber();
        $timer = new Timer(1.23, $fiber);

        $this->assertSame(1.23, TestHelper::getProperty($timer, 'at'));
        $this->assertSame($fiber, TestHelper::getProperty($timer, 'fiber'));
    }
}

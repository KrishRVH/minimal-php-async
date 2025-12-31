<?php

/**
 * @phan-file-suppress PhanAccessMethodInternal
 * @phan-file-suppress PhanUnreferencedClass
 */

declare(strict_types=1);

namespace Krvh\MinimalPhpAsync\Tests;

use Krvh\MinimalPhpAsync\IoWatcher;
use Krvh\MinimalPhpAsync\Tests\Support\AsyncTestCase;
use Krvh\MinimalPhpAsync\Tests\Support\TestHelper;

/** @psalm-suppress UnusedClass */
final class IoWatcherTest extends AsyncTestCase
{
    public function testWithCreatesUpdatedClone(): void
    {
        $stream = TestHelper::openTempStream();

        $fiber = TestHelper::newSuspendedFiber();
        $watcher = new IoWatcher($stream, $fiber, 'buf', 1);
        $next = $watcher->with('next', 2);

        $this->assertNotSame($watcher, $next);
        $this->assertSame('buf', TestHelper::getProperty($watcher, 'buffer'));
        $this->assertSame('next', TestHelper::getProperty($next, 'buffer'));
        $this->assertSame(1, TestHelper::getProperty($watcher, 'offsetOrMaxBytes'));
        $this->assertSame(2, TestHelper::getProperty($next, 'offsetOrMaxBytes'));

        fclose($stream);
    }

    public function testDefaultsUseZeroOffset(): void
    {
        $stream = TestHelper::openTempStream();
        $fiber = TestHelper::newSuspendedFiber();

        $watcher = new IoWatcher($stream, $fiber);
        $this->assertSame('', TestHelper::getProperty($watcher, 'buffer'));
        $this->assertSame(0, TestHelper::getProperty($watcher, 'offsetOrMaxBytes'));

        fclose($stream);
    }
}

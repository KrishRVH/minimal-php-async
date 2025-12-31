<?php

declare(strict_types=1);

namespace Krvh\MinimalPhpAsync\Tests\Support;

use Krvh\MinimalPhpAsync\Async;
use Override;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

abstract class AsyncTestCase extends TestCase
{
    #[Override]
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/SocketOverrides.php';
        require_once __DIR__ . '/TimeOverrides.php';
    }

    #[Override]
    protected function tearDown(): void
    {
        $ref = new ReflectionProperty(Async::class, 'instance');
        $ref->setValue(null, null);

        SocketStub::reset();
        SelectStub::reset();
        SleepStub::reset();
        TimeStub::reset();

        parent::tearDown();
    }
}

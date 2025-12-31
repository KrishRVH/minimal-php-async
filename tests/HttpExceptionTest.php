<?php

/**
 * @phan-file-suppress PhanUnreferencedClass
 */

declare(strict_types=1);

namespace Krvh\MinimalPhpAsync\Tests;

use Krvh\MinimalPhpAsync\HttpException;
use Krvh\MinimalPhpAsync\Tests\Support\AsyncTestCase;

/** @psalm-suppress UnusedClass */
final class HttpExceptionTest extends AsyncTestCase
{
    public function testHttpExceptionCarriesStatus(): void
    {
        $ex = new HttpException(404, 'http://example.test');

        $this->assertSame(404, $ex->status);
        $this->assertSame('HTTP 404: http://example.test', $ex->getMessage());
        $this->assertSame(404, $ex->getCode());
    }
}

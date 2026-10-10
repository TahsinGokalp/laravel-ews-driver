<?php

namespace TahsinGokalp\LaravelEwsDriver\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TahsinGokalp\LaravelEwsDriver\Exceptions\EwsException;

class EwsExceptionTest extends TestCase
{
    public function test_it_is_a_regular_exception(): void
    {
        $exception = new EwsException('failed');

        $this->assertInstanceOf(\Exception::class, $exception);
        $this->assertSame('failed', $exception->getMessage());
    }
}

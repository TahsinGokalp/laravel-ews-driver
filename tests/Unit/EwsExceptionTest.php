<?php

namespace TahsinGokalp\LaravelEwsDriver\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use TahsinGokalp\LaravelEwsDriver\Exceptions\EwsException;

class EwsExceptionTest extends TestCase
{
    public function test_it_is_a_symfony_transport_exception(): void
    {
        $exception = new EwsException('failed');

        $this->assertInstanceOf(TransportExceptionInterface::class, $exception);
        $this->assertSame('failed', $exception->getMessage());
    }
}

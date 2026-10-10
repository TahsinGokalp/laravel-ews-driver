<?php

namespace TahsinGokalp\LaravelEwsDriver\Tests\Feature;

use Illuminate\Mail\MailManager;
use ReflectionProperty;
use TahsinGokalp\LaravelEwsDriver\Config\EwsDriverConfig;
use TahsinGokalp\LaravelEwsDriver\Tests\TestCase;
use TahsinGokalp\LaravelEwsDriver\Transport\ExchangeTransport;

class EwsServiceProviderTest extends TestCase
{
    public function test_it_registers_the_exchange_transport(): void
    {
        $transport = $this->app->make(MailManager::class)->mailer('exchange')->getSymfonyTransport();

        $this->assertInstanceOf(ExchangeTransport::class, $transport);
        $this->assertSame('exchange', (string) $transport);
    }

    public function test_it_builds_driver_config_from_mailer_config(): void
    {
        $config = $this->driverConfig();

        $this->assertSame('sender@example.com', $config->from);
        $this->assertSame('webmail.example.com', $config->host);
        $this->assertSame('example-user', $config->username);
        $this->assertSame('example-password', $config->password);
        $this->assertSame('Exchange2010', $config->version);
        $this->assertSame('SendAndSaveCopy', $config->messageDispositionType);
    }

    public function test_it_uses_global_from_address_as_sender(): void
    {
        $this->app['config']->set('mail.from.address', 'noreply@example.com');

        $this->assertSame('noreply@example.com', $this->driverConfig()->from);
    }

    public function test_mail_facade_resolves_exchange_as_default_mailer(): void
    {
        $this->app['config']->set('mail.default', 'exchange');

        $this->assertInstanceOf(
            ExchangeTransport::class,
            $this->app->make('mailer')->getSymfonyTransport()
        );
    }

    private function driverConfig(): EwsDriverConfig
    {
        $transport = $this->app->make(MailManager::class)->mailer('exchange')->getSymfonyTransport();

        return (new ReflectionProperty($transport, 'config'))->getValue($transport);
    }
}

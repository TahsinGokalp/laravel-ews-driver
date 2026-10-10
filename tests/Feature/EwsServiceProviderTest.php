<?php

namespace TahsinGokalp\LaravelEwsDriver\Tests\Feature;

use Illuminate\Contracts\Support\DeferrableProvider;
use Illuminate\Mail\MailManager;
use Illuminate\Mail\MailServiceProvider;
use ReflectionProperty;
use TahsinGokalp\LaravelEwsDriver\Config\EwsDriverConfig;
use TahsinGokalp\LaravelEwsDriver\EwsServiceProvider;
use TahsinGokalp\LaravelEwsDriver\Exceptions\EwsException;
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

    public function test_optional_values_fall_back_to_defaults(): void
    {
        $this->app['config']->set('mail.mailers.exchange', [
            'transport' => 'exchange',
            'host' => 'webmail.example.com',
            'username' => 'example-user',
            'password' => 'example-password',
        ]);

        $config = $this->driverConfig();

        $this->assertSame('Exchange2013', $config->version);
        $this->assertSame('SendAndSaveCopy', $config->messageDispositionType);
        $this->assertFalse($config->verifySsl);
    }

    public function test_it_reads_verify_ssl_from_mailer_config(): void
    {
        $this->app['config']->set('mail.mailers.exchange.verifySsl', true);

        $this->assertTrue($this->driverConfig()->verifySsl);
    }

    public function test_global_from_address_is_optional(): void
    {
        $this->app['config']->set('mail.from.address', null);

        $this->assertNull($this->driverConfig()->from);
    }

    public function test_missing_required_config_throws_a_clear_exception(): void
    {
        $this->app['config']->set('mail.mailers.exchange.host', null);

        $this->expectException(EwsException::class);
        $this->expectExceptionMessage('The exchange mailer requires the "host" config value to be set.');

        $this->app->make(MailManager::class)->mailer('exchange');
    }

    public function test_it_does_not_replace_laravels_mail_services(): void
    {
        $provider = new EwsServiceProvider($this->app);

        $this->assertNotInstanceOf(MailServiceProvider::class, $provider);
        $this->assertNotInstanceOf(DeferrableProvider::class, $provider);
        $this->assertSame($this->app->make('mail.manager'), $this->app->make(MailManager::class));
    }

    public function test_it_extends_a_mail_manager_resolved_before_boot(): void
    {
        $this->app->forgetInstance('mail.manager');
        $manager = $this->app->make('mail.manager');

        (new EwsServiceProvider($this->app))->boot();

        $this->assertInstanceOf(ExchangeTransport::class, $manager->mailer('exchange')->getSymfonyTransport());
    }

    public function test_other_mailers_keep_working(): void
    {
        $this->app['config']->set('mail.mailers.array', ['transport' => 'array']);

        $this->assertSame('array', (string) $this->app->make(MailManager::class)->mailer('array')->getSymfonyTransport());
    }

    private function driverConfig(): EwsDriverConfig
    {
        $transport = $this->app->make(MailManager::class)->mailer('exchange')->getSymfonyTransport();

        return (new ReflectionProperty($transport, 'config'))->getValue($transport);
    }
}

<?php

namespace TahsinGokalp\LaravelEwsDriver\Tests;

use Illuminate\Mail\MailServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use TahsinGokalp\LaravelEwsDriver\EwsServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        // Laravel apps load MailServiceProvider by default; Testbench does not.
        return [MailServiceProvider::class, EwsServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('mail.from.address', 'sender@example.com');
        $app['config']->set('mail.mailers.exchange', [
            'transport' => 'exchange',
            'host' => 'webmail.example.com',
            'username' => 'example-user',
            'password' => 'example-password',
            'version' => 'Exchange2010',
            'messageDispositionType' => 'SendAndSaveCopy',
        ]);
    }
}

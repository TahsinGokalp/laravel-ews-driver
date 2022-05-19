<?php

namespace TahsinGokalp\LaravelEwsDriver;

use Illuminate\Mail\MailManager;
use Illuminate\Mail\MailServiceProvider;
use TahsinGokalp\LaravelEwsDriver\Config\EwsDriverConfig;
use TahsinGokalp\LaravelEwsDriver\Transport\ExchangeTransport;

class EwsServiceProvider extends MailServiceProvider
{
    public function register(): void
    {
        parent::register();

        $this->app->afterResolving(MailManager::class, function (MailManager $mailManager) {
            $mailManager->extend('exchange', function ($config) {
                $driverConfig = new EwsDriverConfig();
                $driverConfig->from = config('mail.from.address');
                $driverConfig->host = $config['host'];
                $driverConfig->username = $config['username'];
                $driverConfig->password = $config['password'];
                $driverConfig->version = $config['version'];
                $driverConfig->messageDispositionType = $config['messageDispositionType'];

                return new ExchangeTransport($driverConfig);
            });
        });
    }
}

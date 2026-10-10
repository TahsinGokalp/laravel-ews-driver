<?php

namespace TahsinGokalp\LaravelEwsDriver;

use Illuminate\Mail\MailManager;
use Illuminate\Support\ServiceProvider;
use TahsinGokalp\LaravelEwsDriver\Config\EwsDriverConfig;
use TahsinGokalp\LaravelEwsDriver\Transport\ExchangeTransport;

class EwsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->callAfterResolving('mail.manager', function (MailManager $mailManager) {
            $mailManager->extend('exchange', function (array $config) {
                return new ExchangeTransport(
                    EwsDriverConfig::fromArray($config, $this->app['config']->get('mail.from.address'))
                );
            });
        });
    }
}

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
                return new ExchangeTransport(
                    EwsDriverConfig::fromArray($config, config('mail.from.address'))
                );
            });
        });
    }
}

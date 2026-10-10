<?php

namespace TahsinGokalp\LaravelEwsDriver\Tests\Fakes;

use jamesiarmes\PhpEws\Client;
use TahsinGokalp\LaravelEwsDriver\Config\EwsDriverConfig;
use TahsinGokalp\LaravelEwsDriver\Transport\ExchangeTransport;

/**
 * Exchange transport that hands out a pre-built client instead of
 * connecting to a real EWS host.
 */
class FakeExchangeTransport extends ExchangeTransport
{
    public function __construct(EwsDriverConfig $config, private Client $client)
    {
        parent::__construct($config);
    }

    protected function createClient(): Client
    {
        return $this->client;
    }
}

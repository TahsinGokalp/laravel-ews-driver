<?php

namespace TahsinGokalp\LaravelEwsDriver\Config;

use jamesiarmes\PhpEws\Client;
use jamesiarmes\PhpEws\Enumeration\MessageDispositionType;
use TahsinGokalp\LaravelEwsDriver\Exceptions\EwsException;

class EwsDriverConfig
{
    public const REQUIRED_KEYS = ['host', 'username', 'password'];

    public const MESSAGE_DISPOSITION_TYPES = [
        MessageDispositionType::SAVE_ONLY,
        MessageDispositionType::SEND_AND_SAVE_COPY,
        MessageDispositionType::SEND_ONLY,
    ];

    /**
     * Fallback sender address, used when the message has no "from" address.
     */
    public ?string $from = null;
    public string $host;
    public string $username;
    public string $password;
    public string $version = Client::VERSION_2013;
    public string $messageDispositionType = MessageDispositionType::SEND_AND_SAVE_COPY;
    public bool $verifySsl = false;

    /**
     * Build the driver config from a Laravel mailer config array.
     *
     * @param array       $config
     * @param string|null $from
     *
     * @throws EwsException
     *
     * @return static
     */
    public static function fromArray(array $config, ?string $from = null): static
    {
        foreach (self::REQUIRED_KEYS as $key) {
            if (!isset($config[$key]) || !is_string($config[$key]) || $config[$key] === '') {
                throw new EwsException("The exchange mailer requires the \"$key\" config value to be set.");
            }
        }

        $driverConfig = new static();
        $driverConfig->from = $from !== '' ? $from : null;
        $driverConfig->host = $config['host'];
        $driverConfig->username = $config['username'];
        $driverConfig->password = $config['password'];

        if (!empty($config['version'])) {
            $driverConfig->version = $config['version'];
        }

        if (!empty($config['messageDispositionType'])) {
            if (!in_array($config['messageDispositionType'], self::MESSAGE_DISPOSITION_TYPES, true)) {
                throw new EwsException(sprintf(
                    'Invalid exchange "messageDispositionType" config value "%s". Expected one of: %s.',
                    $config['messageDispositionType'],
                    implode(', ', self::MESSAGE_DISPOSITION_TYPES)
                ));
            }

            $driverConfig->messageDispositionType = $config['messageDispositionType'];
        }

        if (isset($config['verifySsl'])) {
            $driverConfig->verifySsl = filter_var($config['verifySsl'], FILTER_VALIDATE_BOOLEAN);
        }

        return $driverConfig;
    }
}

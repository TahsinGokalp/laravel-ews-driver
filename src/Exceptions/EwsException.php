<?php

namespace TahsinGokalp\LaravelEwsDriver\Exceptions;

use Symfony\Component\Mailer\Exception\TransportException;

/**
 * Extends Symfony's TransportException so failover and round-robin
 * mailers move on to the next transport when Exchange fails.
 */
class EwsException extends TransportException
{
}

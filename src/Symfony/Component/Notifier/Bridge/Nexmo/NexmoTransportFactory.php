<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\Notifier\Bridge\Nexmo;

use Symfony\Component\Notifier\Exception\UnsupportedSchemeException;
use Symfony\Component\Notifier\Transport\AbstractTransportFactory;
use Symfony\Component\Notifier\Transport\Dsn;

/**
 * @author Fabien Potencier <fabien@symfony.com>
 */
final class NexmoTransportFactory extends AbstractTransportFactory
{
    public function create(Dsn $dsn): NexmoTransport
    {
        $scheme = $dsn->getScheme();

        if ('nexmo' !== $scheme) {
            throw new UnsupportedSchemeException($dsn, 'nexmo', $this->getSupportedSchemes());
        }

        $apiKey = $this->getUser($dsn);
        $apiSecret = $this->getPassword($dsn);
        $from = $dsn->getRequiredOption('from');
        $host = 'default' === $dsn->getHost() ? null : $dsn->getHost();
        $port = $dsn->getPort();

        return (new NexmoTransport($apiKey, $apiSecret, $from, $this->client, $this->dispatcher))->setHost($host)->setPort($port);
    }

    protected function getSupportedSchemes(): array
    {
        return ['nexmo'];
    }
}

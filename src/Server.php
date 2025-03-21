<?php

declare(strict_types=1);

namespace Sentry\Agent;

use React\Socket\ConnectionInterface;
use React\Socket\SocketServer;

/**
 * @internal
 */
class Server
{
    /**
     * @var string
     */
    private $uri;

    /**
     * @var callable(\Throwable): void
     */
    private $onServerError;

    /**
     * @var callable(\Throwable): void
     */
    private $onConnectionError;

    /**
     * @var callable(Envelope): void
     */
    private $onEnvelopeReceived;

    /**
     * @param string                     $uri                the address the server should listen on
     * @param callable(\Throwable): void $onServerError      called when the server encounters an error
     * @param callable(\Throwable): void $onConnectionError  called when the connection encounters an error
     * @param callable(Envelope): void   $onEnvelopeReceived called when an envelope is received
     */
    public function __construct(
        string $uri,
        callable $onServerError,
        callable $onConnectionError,
        callable $onEnvelopeReceived
    ) {
        $this->uri = $uri;
        $this->onServerError = $onServerError;
        $this->onConnectionError = $onConnectionError;
        $this->onEnvelopeReceived = $onEnvelopeReceived;
    }

    public function run(): void
    {
        $socket = new SocketServer($this->uri);

        $socket->on('connection', function (ConnectionInterface $connection): void {
            $messageLength = 0;
            $connectionBuffer = '';

            $connection->on('data', function (string $chunk) use (&$connectionBuffer, &$messageLength) {
                $connectionBuffer .= $chunk;

                while (\strlen($connectionBuffer) >= 4) {
                    if ($messageLength === 0) {
                        $unpackedHeader = unpack('N', substr($connectionBuffer, 0, 4));

                        if ($unpackedHeader === false) {
                            throw new \RuntimeException('Unable to unpack the header received from the client.');
                        }

                        $messageLength = $unpackedHeader[1];
                    }

                    if (\strlen($connectionBuffer) < $messageLength) {
                        break;
                    }

                    \call_user_func($this->onEnvelopeReceived, new Envelope(substr($connectionBuffer, 4, $messageLength)));

                    $connectionBuffer = substr($connectionBuffer, $messageLength);
                    $messageLength = 0;
                }
            });

            $connection->on('end', function () {});

            $connection->on('error', function (\Throwable $exception) use (&$connectionBuffer) {
                \call_user_func($this->onConnectionError, $exception);

                $connectionBuffer = '';
            });

            $connection->on('close', function () use (&$connectionBuffer) {
                $connectionBuffer = '';
            });
        });

        $socket->on('error', function (\Throwable $exception) {
            \call_user_func($this->onServerError, $exception);
        });
    }
}

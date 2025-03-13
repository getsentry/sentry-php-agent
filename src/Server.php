<?php

declare(strict_types=1);

namespace Sentry\Agent;

use React\Socket\ConnectionInterface;
use React\Socket\SocketServer;

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
            $connectionBuffer = '';

            $connection->on('data', function (string $chunk) use (&$connectionBuffer) {
                $connectionBuffer .= $chunk;
            });

            $connection->on('end', function () use (&$connectionBuffer) {
                // @TODO: Right now the envelope is never checked for anything, the Envelope class might throw an exception if it fails to parse out the header in the future?
                //        Parsing the header also allows us to have a bit more information and we could use it's information to accept envelopes destined for multiple DSNs instead of just the configured one.

                // @TODO: We should probably also check if the envelope is empty and if so, we should not call the onEnvelopeReceived callback.

                // @TODO: We should also see what happens if a client send multiple envelopes in a single "request" and if we need to handle that.
                //        This would save a client needing to build up a TCP connection for every envelope instead of re-using an open socket.

                \call_user_func($this->onEnvelopeReceived, new Envelope($connectionBuffer));
            });

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

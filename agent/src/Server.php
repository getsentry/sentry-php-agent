<?php

declare(strict_types=1);

namespace Sentry\Agent;

use React\Socket\ConnectionInterface;
use React\Socket\SocketServer;
use Sentry\Agent\Exceptions\MalformedEnvelope;

/**
 * @internal
 */
class Server
{
    /**
     * Maximum envelope size in bytes (200MB as per Sentry envelope size limits).
     *
     * @see https://develop.sentry.dev/sdk/data-model/envelopes/#size-limits
     */
    private const MAX_ENVELOPE_SIZE = 200 * 1024 * 1024;

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

            $connection->on('data', function (string $chunk) use ($connection, &$connectionBuffer, &$messageLength) {
                $connectionBuffer .= $chunk;

                while (\strlen($connectionBuffer) >= 4) {
                    if ($messageLength === 0) {
                        $unpackedHeader = unpack('N', substr($connectionBuffer, 0, 4));

                        if ($unpackedHeader === false) {
                            throw new \RuntimeException('Unable to unpack the header received from the client.');
                        }

                        // The message length includes the 4 bytes of the header itself
                        $messageLength = $unpackedHeader[1];

                        if ($messageLength - 4 > self::MAX_ENVELOPE_SIZE) {
                            \call_user_func($this->onConnectionError, new \RuntimeException(
                                sprintf('Envelope size of %d bytes exceeds maximum allowed size of %d bytes.', $messageLength - 4, self::MAX_ENVELOPE_SIZE)
                            ));

                            $connection->close();

                            return;
                        }
                    }

                    if (\strlen($connectionBuffer) < $messageLength) {
                        break;
                    }

                    try {
                        \call_user_func($this->onEnvelopeReceived, Envelope::fromString(substr($connectionBuffer, 4, $messageLength - 4)));
                    } catch (MalformedEnvelope $e) {
                        \call_user_func($this->onConnectionError, $e);
                    }

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

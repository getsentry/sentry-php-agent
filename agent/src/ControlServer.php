<?php

declare(strict_types=1);

namespace Sentry\Agent;

use Psr\Http\Message\ServerRequestInterface;
use React\EventLoop\Loop;
use React\Http\HttpServer;
use React\Http\Message\Response;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use React\Socket\SocketServer;

/**
 * @internal
 */
class ControlServer
{
    /**
     * @var string
     */
    private $uri;

    /**
     * @var EnvelopeQueue
     */
    private $queue;

    /**
     * @var SocketServer|null
     */
    private $socket;

    public function __construct(string $uri, EnvelopeQueue $queue)
    {
        $this->uri = $uri;
        $this->queue = $queue;
    }

    public function run(): void
    {
        $httpServer = new HttpServer(function (ServerRequestInterface $request) {
            $path = $request->getUri()->getPath();

            if ($path === '/status') {
                return $this->handleStatus();
            }

            if ($path === '/drain') {
                return $this->handleDrain();
            }

            return new Response(404, ['Content-Type' => 'text/plain'], 'Not Found');
        });

        $this->socket = new SocketServer($this->uri);

        $httpServer->listen($this->socket);
    }

    /**
     * Stops the control server.
     */
    public function close(): void
    {
        if ($this->socket !== null) {
            $this->socket->close();
            $this->socket = null;
        }
    }

    /**
     * Returns the current queue status.
     */
    private function handleStatus(): Response
    {
        $body = json_encode([
            'queue_size' => \count($this->queue),
        ]);

        return new Response(200, ['Content-Type' => 'application/json'], $body !== false ? $body : '{}');
    }

    /**
     * Waits for the queue to drain and returns when empty.
     *
     * @return PromiseInterface<Response>
     */
    private function handleDrain(): PromiseInterface
    {
        $deferred = new Deferred();

        $checkInterval = 0.05; // 50ms

        $check = function () use (&$check, $deferred, $checkInterval): void {
            if (\count($this->queue) === 0) {
                $deferred->resolve(new Response(200, ['Content-Type' => 'text/plain'], 'ok'));

                return;
            }

            Loop::addTimer($checkInterval, $check);
        };

        Loop::futureTick($check);

        return $deferred->promise();
    }
}

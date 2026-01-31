<?php

declare(strict_types=1);

namespace Sentry\Agent\Transport;

use Psr\Log\LoggerInterface;
use Sentry\HttpClient\HttpClientInterface;
use Sentry\HttpClient\Request;
use Sentry\HttpClient\Response;
use Sentry\Options;

class AgentClient implements HttpClientInterface
{
    /**
     * The maximum message size that can be sent (2^32 - 1 bytes).
     */
    private const MAX_MESSAGE_SIZE = 4294967295;

    /**
     * @var string
     */
    private $host;

    /**
     * @var int
     */
    private $port;

    /**
     * @var resource|null
     */
    private $socket;

    /**
     * @var HttpClientInterface|null
     */
    private $fallbackTransport;

    /**
     * @var LoggerInterface|null
     */
    private $logger;

    /**
     * @var int
     */
    private $connectTimeoutMs;

    /**
     * @var int
     */
    private $socketTimeoutMs;

    public function __construct(
        string $host = '127.0.0.1',
        int $port = 5148,
        ?HttpClientInterface $fallbackTransport = null,
        ?LoggerInterface $logger = null,
        int $connectTimeoutMs = 10,
        int $socketTimeoutMs = 50
    ) {
        $this->host = $host;
        $this->port = $port;
        $this->fallbackTransport = $fallbackTransport;
        $this->logger = $logger;
        $this->connectTimeoutMs = $connectTimeoutMs;
        $this->socketTimeoutMs = $socketTimeoutMs;
    }

    public function __destruct()
    {
        $this->disconnect();
    }

    /**
     * @phpstan-assert-if-true resource $this->socket
     */
    private function connect(): bool
    {
        if ($this->socket !== null) {
            return true;
        }

        $socket = @fsockopen($this->host, $this->port, $errorNo, $errorMsg, $this->connectTimeoutMs / 1000);

        if ($socket === false) {
            if ($this->logger !== null) {
                $this->logger->warning('Failed to connect to the Sentry Agent at {host}:{port}: [{errorNo}] {errorMsg}', [
                    'host' => $this->host,
                    'port' => $this->port,
                    'errorNo' => $errorNo,
                    'errorMsg' => $errorMsg,
                ]);
            }

            return false;
        }

        // Set a timeout for the socket to prevent blocking if the agent becomes unresponsive
        stream_set_timeout($socket, 0, $this->socketTimeoutMs * 1000);

        $this->socket = $socket;

        return true;
    }

    private function disconnect(): void
    {
        if ($this->socket === null) {
            return;
        }

        fclose($this->socket);

        $this->socket = null;
    }

    private function send(string $message): bool
    {
        if (!$this->connect()) {
            if ($this->logger !== null) {
                $this->logger->error('Unable to connect to the Sentry Agent, is it running?');
            }

            return false;
        }

        $messageSize = \strlen($message) + 4;

        if ($messageSize > self::MAX_MESSAGE_SIZE) {
            if ($this->logger !== null) {
                $this->logger->error('Message size {size} bytes exceeds maximum allowed size of {max} bytes', [
                    'size' => $messageSize,
                    'max' => self::MAX_MESSAGE_SIZE,
                ]);
            }

            return false;
        }

        $contentLength = pack('N', $messageSize);

        $result = @fwrite($this->socket, $contentLength . $message);

        if ($result === false || $result === 0) {
            if ($this->logger !== null) {
                $this->logger->warning('Failed to write to the Sentry Agent socket, the agent may have disconnected');
            }

            $this->disconnect();

            return false;
        }

        return true;
    }

    public function sendRequest(Request $request, Options $options): Response
    {
        $body = $request->getStringBody();

        if (empty($body)) {
            return new Response(400, [], 'Request body is empty');
        }

        if ($this->send($body)) {
            // Since we are sending async there is no feedback so we always return an empty response
            return new Response(202, [], '');
        }

        if ($this->fallbackTransport !== null) {
            return $this->fallbackTransport->sendRequest($request, $options);
        }

        return new Response(500, [], 'Unable to send request to Sentry Agent and no fallback transport is configured');
    }
}

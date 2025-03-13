<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

Sentry\init([
    'dsn' => 'https://8b9ee30b3d9541d3b38d2fa6ddf9c73b@o447951.ingest.us.sentry.io/5572016',
    'http_client' => new class implements Sentry\HttpClient\HttpClientInterface {
        private $socket;

        public function __destruct()
        {
            $this->disconnect();
        }

        private function connect(): bool
        {
            if ($this->socket !== null) {
                return true;
            }

            $this->socket = fsockopen('127.0.0.1', 5148, $errorNo, $errorMsg, 2);

            if ($this->socket === false) {
                // @TODO: Error handling?
                $this->socket = null;

                return false;
            }

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

        private function send(string $message): void
        {
            if (!$this->connect()) {
                return;
            }

            // @TODO: Make sure we don't send more than 2^32 - 1 bytes
            $contentLength = pack('N', strlen($message) + 4);

            // @TODO: Error handling?
            fwrite($this->socket, $contentLength . $message);
        }

        public function sendRequest(Sentry\HttpClient\Request $request, Sentry\Options $options): Sentry\HttpClient\Response
        {
            if (!$request->hasStringBody()) {
                return new Sentry\HttpClient\Response(400, [], 'Request body is empty');
            }

            $this->send($request->getStringBody());

            return new Sentry\HttpClient\Response(202, [], '');
        }
    },
]);

$startTime = microtime(true);

Sentry\captureMessage('Hello world!');
Sentry\captureMessage('Hello world!');
Sentry\captureMessage('Hello world!');

$endTime = microtime(true);

$executionTime = ($endTime - $startTime) * 1000;

echo "Execution time: {$executionTime}ms\n";

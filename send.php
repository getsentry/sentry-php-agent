<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

$socket = fsockopen('127.0.0.1', 5148, $errorNo, $errorMsg, 2);

Sentry\init([
    'dsn' => 'https://5b30edd9b26444c9b185f7a0a02d3c5d@o9.ingest.observ.app/22',
    'http_client' => new class implements Sentry\HttpClient\HttpClientInterface {
        public function sendRequest(Sentry\HttpClient\Request $request, Sentry\Options $options): Sentry\HttpClient\Response
        {
            global $socket;

            fwrite($socket, $request->getStringBody());

            return new Sentry\HttpClient\Response(202, [], '');
        }
    },
]);

$startTime = microtime(true);

Sentry\captureMessage('Hello world!');

$endTime = microtime(true);

$executionTime = ($endTime - $startTime) * 1000;

echo "Execution time: {$executionTime}ms\n";

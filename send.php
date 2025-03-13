<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

$socket = fsockopen('127.0.0.1', 5148, $errorNo, $errorMsg, 2);

Sentry\init([
    'dsn' => 'https://8b9ee30b3d9541d3b38d2fa6ddf9c73b@o447951.ingest.us.sentry.io/5572016',
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

<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

Sentry\init([
    'dsn' => 'https://8b9ee30b3d9541d3b38d2fa6ddf9c73b@o447951.ingest.us.sentry.io/5572016',
    'http_client' => new Sentry\Agent\Transport\AgentClient(),
]);

$startTime = microtime(true);

Sentry\captureMessage('Hello world!');

$endTime = microtime(true);

$executionTime = ($endTime - $startTime) * 1000;

echo "Execution time: {$executionTime}ms\n";

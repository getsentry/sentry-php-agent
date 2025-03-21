<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

Sentry\init([
    'dsn' => '___PUBLIC_DSN___',
    'http_client' => new Sentry\Agent\Transport\AgentClient(),
]);

$startTime = microtime(true);

Sentry\captureMessage('Hello world!');

$endTime = microtime(true);

$executionTime = ($endTime - $startTime) * 1000;

echo "Execution time: {$executionTime}ms\n";

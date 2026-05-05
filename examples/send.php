<?php

declare(strict_types=1);

use Sentry\Agent\Transport\AgentClientBuilder;

require_once __DIR__ . '/../agent/vendor/autoload.php';

Sentry\init([
    'dsn' => getenv('SENTRY_DSN') ?: 'https://public@example.com/1',
    'http_client' => AgentClientBuilder::create()
        ->disableFallbackClient()
        ->getClient(),
]);

$startTime = microtime(true);

Sentry\captureMessage('Hello world!');

$endTime = microtime(true);

$executionTime = ($endTime - $startTime) * 1000;

echo "Execution time: {$executionTime}ms\n";

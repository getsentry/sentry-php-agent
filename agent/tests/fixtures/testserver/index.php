<?php

declare(strict_types=1);

$outputFile = __DIR__ . '/output.json';
$requestCountFile = __DIR__ . '/request_count.txt';

// We expect the path to be `/api/<project_id>/envelope/`.
// We use the project ID to determine the status code so we need to extract it from the path
$path = trim(parse_url($_SERVER['REQUEST_URI'], \PHP_URL_PATH), '/');

if (strpos($path, 'ping') === 0) {
    http_response_code(200);

    echo 'pong';

    return;
}

if (!preg_match('/api\/\d+\/envelope/', $path)) {
    http_response_code(204);

    return;
}

$projectId = (int) explode('/', $path)[1];

// Project IDs are used to control behavior:
// - 200: Normal 200 OK response
// - 429: Returns 429 with X-Sentry-Rate-Limits header for 'error' category (60 seconds)
// - Other: Use as HTTP status code directly
$status = $projectId;

// Track request count
$requestCount = file_exists($requestCountFile) ? (int) file_get_contents($requestCountFile) : 0;
$requestCount++;
file_put_contents($requestCountFile, (string) $requestCount);

$headers = getallheaders();

$rawBody = file_get_contents('php://input');

$compressed = false;

if (!isset($headers['Content-Encoding'])) {
    $body = $rawBody;
} elseif ($headers['Content-Encoding'] === 'gzip') {
    $body = gzdecode($rawBody);
    $compressed = true;
} else {
    $body = '__unable to decode body__';
}

$output = [
    'body' => $body,
    'status' => $status,
    'server' => $_SERVER,
    'headers' => $headers,
    'compressed' => $compressed,
    'request_count' => $requestCount,
];

file_put_contents($outputFile, json_encode($output, \JSON_PRETTY_PRINT));

header('X-Sentry-Test-Server-Status-Code: ' . $status);

// Return rate limit headers for project ID 429
if ($projectId === 429) {
    // Format: retry_after:categories:scope:reason_code
    // Rate limit 'error' category for 60 seconds
    header('X-Sentry-Rate-Limits: 60:error::');
}

http_response_code($status);

echo $body;

<?php

declare(strict_types=1);

use React\EventLoop\Loop;
use Sentry\Agent\Console\Log;
use Sentry\Agent\Envelope;
use Sentry\Agent\EnvelopeForwarder;
use Sentry\Agent\EnvelopeQueue;
use Sentry\Agent\Server;
use Sentry\Dsn;

require __DIR__ . '/../vendor/autoload.php';

// @TODO: "sentryagent" with a 5 in front, it's a unused "user port": https://www.iana.org/assignments/service-names-port-numbers/service-names-port-numbers.xhtml?search=5148
//        Maybe there is a better way to select a port to use but for now this is fine.
$listenAddress = '127.0.0.1:5148';

// Configures the timeout for the connection to Sentry, since we are running in an agent we can afford a little longer timeout then we would normally do in a regular PHP context
$upstreamTimeout = 2;

// Configures the amount of concurrent requests the agent is allowed to make towards Sentry
$upstreamConcurrency = 10;

// How many envelopes we want to keep in memory before we start dropping them
$queueLimit = 1000;

// The DSN where we are forwarding the envelopes to
$dsn = Dsn::createFromString('https://8b9ee30b3d9541d3b38d2fa6ddf9c73b@o447951.ingest.us.sentry.io/5572016');

Log::info("Starting Sentry agent, listening on {$listenAddress}...");

$forwarder = new EnvelopeForwarder(
    $dsn,
    $upstreamTimeout,
    function (Psr\Http\Message\ResponseInterface $response) {
        if ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300) {
            Log::info('Envelope sent successfully.');
        } else {
            Log::error("Envelope send error: {$response->getStatusCode()} {$response->getReasonPhrase()}");
        }
    },
    function (Throwable $exception) {
        Log::error("Envelope send error: {$exception->getMessage()}");
    }
);

$queue = new EnvelopeQueue(
    $upstreamConcurrency,
    $queueLimit,
    function (Envelope $envelope) use ($forwarder) {
        return $forwarder->forward($envelope);
    }
);

$server = new Server(
    $listenAddress,
    function (Throwable $exception) {
        Log::error("Server error: {$exception->getMessage()}");
    },
    function (Throwable $exception) {
        Log::error("Incoming connection error: {$exception->getMessage()}");
    },
    function (Envelope $envelope) use ($queue) {
        Log::info('Envelope received, forwarding to Sentry...');

        $queue->enqueue($envelope);
    }
);

$server->run();

Loop::run();

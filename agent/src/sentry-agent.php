<?php

declare(strict_types=1);

use React\EventLoop\Loop;
use Sentry\Agent\Console\Log;
use Sentry\Agent\Envelope;
use Sentry\Agent\EnvelopeForwarder;
use Sentry\Agent\EnvelopeQueue;
use Sentry\Agent\Server;

if (class_exists('Phar') && Phar::running(false) !== '') {
    // If running the .phar directly from ./vendor/bin/, we don't want to use $_composer_autoload_path since this
    // will load the projects files and lead to ClassNotFound errors.
    // We want to use the autoload.php from the phar itself.
    $vendorPath = __DIR__ . '/../vendor';
} else {
    // This works fine for local development or if running the phar from ./vendor/sentry/sentry-agent/bin/
    $vendorPath = $_composer_autoload_path ?? __DIR__ . '/../vendor';
}

require_once "{$vendorPath}/autoload.php";

$sentryAgentVersion = '0.0.0';

if (file_exists("{$vendorPath}/composer/installed.php")) {
    $installed = require "{$vendorPath}/composer/installed.php";

    $sentryAgentVersion = $installed['root']['pretty_version'] ?? $sentryAgentVersion;
}

function printHelp(): void
{
    global $sentryAgentVersion;

    echo <<<HELP
Sentry Agent {$sentryAgentVersion} 

Description:
  A local agent that listens for Sentry SDK requests and forwards them to the destined Sentry server.
  
Usage:
  ./sentry-agent [options]
  
Options:
  -h, --help                            Display this help output
      --listen=ADDRESS                  The address the agent listens for connections on [default: "127.0.0.1:5148"]
      --upstream-timeout=SECONDS        The timeout for the connection to Sentry (in seconds) [default: "2.0"]
      --upstream-concurrency=REQUESTS   Configures the amount of concurrent requests the agent is allowed to make towards Sentry [default: "10"]
      --queue-limit=ENVELOPES           How many envelopes we want to keep in memory before we start dropping them [default: "1000"]
  -v, --verbose                         When supplied the agent will print debug messages to the console, otherwise only errors and info messages are printed

HELP;
}

$options = getopt('h', ['listen::', 'upstream-timeout::', 'upstream-concurrency::', 'queue-limit::', 'help']);

if ($options === false) {
    Log::error('Failed to parse command line options.');

    exit(1);
}

$getOption = static function (string $key, $default = null) use ($options) {
    if (!isset($options[$key])) {
        return $default;
    }

    // If the option is provided multiple times, we take the first value.
    $value = is_array($options[$key])
        ? $options[$key][0]
        : $options[$key];

    // Options without a value are returned as false by getopt. We treat them as boolean flags and return true instead.
    return $value === false
        ? true
        : $value;
};

$firstArgument = $argv[1] ?? '-';

if ($firstArgument === 'help' || $firstArgument[0] !== '-' || $getOption('h') || $getOption('help')) {
    printHelp();

    // Showed help, exit.
    exit(0);
}

Log::setVerbose($getOption('v') || $getOption('verbose'));

$listenAddress = $getOption('listen', '127.0.0.1:5148');

$upstreamTimeout = (float) $getOption('upstream-timeout', 2.0);

if ($upstreamTimeout <= 0) {
    Log::error('The upstream timeout must be a positive number.');

    exit(1);
}

$upstreamConcurrency = (int) $getOption('upstream-concurrency', 10);

if ($upstreamConcurrency <= 0) {
    Log::error('The upstream concurrency must be a positive integer.');

    exit(1);
}

$queueLimit = (int) $getOption('queue-limit', 1000);

if ($queueLimit <= 0) {
    Log::error('The queue limit must be a positive integer and at least 1.');

    exit(1);
}

if ($queueLimit < $upstreamConcurrency) {
    Log::error('The queue limit must be at least equal to the upstream concurrency.');

    exit(1);
}

Log::info("Starting Sentry Agent ({$sentryAgentVersion}), listening on {$listenAddress} (timeout:{$upstreamTimeout}, concurrency:{$upstreamConcurrency}, queue:{$queueLimit})");

$forwarder = new EnvelopeForwarder(
    $upstreamTimeout,
    function (Psr\Http\Message\ResponseInterface $response) {
        if ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300) {
            if ($response->getStatusCode() === 200) {
                $responseBody = json_decode($response->getBody()->getContents(), true);

                $eventId = is_array($responseBody) ? $responseBody['id'] ?? null : null;

                if (!is_string($eventId)) {
                    $eventId = '<unknown>';
                }

                Log::debug("Envelope sent successfully (ID: {$eventId}, http status: {$response->getStatusCode()}).");
            } else {
                Log::debug("Envelope sent successfully (http status: {$response->getStatusCode()}).");
            }
        } else {
            Log::error("Envelope send error: {$response->getStatusCode()} {$response->getReasonPhrase()}");
        }

        return null;
    },
    function (Throwable $exception) {
        Log::error("Envelope send error: {$exception->getMessage()}");

        return null;
    }
);

$queue = new EnvelopeQueue(
    $upstreamConcurrency,
    $queueLimit,
    function (Envelope $envelope) use ($forwarder) {
        try {
            return $forwarder->forward($envelope);
        } catch (Exception $e) {
            Log::error("Failed to forward envelope: {$e->getMessage()}");

            return new React\Promise\Internal\RejectedPromise($e);
        }
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
        Log::debug('Envelope received, queueing forward to Sentry...');

        $queue->enqueue($envelope);
    }
);

$server->run();

Loop::run();

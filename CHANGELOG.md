# CHANGELOG

## 1.0.0

The Sentry SDK team is happy to announce the immediate availability of Sentry PHP Agent v1.0.0.

This is the first stable release of the Sentry PHP Agent, a local sidecar for PHP applications. The agent accepts
envelopes from the PHP SDK over the local interface and forwards them to Sentry asynchronously, reducing the network
latency paid by individual PHP requests.

Use it alongside the PHP, Laravel, or Symfony SDKs:

```bash
composer require sentry/sentry sentry/sentry-agent
vendor/bin/sentry-agent
```

```php
use Sentry\Agent\Transport\AgentClientBuilder;

\Sentry\init([
    'dsn' => '__YOUR_DSN__',
    'http_client' => AgentClientBuilder::create()->getClient(),
]);
```

## 0.1.1

- Internal release

## 0.1.0

- Internal release

<p align="center">
  <a href="https://sentry.io/?utm_source=github&utm_medium=logo" target="_blank">
    <picture>
      <source srcset="https://sentry-brand.storage.googleapis.com/sentry-logo-white.png" media="(prefers-color-scheme: dark)" />
      <source srcset="https://sentry-brand.storage.googleapis.com/sentry-logo-black.png" media="(prefers-color-scheme: light), (prefers-color-scheme: no-preference)" />
      <img src="https://sentry-brand.storage.googleapis.com/sentry-logo-black.png" alt="Sentry" width="280">
    </picture>
  </a>
</p>

_Bad software is everywhere, and we're tired of it. Sentry is on a mission to help developers write better software faster, so we can get back to enjoying technology. If you want to join us [<kbd>**Check out our open positions**</kbd>](https://sentry.io/careers/)_

# Official Sentry Agent for PHP

## Getting started

### What is the Agent?

The Agent is a sidecar to the PHP application that is meant to run on the same machine in order to
accept outgoing HTTP requests to Sentry and send them asynchronously without blocking the main PHP script.

### Install

Install the agent alongside the [PHP SDK](https://github.com/getsentry/sentry-php) using [Composer](https://getcomposer.org/).

```bash
composer require sentry/sentry sentry/sentry-agent
```

### Configuration

Use the SDK-provided agent client as the custom HTTP client for the [PHP](https://github.com/getsentry/sentry-php) (also [Symfony](https://github.com/getsentry/sentry-symfony) & [Laravel](https://github.com/getsentry/sentry-laravel)) SDKs.

```php
use Sentry\Agent\Transport\AgentClientBuilder;

Sentry\init([
    'dsn' => '___PUBLIC_DSN___',
    'http_client' => AgentClientBuilder::create()->getClient(),
]);
```

### Usage

```php
vendor/bin/sentry-agent
```

#### Configuration

```bash
vendor/bin/sentry-agent [options]
```

- `--listen=ADDRESS`, defaults to `127.0.0.1:5148`
- `--upstream-timeout=SECONDS`, defaults to `2.0` seconds
- `--upstream-concurrency=REQUESTS`, defaults to `10`
- `--queue-limit=ENVELOPES`, the amount of envelopes to keep in memory, defaults to `1000`
- `--drain-timeout=SECONDS`, defaults to `10.0` seconds
- `--control-server=ADDRESS`, enables the HTTP control server on the specified address
- `--http-proxy=URL`, forwards upstream envelope requests through an HTTP CONNECT proxy
- `--http-proxy-authentication=AUTH`, credentials for proxy basic authentication in `username:password` format

## License

Licensed under the MIT license, see [`LICENSE`](LICENSE)

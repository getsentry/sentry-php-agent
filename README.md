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

> [!CAUTION]
> The current state of this project is somewhere along the lines of pre-alpha.
> We strongly recommend you to not run this in production.
> During the `0.x` release cycle, we might introduce breaking changes at any time.

## Getting started

### Install

Install the agent using [Composer](https://getcomposer.org/).

```bash
composer require sentry/sentry-agent
```

### Configuration

The agent is configured as a custom HTTP client for the [PHP](https://github.com/getsentry/sentry-php) (also [Symfony](https://github.com/getsentry/sentry-symfony) & [Laravel](https://github.com/getsentry/sentry-laravel)) SDKs.

```php
Sentry\init([
    'dsn' => '___PUBLIC_DSN___',
    'http_client' => new \Sentry\Agent\Transport\AgentClient(),
]);
```

### Usage

```php
vendor/bin/sentry-agent
```

#### Configuration

```php
vendor/bin/sentry-agent [listen_address] [listen_port] [upstream_timeout] [upstream_concurrency] [queue_limit]
```

- `listen_address`, defaults to `127.0.0.1`
- `listen_port`, defaults to `5148`
- `upstream_timeout`, defaults to `2.0` seconds
- `upstream_concurrency`, defaults to `10`
- `queue_limit`, the amount of envelopes to keep in memory, defaults to `1000`

## License

Licensed under the MIT license, see [`LICENSE`](LICENSE)

<?php

declare(strict_types=1);

namespace Sentry\Agent\Console;

/**
 * @internal
 */
class Log
{
    private const LEVEL_INFO = 'INFO';
    private const LEVEL_ERROR = 'ERROR';
    private const LEVEL_DEBUG = 'DEBUG';

    /**
     * @var bool Whether to output debug messages or not
     */
    private static $verbose = false;

    private static function output(string $level, string $message): void
    {
        if ($level === self::LEVEL_DEBUG && !self::$verbose) {
            return;
        }

        echo \sprintf("sentry-agent [%-19s] [%-5s] %s\n", date('Y-m-d H:i:s'), $level, $message);
    }

    public static function info(string $message): void
    {
        self::output(self::LEVEL_INFO, $message);
    }

    public static function error(string $message): void
    {
        self::output(self::LEVEL_ERROR, $message);
    }

    public static function debug(string $message): void
    {
        self::output(self::LEVEL_DEBUG, $message);
    }

    public static function setVerbose(bool $verbose): void
    {
        self::$verbose = $verbose;
    }
}

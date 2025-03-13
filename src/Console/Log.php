<?php

declare(strict_types=1);

namespace Sentry\Agent\Console;

class Log
{
    private const LEVEL_INFO = 'INFO';
    private const LEVEL_ERROR = 'ERROR';

    private static function output(string $level, string $message): void
    {
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
}

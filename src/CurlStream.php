<?php

declare(strict_types=1);

namespace Sentry\Agent;

use Psr\Http\Message\StreamInterface;

/**
 * @internal
 */
class CurlStream implements StreamInterface
{
    /**
     * @var string
     */
    private $content;

    public function __construct(string $content)
    {
        $this->content = $content;
    }

    public function __toString(): string
    {
        return $this->content;
    }

    public function getContents(): string
    {
        return $this->content;
    }

    public function getSize(): ?int
    {
        return \strlen($this->content);
    }

    public function isReadable(): bool
    {
        return true;
    }

    public function isWritable(): bool
    {
        return false;
    }

    public function isSeekable(): bool
    {
        return false;
    }

    public function close(): void
    {
    }

    public function detach()
    {
        return null;
    }

    public function tell(): int
    {
        return 0;
    }

    public function eof(): bool
    {
        return true;
    }

    public function seek($offset, $whence = \SEEK_SET): void
    {
    }

    public function rewind(): void
    {
    }

    public function read($length): string
    {
        return $this->content;
    }

    public function write($string): int
    {
        return 0;
    }

    public function getMetadata($key = null)
    {
        return null;
    }
}

<?php

declare(strict_types=1);

namespace Sentry\Agent;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

class CurlResponse implements ResponseInterface
{
    private int $statusCode;
    private array $headers;
    private string $body;

    public function __construct(int $statusCode, array $headers, string $body)
    {
        $this->statusCode = $statusCode;
        $this->headers = $headers;
        $this->body = $body;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getReasonPhrase(): string
    {
        $phrases = [
            200 => 'OK',
            201 => 'Created',
            204 => 'No Content',
            400 => 'Bad Request',
            401 => 'Unauthorized',
            403 => 'Forbidden',
            404 => 'Not Found',
            429 => 'Too Many Requests',
            500 => 'Internal Server Error',
            502 => 'Bad Gateway',
            503 => 'Service Unavailable',
        ];

        return $phrases[$this->statusCode] ?? 'Unknown';
    }

    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function hasHeader($name): bool
    {
        return isset($this->headers[$name]);
    }

    public function getHeader($name): array
    {
        return $this->headers[$name] ?? [];
    }

    public function getHeaderLine($name): string
    {
        return implode(', ', $this->getHeader($name));
    }

    public function getBody(): StreamInterface
    {
        return new CurlStream($this->body);
    }

    public function withStatus($code, $reasonPhrase = ''): ResponseInterface
    {
        $new = clone $this;
        $new->statusCode = $code;

        return $new;
    }

    public function withHeader($name, $value): ResponseInterface
    {
        $new = clone $this;
        $new->headers[$name] = \is_array($value) ? $value : [$value];

        return $new;
    }

    public function withAddedHeader($name, $value): ResponseInterface
    {
        $new = clone $this;
        if (!isset($new->headers[$name])) {
            $new->headers[$name] = [];
        }
        $new->headers[$name] = array_merge($new->headers[$name], \is_array($value) ? $value : [$value]);

        return $new;
    }

    public function withoutHeader($name): ResponseInterface
    {
        $new = clone $this;
        unset($new->headers[$name]);

        return $new;
    }

    public function getProtocolVersion(): string
    {
        return '1.1';
    }

    public function withProtocolVersion($version): ResponseInterface
    {
        return $this;
    }

    public function withBody(StreamInterface $body): ResponseInterface
    {
        $new = clone $this;
        $new->body = (string) $body;

        return $new;
    }
}

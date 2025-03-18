<?php

declare(strict_types=1);

namespace Sentry\Agent;

/**
 * @internal
 */
class Envelope
{
    /**
     * @var string
     */
    private $data;

    public function __construct(string $data)
    {
        $this->data = $data;
    }

    public function getDsn(): ?string
    {
        $header = $this->getHeader();

        if ($header === null) {
            return null;
        }

        $parsedHeader = json_decode($header, true);

        if (\is_array($parsedHeader) && !empty($parsedHeader['dsn']) && \is_string($parsedHeader['dsn'])) {
            return $parsedHeader['dsn'];
        }

        return null;
    }

    public function getData(): string
    {
        return $this->data;
    }

    public function getHeader(): ?string
    {
        $position = strpos($this->data, "\n");

        if ($position === false) {
            return null;
        }

        return substr($this->data, 0, $position);
    }
}

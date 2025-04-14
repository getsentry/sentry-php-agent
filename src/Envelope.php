<?php

declare(strict_types=1);

namespace Sentry\Agent;

use Sentry\EventType;

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

    public function getFirstItemType(): ?EventType
    {
        $header = $this->getFirstItemHeader();

        if ($header === null) {
            return null;
        }

        $parsedHeader = json_decode($header, true);

        $type = null;

        if (\is_array($parsedHeader) && !empty($parsedHeader['type']) && \is_string($parsedHeader['type'])) {
            $type = $parsedHeader['type'];
        }

        switch ($type) {
            case (string) EventType::event():
                return EventType::event();
            case (string) EventType::transaction():
                return EventType::transaction();
            case (string) EventType::checkIn():
                return EventType::checkIn();
            default:
                return null;
        }
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

    public function getFirstItemHeader(): ?string
    {
        $headerEndsAt = strpos($this->data, "\n");

        if ($headerEndsAt === false) {
            return null;
        }

        $itemHeaderEndsAt = strpos($this->data, "\n", $headerEndsAt + 1);

        if ($itemHeaderEndsAt === false) {
            return null;
        }

        return substr($this->data, $headerEndsAt + 1, $itemHeaderEndsAt - $headerEndsAt - 1);
    }
}

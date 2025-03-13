<?php

declare(strict_types=1);

namespace Sentry\Agent;

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

    public function getData(): string
    {
        return $this->data;
    }
}

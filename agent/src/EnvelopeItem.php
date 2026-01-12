<?php

declare(strict_types=1);

namespace Sentry\Agent;

/**
 * @internal
 *
 * @phpstan-type EnvelopeItemHeader array{
 *     type: string,
 * }
 */
class EnvelopeItem
{
    /**
     * @var EnvelopeItemHeader The envelope item header
     */
    private $header;

    /**
     * @var string The envelope item data
     */
    private $data;

    /**
     * @param EnvelopeItemHeader $header The envelope item header
     * @param string             $data   The envelope item data
     */
    public function __construct(array $header, string $data)
    {
        $this->header = $header;
        $this->data = $data;
    }

    /**
     * @return EnvelopeItemHeader
     */
    public function getHeader(): array
    {
        return $this->header;
    }

    public function getData(): string
    {
        return $this->data;
    }

    public function __toString()
    {
        return json_encode($this->header) . "\n" . $this->data;
    }
}

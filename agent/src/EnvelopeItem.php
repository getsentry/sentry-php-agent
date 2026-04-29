<?php

declare(strict_types=1);

namespace Sentry\Agent;

/**
 * @internal
 *
 * @phpstan-type EnvelopeItemHeader array{
 *     type: string,
 *     length?: int,
 * }
 */
class EnvelopeItem
{
    private const EVENT_ITEM_TYPES_WITH_INGEST_PATH = [
        'event' => true,
        'transaction' => true,
    ];

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

    public function appendIngestPath(string $version): void
    {
        if (!isset(self::EVENT_ITEM_TYPES_WITH_INGEST_PATH[$this->header['type']])) {
            return;
        }

        $payload = json_decode($this->data, true);

        if (!\is_array($payload)) {
            return;
        }

        if (!isset($payload['ingest_path']) || !\is_array($payload['ingest_path'])) {
            $payload['ingest_path'] = [];
        }

        $payload['ingest_path'][] = [
            'version' => $version,
        ];

        $data = json_encode($payload);

        if ($data === false) {
            return;
        }

        $this->data = $data;

        if (isset($this->header['length'])) {
            $this->header['length'] = \strlen($this->data);
        }
    }

    public function __toString()
    {
        return json_encode($this->header) . "\n" . $this->data;
    }
}

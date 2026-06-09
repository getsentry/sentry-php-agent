<?php

declare(strict_types=1);

namespace Sentry\Agent;

use Sentry\Util\JSON;

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
    public const TRANSPORT_KEY = 'sentry.transport';
    public const TRANSPORT_VALUE = 'php-agent';

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

    public function prepareForForwarding(string $client): void
    {
        try {
            switch ($this->header['type']) {
                case 'event':
                case 'transaction':
                    /** @var array<array-key, mixed> $payload */
                    $payload = JSON::decode($this->data);
                    $payload = self::addIngestPathAndTransportTag($payload, $client);
                    break;
                case 'log':
                case 'trace_metric':
                    /** @var array<array-key, mixed> $payload */
                    $payload = JSON::decode($this->data);
                    $payload = self::addTransportAttributeToItems($payload);
                    break;
                default:
                    return;
            }

            $data = JSON::encode($payload);
        } catch (\Throwable $e) {
            return;
        }

        $this->data = $data;

        if (isset($this->header['length'])) {
            $this->header['length'] = \strlen($this->data);
        }
    }

    /**
     * @param array<array-key, mixed> $payload
     *
     * @return array<array-key, mixed>
     */
    private static function addIngestPathAndTransportTag(array $payload, string $client): array
    {
        if (!isset($payload['ingest_path']) || !\is_array($payload['ingest_path'])) {
            $payload['ingest_path'] = [];
        }

        $payload['ingest_path'][] = [
            'version' => $client,
        ];

        if (!isset($payload['tags']) || !\is_array($payload['tags'])) {
            $payload['tags'] = [];
        }

        $payload['tags'][self::TRANSPORT_KEY] = self::TRANSPORT_VALUE;

        return $payload;
    }

    /**
     * @param array<array-key, mixed> $payload
     *
     * @return array<array-key, mixed>
     */
    private static function addTransportAttributeToItems(array $payload): array
    {
        foreach ($payload['items'] ?? [] as $index => $item) {
            $item['attributes'][self::TRANSPORT_KEY] = [
                'type' => 'string',
                'value' => self::TRANSPORT_VALUE,
            ];

            $payload['items'][$index] = $item;
        }

        return $payload;
    }

    public function __toString()
    {
        return json_encode($this->header) . "\n" . $this->data;
    }
}

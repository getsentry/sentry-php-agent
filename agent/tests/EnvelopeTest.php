<?php

declare(strict_types=1);

namespace Sentry\Agent\Tests;

use PHPUnit\Framework\TestCase;
use Sentry\Agent\Envelope;
use Sentry\Agent\EnvelopeForwarder;
use Sentry\Agent\EnvelopeItem;

class EnvelopeTest extends TestCase
{
    public function testCanParsePhpSdkExampleEvent(): void
    {
        $payload = $this->getFixture('envelope_with_php_sdk_event');

        $envelope = Envelope::fromString($payload);

        $this->assertCount(1, $envelope->getItems());

        $this->assertEquals('event', $envelope->getItems()[0]->getHeader()['type']);

        $this->assertEquals($payload, (string) $envelope);
    }

    public function testCanParseLogsExampleEvent(): void
    {
        $payload = $this->getFixture('envelope_with_log_item');

        $envelope = Envelope::fromString($payload);

        $this->assertCount(1, $envelope->getItems());

        $this->assertEquals('log', $envelope->getItems()[0]->getHeader()['type']);

        $this->assertEquals($payload, (string) $envelope);
    }

    public function testCanParseEnvelopeWith2Items(): void
    {
        $payload = $this->getFixture('envelope_with_2_items');

        $envelope = Envelope::fromString($payload);

        $this->assertEquals('https://e12d836b15bb49d7bbf99e64295d995b:@sentry.io/42', $envelope->getHeader()['dsn']);

        $this->assertCount(2, $envelope->getItems());

        $this->assertEquals('attachment', $envelope->getItems()[0]->getHeader()['type']);
        $this->assertEquals('event', $envelope->getItems()[1]->getHeader()['type']);

        $this->assertEquals($payload, (string) $envelope);
    }

    public function testPrepareForForwardingAddsIngestPathAndTransportTagToEventItem(): void
    {
        $envelope = new Envelope(
            ['dsn' => 'http://public@example.com/1'],
            [new EnvelopeItem(['type' => 'event'], '{"message":"test"}')]
        );

        $envelope->prepareForForwarding($this->getIngestPathVersion());

        $payload = json_decode($envelope->getItems()[0]->getData(), true);

        $this->assertSame([['version' => $this->getIngestPathVersion()]], $payload['ingest_path']);
        $this->assertSame(EnvelopeItem::TRANSPORT_VALUE, $payload['tags'][EnvelopeItem::TRANSPORT_KEY]);
    }

    public function testPrepareForForwardingPreservesExistingIngestPathAndTags(): void
    {
        $envelope = new Envelope(
            ['dsn' => 'http://public@example.com/1'],
            [
                new EnvelopeItem(
                    ['type' => 'transaction'],
                    '{"transaction":"/test","ingest_path":[{"version":"relay/1.0.0","public_key":"abc"}],"tags":{"release_channel":"beta"}}'
                ),
            ]
        );

        $envelope->prepareForForwarding($this->getIngestPathVersion());

        $payload = json_decode($envelope->getItems()[0]->getData(), true);

        $this->assertSame(
            [
                ['version' => 'relay/1.0.0', 'public_key' => 'abc'],
                ['version' => $this->getIngestPathVersion()],
            ],
            $payload['ingest_path']
        );
        $this->assertSame(
            [
                'release_channel' => 'beta',
                EnvelopeItem::TRANSPORT_KEY => EnvelopeItem::TRANSPORT_VALUE,
            ],
            $payload['tags']
        );
    }

    public function testPrepareForForwardingReplacesInvalidTags(): void
    {
        $envelope = new Envelope(
            ['dsn' => 'http://public@example.com/1'],
            [new EnvelopeItem(['type' => 'event'], '{"message":"test","ingest_path":"invalid","tags":"invalid"}')]
        );

        $envelope->prepareForForwarding($this->getIngestPathVersion());

        $payload = json_decode($envelope->getItems()[0]->getData(), true);

        $this->assertSame([['version' => $this->getIngestPathVersion()]], $payload['ingest_path']);
        $this->assertSame([EnvelopeItem::TRANSPORT_KEY => EnvelopeItem::TRANSPORT_VALUE], $payload['tags']);
    }

    public function testPrepareForForwardingUpdatesLengthHeader(): void
    {
        $envelope = new Envelope(
            ['dsn' => 'http://public@example.com/1'],
            [new EnvelopeItem(['type' => 'event', 'length' => 18], '{"message":"test"}')]
        );

        $envelope->prepareForForwarding($this->getIngestPathVersion());

        $item = $envelope->getItems()[0];

        $this->assertSame(\strlen($item->getData()), $item->getHeader()['length']);
    }

    public function testPrepareForForwardingAddsTransportAttributeToLogItems(): void
    {
        $envelope = new Envelope(
            ['dsn' => 'http://public@example.com/1'],
            [
                new EnvelopeItem(
                    ['type' => 'log'],
                    '{"items":[{"body":"first","attributes":{"sentry.environment":{"type":"string","value":"production"}}},{"body":"second","attributes":[]}]}'
                ),
            ]
        );

        $envelope->prepareForForwarding($this->getIngestPathVersion());

        $payload = json_decode($envelope->getItems()[0]->getData(), true);

        $this->assertSame('production', $payload['items'][0]['attributes']['sentry.environment']['value']);
        $this->assertSame(
            ['type' => 'string', 'value' => EnvelopeItem::TRANSPORT_VALUE],
            $payload['items'][0]['attributes'][EnvelopeItem::TRANSPORT_KEY]
        );
        $this->assertSame(
            ['type' => 'string', 'value' => EnvelopeItem::TRANSPORT_VALUE],
            $payload['items'][1]['attributes'][EnvelopeItem::TRANSPORT_KEY]
        );
    }

    public function testPrepareForForwardingAddsTransportAttributeToTraceMetricItems(): void
    {
        $envelope = new Envelope(
            ['dsn' => 'http://public@example.com/1'],
            [
                new EnvelopeItem(
                    ['type' => 'trace_metric'],
                    '{"items":[{"name":"foo","attributes":{"unit":{"type":"string","value":"millisecond"}}}]}'
                ),
            ]
        );

        $envelope->prepareForForwarding($this->getIngestPathVersion());

        $payload = json_decode($envelope->getItems()[0]->getData(), true);

        $this->assertSame('millisecond', $payload['items'][0]['attributes']['unit']['value']);
        $this->assertSame(
            ['type' => 'string', 'value' => EnvelopeItem::TRANSPORT_VALUE],
            $payload['items'][0]['attributes'][EnvelopeItem::TRANSPORT_KEY]
        );
    }

    public function testPrepareForForwardingDoesNotMutateUnsupportedItems(): void
    {
        $payloads = [
            'check_in' => '{"check_in_id":"abc","monitor_slug":"job","status":"ok"}',
            'profile' => '{"platform":"php","version":"1","client_sdk":{"name":"sentry.php","version":"4.0.0"}}',
            'client_report' => '{"timestamp":1746542641,"discarded_events":[]}',
            'session' => '{"sid":"abc","did":"x","started":"2025-01-01T00:00:00Z","status":"ok"}',
            'replay_event' => '{"platform":"php","sdk":{"name":"sentry.php","version":"4.0.0"},"tags":{"foo":"bar"}}',
            'replay_recording' => '{"items":[{"attributes":{"foo":{"type":"string","value":"bar"}}}]}',
            'feedback' => '{"platform":"php","sdk":{"name":"sentry.php","version":"4.0.0"},"tags":{"foo":"bar"}}',
        ];

        foreach ($payloads as $type => $data) {
            $envelope = new Envelope(
                ['dsn' => 'http://public@example.com/1'],
                [new EnvelopeItem(['type' => $type], $data)]
            );

            $envelope->prepareForForwarding($this->getIngestPathVersion());

            $this->assertSame($data, $envelope->getItems()[0]->getData(), "Type {$type} should not be mutated");
        }
    }

    public function testPrepareForForwardingDoesNotMutateMalformedJson(): void
    {
        $envelope = new Envelope(
            ['dsn' => 'http://public@example.com/1'],
            [new EnvelopeItem(['type' => 'event'], '{"message":')]
        );

        $envelope->prepareForForwarding($this->getIngestPathVersion());

        $this->assertSame('{"message":', $envelope->getItems()[0]->getData());
    }

    private function getFixture(string $name): string
    {
        $fixture = file_get_contents(__DIR__ . "/fixtures/envelopes/{$name}.dat");

        if ($fixture === false) {
            throw new \RuntimeException("Failed to read fixture: {$name}");
        }

        // To make it easier to edit the fixtures, we remove the trailing new line
        return rtrim($fixture, "\n");
    }

    private function getIngestPathVersion(): string
    {
        return EnvelopeForwarder::IDENTIFIER . '/' . EnvelopeForwarder::VERSION;
    }
}

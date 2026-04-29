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

    public function testAppendIngestPathToEventItem(): void
    {
        $envelope = new Envelope(
            ['dsn' => 'http://public@example.com/1'],
            [new EnvelopeItem(['type' => 'event'], '{"message":"test"}')]
        );

        $envelope->appendIngestPath($this->getIngestPathVersion());

        $payload = json_decode($envelope->getItems()[0]->getData(), true);

        $this->assertSame([['version' => $this->getIngestPathVersion()]], $payload['ingest_path']);
    }

    public function testAppendIngestPathPreservesExistingEntries(): void
    {
        $envelope = new Envelope(
            ['dsn' => 'http://public@example.com/1'],
            [
                new EnvelopeItem(
                    ['type' => 'transaction'],
                    '{"transaction":"/test","ingest_path":[{"version":"relay/1.0.0","public_key":"abc"}]}'
                ),
            ]
        );

        $envelope->appendIngestPath($this->getIngestPathVersion());

        $payload = json_decode($envelope->getItems()[0]->getData(), true);

        $this->assertSame(
            [
                ['version' => 'relay/1.0.0', 'public_key' => 'abc'],
                ['version' => $this->getIngestPathVersion()],
            ],
            $payload['ingest_path']
        );
    }

    public function testAppendIngestPathUpdatesLengthHeader(): void
    {
        $envelope = new Envelope(
            ['dsn' => 'http://public@example.com/1'],
            [new EnvelopeItem(['type' => 'event', 'length' => 18], '{"message":"test"}')]
        );

        $envelope->appendIngestPath($this->getIngestPathVersion());

        $item = $envelope->getItems()[0];

        $this->assertSame(\strlen($item->getData()), $item->getHeader()['length']);
    }

    public function testAppendIngestPathReplacesInvalidExistingIngestPath(): void
    {
        $envelope = new Envelope(
            ['dsn' => 'http://public@example.com/1'],
            [new EnvelopeItem(['type' => 'event'], '{"message":"test","ingest_path":"invalid"}')]
        );

        $envelope->appendIngestPath($this->getIngestPathVersion());

        $payload = json_decode($envelope->getItems()[0]->getData(), true);

        $this->assertSame([['version' => $this->getIngestPathVersion()]], $payload['ingest_path']);
    }

    public function testAppendIngestPathDoesNotMutateUnsupportedItems(): void
    {
        foreach (['log', 'check_in', 'profile', 'attachment'] as $type) {
            $envelope = new Envelope(
                ['dsn' => 'http://public@example.com/1'],
                [new EnvelopeItem(['type' => $type], '{"message":"test"}')]
            );

            $envelope->appendIngestPath($this->getIngestPathVersion());

            $this->assertSame('{"message":"test"}', $envelope->getItems()[0]->getData());
        }
    }

    public function testAppendIngestPathDoesNotMutateMalformedJson(): void
    {
        $envelope = new Envelope(
            ['dsn' => 'http://public@example.com/1'],
            [new EnvelopeItem(['type' => 'event'], '{"message":')]
        );

        $envelope->appendIngestPath($this->getIngestPathVersion());

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

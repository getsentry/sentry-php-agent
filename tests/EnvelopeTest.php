<?php

declare(strict_types=1);

namespace Sentry\Agent\Tests;

use PHPUnit\Framework\TestCase;
use Sentry\Agent\Envelope;

class EnvelopeTest extends TestCase
{
    public function testCanParsePhpSdkExampleEvent(): void
    {
        $payload = $this->getFixture('example_php_sdk_event');

        $envelope = Envelope::fromString($payload);

        $this->assertCount(1, $envelope->getItems());

        $this->assertEquals('event', $envelope->getItems()[0]->getHeader()['type']);

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

    private function getFixture(string $name): string
    {
        $fixture = file_get_contents(__DIR__ . "/fixtures/envelopes/{$name}.dat");

        if ($fixture === false) {
            throw new \RuntimeException("Failed to read fixture: {$name}");
        }

        // To make it easier to edit the fixtures, we remove the trailing new line
        return rtrim($fixture, "\n");
    }
}

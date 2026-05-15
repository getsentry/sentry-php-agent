<?php

declare(strict_types=1);

namespace Sentry\Agent\Tests;

use PHPUnit\Framework\TestCase;
use Sentry\Agent\EnvelopeForwarder;
use Sentry\Event;
use Sentry\Options;
use Sentry\Serializer\PayloadSerializer;

class AgentForwardingTest extends TestCase
{
    use TestAgent, TestServer;

    public function testAgentForwardsEnvelopeToUpstream(): void
    {
        $serverAddress = $this->startTestServer();

        $dsn = "http://e12d836b15bb49d7bbf99e64295d995b:@{$serverAddress}/200";

        $envelope = $this->createEnvelope($dsn, 'Hello from agent test!');

        $this->startTestAgent();
        $this->sendEnvelopeToAgent($envelope);
        $this->stopTestAgent();

        $serverOutput = $this->stopTestServer();

        // Verify the envelope was forwarded correctly
        $this->assertEquals(200, $serverOutput['status']);
        $this->assertEquals(1, $serverOutput['request_count']);
        $this->assertStringContainsString('Hello from agent test!', $serverOutput['body']);
        $this->assertStringContainsString('"type":"event"', $serverOutput['body']);
        $this->assertStringContainsString(
            '"ingest_path":[{"version":"' . str_replace('/', '\/', EnvelopeForwarder::IDENTIFIER . '/' . EnvelopeForwarder::VERSION) . '"}]',
            $serverOutput['body']
        );

        // Verify the correct headers were sent
        $this->assertArrayHasKey('X-Sentry-Auth', $serverOutput['headers']);
        $this->assertStringContainsString('sentry_key=e12d836b15bb49d7bbf99e64295d995b', $serverOutput['headers']['X-Sentry-Auth']);
        $this->assertStringContainsString('sentry.php.agent', $serverOutput['headers']['X-Sentry-Auth']);
    }

    public function testAgentForwardsMultipleEnvelopesToUpstream(): void
    {
        $serverAddress = $this->startTestServer();

        $dsn = "http://publickey:@{$serverAddress}/200";

        $this->startTestAgent();
        $this->sendEnvelopeToAgent($this->createEnvelope($dsn, 'First message'));
        $this->sendEnvelopeToAgent($this->createEnvelope($dsn, 'Second message'));
        $this->stopTestAgent();

        $serverOutput = $this->stopTestServer();

        $this->assertEquals(200, $serverOutput['status']); // this verifies the last response status
        $this->assertEquals(2, $serverOutput['request_count']);
    }

    public function testAgentRejectsZeroLengthFrameWithoutBlockingEventLoop(): void
    {
        $this->startTestAgent();
        
        try {
            $this->sendRawDataToAgent(pack('N', 0));

            // Give the agent a chance to process the malformed frame before checking liveness.
            usleep(100000);

            $status = $this->getControlServerStatus(1.0);

            if ($status === false) {
                $this->forceStopTestAgent();
            }

            $this->assertTrue($status !== false, 'The control server should remain responsive after a zero-length frame.');
            $this->assertSame(['queue_size' => 0], json_decode((string) $status, true));
        } finally {
            if ($this->agentProcess !== null) {
                $this->stopTestAgent();
            }
        }
    }

    public function testAgentCompressesEnvelopeToUpstream(): void
    {
        if (!\extension_loaded('zlib')) {
            $this->markTestSkipped('The zlib extension is required to test envelope compression.');
        }

        $serverAddress = $this->startTestServer();

        $dsn = "http://publickey:@{$serverAddress}/200";

        $this->startTestAgent();
        $this->sendEnvelopeToAgent($this->createEnvelope($dsn, 'Compressed message'));
        $this->stopTestAgent();

        $serverOutput = $this->stopTestServer();

        $this->assertTrue($serverOutput['compressed']);
        $this->assertEquals('gzip', $serverOutput['headers']['Content-Encoding']);
        $this->assertStringContainsString('Compressed message', $serverOutput['body']);
    }

    public function testAgentRespectsRateLimiting(): void
    {
        $serverAddress = $this->startTestServer();

        $this->startTestAgent();

        // Use project ID 429 to trigger rate limiting response from test server
        // The test server returns X-Sentry-Rate-Limits header for this project ID
        $dsn = "http://publickey:@{$serverAddress}/429";

        // Send first envelope - this will trigger rate limiting
        $this->sendEnvelopeToAgent($this->createEnvelope($dsn, 'First message - triggers rate limit'));

        // Wait for the agent to process the first envelope and receive rate limit response
        $this->waitForQueueDrain();

        // Send second envelope - should be dropped by agent due to rate limiting
        $this->sendEnvelopeToAgent($this->createEnvelope($dsn, 'Second message - should be dropped'));

        // Send third envelope - should also be dropped
        $this->sendEnvelopeToAgent($this->createEnvelope($dsn, 'Third message - should be dropped'));

        $this->stopTestAgent();

        $serverOutput = $this->stopTestServer();

        // Only the first request should have been made to the server
        // The subsequent envelopes should have been dropped by the agent due to rate limiting
        $this->assertEquals(1, $serverOutput['request_count'], 'Only the first envelope should reach the server');
        $this->assertStringContainsString('First message', $serverOutput['body']);
    }

    /**
     * Create a test envelope using the Sentry PHP SDK.
     */
    private function createEnvelope(string $dsn, string $message): string
    {
        $options = new Options(['dsn' => $dsn]);

        $event = Event::createEvent();
        $event->setMessage($message);

        $serializer = new PayloadSerializer($options);

        return $serializer->serialize($event);
    }
}

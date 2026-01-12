<?php

declare(strict_types=1);

namespace Sentry\Agent\Tests;

use PHPUnit\Framework\TestCase;
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

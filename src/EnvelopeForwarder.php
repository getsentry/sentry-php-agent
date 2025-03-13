<?php

declare(strict_types=1);

namespace Sentry\Agent;

use Psr\Http\Message\ResponseInterface;
use React\Http\Browser;
use React\Promise\PromiseInterface;
use Sentry\Dsn;

class EnvelopeForwarder
{
    /**
     * The version of the protocol to communicate with the Sentry server.
     */
    public const PROTOCOL_VERSION = '7';

    /**
     * The identifier of the forwarder.
     */
    public const IDENTIFIER = 'sentry.php.agent';

    /**
     * The version of the SDK.
     */
    public const VERSION = '1.0.0';

    /**
     * @var Dsn
     */
    private $dsn;

    /**
     * @var callable(ResponseInterface): void
     */
    private $onEnvelopeSent;

    /**
     * @var callable(\Throwable): void
     */
    private $onEnvelopeError;

    /**
     * @var Browser
     */
    private $browser;

    /**
     * @param callable(ResponseInterface): void $onEnvelopeSent  called when the envelope is sent
     * @param callable(\Throwable): void        $onEnvelopeError called when the envelope fails to send
     */
    public function __construct(Dsn $dsn, int $timeout, callable $onEnvelopeSent, callable $onEnvelopeError)
    {
        $this->dsn = $dsn;
        $this->onEnvelopeSent = $onEnvelopeSent;
        $this->onEnvelopeError = $onEnvelopeError;

        $this->browser = (new Browser())->withTimeout($timeout);
    }

    /**
     * @return PromiseInterface<void>
     */
    public function forward(Envelope $envelope): PromiseInterface
    {
        $authHeader = [
            'sentry_version=' . self::PROTOCOL_VERSION,
            'sentry_client=' . self::IDENTIFIER . '/' . self::VERSION,
            'sentry_key=' . $this->dsn->getPublicKey(),
        ];

        // @TODO: Implement any number of missing options like the user-agent, encoding, proxy etc.

        return $this->browser->post($this->dsn->getEnvelopeApiEndpointUrl(), [
            'User-Agent' => self::IDENTIFIER . '/' . self::VERSION,
            'Content-Type' => 'application/x-sentry-envelope',
            'X-Sentry-Auth' => 'Sentry ' . implode(', ', $authHeader),
        ], $envelope->getData())->then($this->onEnvelopeSent, $this->onEnvelopeError);
    }
}

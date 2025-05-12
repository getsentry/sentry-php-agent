<?php

declare(strict_types=1);

namespace Sentry\Agent;

use Psr\Http\Message\ResponseInterface;
use React\Http\Browser;
use React\Promise\Internal\FulfilledPromise;
use React\Promise\PromiseInterface;
use Sentry\Dsn;
use Sentry\HttpClient\Response;
use Sentry\Transport\RateLimiter;

/**
 * @internal
 */
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
    public const VERSION = '0.1.0';

    /**
     * @var float
     */
    private $timeout;

    /**
     * @var callable(ResponseInterface): void
     */
    private $onEnvelopeSent;

    /**
     * @var callable(\Throwable): void
     */
    private $onEnvelopeError;

    /**
     * @var array<string, RateLimiter>
     */
    private $rateLimiters = [];

    /**
     * @param callable(ResponseInterface): void $onEnvelopeSent  called when the envelope is sent
     * @param callable(\Throwable): void        $onEnvelopeError called when the envelope fails to send
     */
    public function __construct(float $timeout, callable $onEnvelopeSent, callable $onEnvelopeError)
    {
        $this->timeout = $timeout;
        $this->onEnvelopeSent = $onEnvelopeSent;
        $this->onEnvelopeError = $onEnvelopeError;
    }

    /**
     * @return PromiseInterface<void|null>
     */
    public function forward(Envelope $envelope): PromiseInterface
    {
        $dsn = $envelope->getDsn();

        $rateLimiter = $this->getRateLimiter($dsn);

        $envelope->rejectItems(static function (EnvelopeItem $envelopeItem) use ($rateLimiter) {
            return $rateLimiter->isRateLimited($envelopeItem->getHeader()['type']);
        });

        // When the envelope is empty, we don't need to send it
        if ($envelope->isEmpty()) {
            return new FulfilledPromise();
        }

        $authHeader = [
            'sentry_version=' . self::PROTOCOL_VERSION,
            'sentry_client=' . self::IDENTIFIER . '/' . self::VERSION,
            'sentry_key=' . $dsn->getPublicKey(),
        ];

        // @TODO: Implement any number of missing options like the user-agent, encoding, proxy etc.

        // @TODO: We might want to replace this Browser API with a cURL implementation using curl_multi_exec
        return (new Browser())->withTimeout($this->timeout)->post(
            $dsn->getEnvelopeApiEndpointUrl(),
            [
                'User-Agent' => self::IDENTIFIER . '/' . self::VERSION,
                'Content-Type' => Envelope::CONTENT_TYPE,
                'X-Sentry-Auth' => 'Sentry ' . implode(', ', $authHeader),
            ],
            (string) $envelope
        )->then(function (ResponseInterface $response) use ($rateLimiter) {
            $rateLimiter->handleResponse(
                new Response($response->getStatusCode(), $response->getHeaders(), $response->getStatusCode() > 400 ? $response->getBody()->getContents() : '')
            );

            \call_user_func($this->onEnvelopeSent, $response);
        }, $this->onEnvelopeError);
    }

    private function getRateLimiter(Dsn $dsn): RateLimiter
    {
        $key = $dsn->getEnvelopeApiEndpointUrl();

        if (!isset($this->rateLimiters[$key])) {
            $this->rateLimiters[$key] = new RateLimiter();
        }

        return $this->rateLimiters[$key];
    }
}

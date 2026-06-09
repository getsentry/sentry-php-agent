<?php

declare(strict_types=1);

namespace Sentry\Agent;

use Clue\React\HttpProxy\ProxyConnector;
use Psr\Http\Message\ResponseInterface;
use React\Http\Browser;
use React\Http\Message\ResponseException;
use React\Promise\PromiseInterface;
use React\Socket\Connector;
use Sentry\Dsn;
use Sentry\HttpClient\Response;
use Sentry\Transport\RateLimiter;

use function React\Promise\resolve;

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
     * @var Browser
     */
    private $browser;

    /**
     * @var callable(ResponseInterface): null
     */
    private $onEnvelopeSent;

    /**
     * @var callable(\Throwable): null
     */
    private $onEnvelopeError;

    /**
     * @var array<string, RateLimiter>
     */
    private $rateLimiters = [];

    /**
     * @param callable(ResponseInterface): null $onEnvelopeSent  called when the envelope is sent
     * @param callable(\Throwable): null        $onEnvelopeError called when the envelope fails to send
     */
    public function __construct(float $timeout, callable $onEnvelopeSent, callable $onEnvelopeError, ?string $httpProxy = null, ?string $httpProxyAuthentication = null)
    {
        $this->timeout = $timeout;
        $this->browser = $this->createBrowser($httpProxy, $httpProxyAuthentication);
        $this->onEnvelopeSent = $onEnvelopeSent;
        $this->onEnvelopeError = $onEnvelopeError;
    }

    /**
     * @return PromiseInterface<null>
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
            return resolve(null);
        }

        $client = self::IDENTIFIER . '/' . self::VERSION;
        $envelope->prepareForForwarding($client);

        $authHeader = [
            'sentry_version=' . self::PROTOCOL_VERSION,
            'sentry_client=' . $client,
            'sentry_key=' . $dsn->getPublicKey(),
        ];

        $headers = [
            'User-Agent' => $client,
            'Content-Type' => Envelope::CONTENT_TYPE,
            'X-Sentry-Auth' => 'Sentry ' . implode(', ', $authHeader),
        ];

        $body = (string) $envelope;

        if (\extension_loaded('zlib')) {
            $compressedBody = gzcompress($body, -1, \ZLIB_ENCODING_GZIP);

            if ($compressedBody !== false) {
                $headers['Content-Encoding'] = 'gzip';
                $body = $compressedBody;
            }
        }

        return $this->browser->withTimeout($this->timeout)->post(
            $dsn->getEnvelopeApiEndpointUrl(),
            $headers,
            $body
        )->then(function (ResponseInterface $response) use ($rateLimiter) {
            $rateLimiter->handleResponse(
                new Response($response->getStatusCode(), $response->getHeaders(), $response->getStatusCode() > 400 ? $response->getBody()->getContents() : '')
            );

            \call_user_func($this->onEnvelopeSent, $response);

            return null;
        }, function (\Throwable $exception) use ($rateLimiter) {
            // Handle rate limiting from error responses (React HTTP throws ResponseException for non-2xx)
            if ($exception instanceof ResponseException) {
                $response = $exception->getResponse();
                $rateLimiter->handleResponse(
                    new Response($response->getStatusCode(), $response->getHeaders(), $response->getBody()->getContents())
                );
            }

            \call_user_func($this->onEnvelopeError, $exception);

            return null;
        });
    }

    private function createBrowser(?string $httpProxy, ?string $httpProxyAuthentication): Browser
    {
        if ($httpProxy === null) {
            return new Browser();
        }

        $headers = [];

        if ($httpProxyAuthentication !== null) {
            $proxyParts = parse_url(strpos($httpProxy, '://') === false ? 'http://' . $httpProxy : $httpProxy);

            if (\is_array($proxyParts) && (isset($proxyParts['user']) || isset($proxyParts['pass']))) {
                throw new \InvalidArgumentException('Proxy credentials must be provided either in the proxy URL or through http proxy authentication, not both.');
            }

            $headers['Proxy-Authorization'] = 'Basic ' . base64_encode($httpProxyAuthentication);
        }

        $proxy = new ProxyConnector($httpProxy, null, $headers);
        $connector = new Connector([
            'tcp' => $proxy,
            'dns' => false,
        ]);

        return new Browser($connector);
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

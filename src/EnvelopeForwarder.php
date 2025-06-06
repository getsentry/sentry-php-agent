<?php

declare(strict_types=1);

namespace Sentry\Agent;

use Psr\Http\Message\ResponseInterface;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\Promise\Internal\FulfilledPromise;
use React\Promise\Promise;
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

        return $this->postAsync(
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

    /**
     * Async POST request using curl_multi_exec.
     *
     * @param array<string, string> $headers
     *
     * @return PromiseInterface<ResponseInterface>
     */
    private function postAsync(string $url, array $headers, string $body): PromiseInterface
    {
        return new Promise(function (callable $resolve, callable $reject) use ($url, $headers, $body) {
            $curlHandle = curl_init();

            $requestHeaders = [];
            foreach ($headers as $name => $value) {
                $requestHeaders[] = $name . ': ' . $value;
            }

            curl_setopt_array($curlHandle, [
                \CURLOPT_URL => $url,
                \CURLOPT_HTTPHEADER => $requestHeaders,
                \CURLOPT_USERAGENT => self::IDENTIFIER . '/' . self::VERSION,
                \CURLOPT_TIMEOUT => (int) $this->timeout,
                \CURLOPT_CONNECTTIMEOUT => (int) $this->timeout,
                \CURLOPT_ENCODING => '',
                \CURLOPT_POST => true,
                \CURLOPT_POSTFIELDS => $body,
                \CURLOPT_RETURNTRANSFER => true,
                \CURLOPT_HEADER => true,
            ]);

            $curlMultiHandle = curl_multi_init();
            curl_multi_add_handle($curlMultiHandle, $curlHandle);

            $loop = Loop::get();
            $loop->futureTick(function () use ($curlMultiHandle, $curlHandle, $resolve, $reject, $loop) {
                $this->pollCurlMultiWithEventLoop($curlMultiHandle, $curlHandle, $resolve, $reject, $loop);
            });
        });
    }
    
    private function pollCurlMultiWithEventLoop(\CurlMultiHandle $curlMultiHandle, \CurlHandle $curlHandle, callable $resolve, callable $reject, LoopInterface $loop): void
    {
        $running = null;
        
        // Execute one iteration
        $status = \curl_multi_exec($curlMultiHandle, $running);
        
        if ($running > 0 && $status === \CURLM_OK) {
            // Use a small timer interval for efficient polling (1ms)
            $loop->addTimer(0.001, function () use ($curlMultiHandle, $curlHandle, $resolve, $reject, $loop) {
                $this->pollCurlMultiWithEventLoop($curlMultiHandle, $curlHandle, $resolve, $reject, $loop);
            });
        } else {
            // Request completed, finalize the response
            $this->finalizeCurlResponse($curlMultiHandle, $curlHandle, $resolve, $reject);
        }
    }

    private function finalizeCurlResponse(\CurlMultiHandle $curlMultiHandle, \CurlHandle $curlHandle, callable $resolve, callable $reject): void
    {
        // Get the response
        $response = curl_multi_getcontent($curlHandle);
        $info = curl_getinfo($curlHandle);
        $error = curl_error($curlHandle);

        curl_multi_remove_handle($curlMultiHandle, $curlHandle);
        curl_close($curlHandle);
        curl_multi_close($curlMultiHandle);

        if ($error) {
            $reject(new \RuntimeException('cURL error: ' . $error));

            return;
        }

        if ($response === false) {
            $reject(new \RuntimeException('Failed to get response'));

            return;
        }

        $headerSize = $info['header_size'];
        $headerString = substr($response, 0, $headerSize);
        $body = substr($response, $headerSize);

        $headers = $this->parseHeaders($headerString);
        $statusCode = $info['http_code'];

        $resolve(new CurlResponse($statusCode, $headers, $body));
    }

    /**
     * Parse HTTP headers from header string.
     *
     * @return array<string, string[]>
     */
    private function parseHeaders(string $headerString): array
    {
        $headers = [];
        $lines = explode("\r\n", trim($headerString));

        foreach ($lines as $line) {
            if (empty($line) || strpos($line, 'HTTP/') === 0) {
                continue;
            }

            $parts = explode(':', $line, 2);
            if (\count($parts) === 2) {
                $name = trim($parts[0]);
                $value = trim($parts[1]);

                if (!isset($headers[$name])) {
                    $headers[$name] = [];
                }
                $headers[$name][] = $value;
            }
        }

        return $headers;
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

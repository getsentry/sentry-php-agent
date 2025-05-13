<?php

declare(strict_types=1);

namespace Sentry\Agent;

use Sentry\Agent\Exceptions\MalformedEnvelope;
use Sentry\Dsn;

/**
 * @internal
 *
 * @phpstan-type EnvelopeHeader array{
 *     dsn: string,
 * }
 */
class Envelope
{
    public const CONTENT_TYPE = 'application/x-sentry-envelope';

    /**
     * @var EnvelopeHeader The envelope header
     */
    private $header;

    /**
     * @var EnvelopeItem[] The envelope items
     */
    private $items;

    /**
     * @param EnvelopeHeader $header
     * @param EnvelopeItem[] $items
     */
    public function __construct(array $header, array $items)
    {
        $this->header = $header;
        $this->items = $items;
    }

    /**
     * @return EnvelopeHeader
     */
    public function getHeader(): array
    {
        return $this->header;
    }

    public function getDsn(): Dsn
    {
        return Dsn::createFromString($this->header['dsn']);
    }

    /**
     * @return EnvelopeItem[]
     */
    public function getItems(): array
    {
        return $this->items;
    }

    public function isEmpty(): bool
    {
        return empty($this->items);
    }

    /**
     * @param callable(EnvelopeItem): bool $callback if the callback returns true, the item will be removed from the envelope
     */
    public function rejectItems(callable $callback): void
    {
        $this->items = array_filter(
            $this->items,
            static function (EnvelopeItem $item) use ($callback) {
                return !$callback($item);
            }
        );
    }

    public function __toString()
    {
        $data = implode(
            "\n",
            array_map(
                static function (EnvelopeItem $item): string {
                    return (string) $item;
                }, $this->items
            )
        );

        // We always terminate with an additional newline
        return json_encode($this->header) . "\n{$data}";
    }

    /**
     * @throws MalformedEnvelope
     */
    public static function fromString(string $envelope): self
    {
        $consumePart = static function () use (&$envelope): ?string {
            // Once we fully consumed the envelope, we return null indicating EOF
            if ($envelope === '') {
                return null;
            }

            // Parts are newline delimited so we can find the next newline to find the end of the next part
            $nextNewline = strpos($envelope, "\n");

            if ($nextNewline === false) {
                $nextNewline = \strlen($envelope);
            }

            $part = substr($envelope, 0, $nextNewline);

            // We consume the newline as well
            $envelope = substr($envelope, $nextNewline + 1);

            // Empty parts are additional trailing newlines, which can be ignored
            if ($part === '') {
                return null;
            }

            return $part;
        };

        $consumeBytes = static function (int $bytes) use (&$envelope): string {
            if (\strlen($envelope) < $bytes) {
                throw new MalformedEnvelope('Envelope reached EOF before consuming expected bytes');
            }

            $part = substr($envelope, 0, $bytes);

            $envelope = substr($envelope, $bytes + 1);

            return $part;
        };

        $parseJson = static function (?string $json): array {
            if ($json === null) {
                throw new MalformedEnvelope('Envelope reached EOF before consuming expected JSON');
            }

            $decoded = json_decode($json, true);

            if (!\is_array($decoded)) {
                // Technically we could have a non-JSON error here (if we try to parse a single JSON scalar for example)
                // but we don't really care if that happens and we can just assume there was a problem parsing the JSON if we don't get an array
                throw new MalformedEnvelope('Failed to decode JSON: ' . json_last_error_msg());
            }

            return $decoded;
        };

        // The first part is always the envelope header
        $header = $parseJson($consumePart());

        // Technically the header could not contain the DSN key, but we don't really care about that case since we won't be able to forward the envelope
        if (!isset($header['dsn'])) {
            throw new MalformedEnvelope('Envelope header does not contain a DSN');
        }

        $items = [];

        while ($rawItemHeader = $consumePart()) {
            $itemHeader = $parseJson($rawItemHeader);

            // The item header should always contain the type
            if (!isset($itemHeader['type'])) {
                throw new MalformedEnvelope('Envelope item header does not contain a type');
            }

            // The size in the header is optional
            $itemContentLength = $itemHeader['length'] ?? null;

            if ($itemContentLength === null) {
                $itemContent = $consumePart();

                if ($itemContent === null) {
                    throw new MalformedEnvelope('Envelope reached EOF before consuming expected item content');
                }
            } else {
                $itemContent = $consumeBytes($itemContentLength);
            }

            $items[] = new EnvelopeItem($itemHeader, $itemContent);
        }

        return new self($header, $items);
    }
}

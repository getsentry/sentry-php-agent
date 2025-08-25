<?php

declare(strict_types=1);

namespace Sentry\Agent;

use Clue\React\Mq\Queue;

/**
 * @internal
 */
class EnvelopeQueue
{
    /**
     * @var Queue<null>
     */
    private $queue;

    /**
     * @param callable(Envelope): \React\Promise\PromiseInterface<null> $onProcessEnvelope called when a envelope is ready for processing
     */
    public function __construct(int $concurrency, int $limit, callable $onProcessEnvelope)
    {
        $this->queue = new Queue($concurrency, $limit, $onProcessEnvelope);
    }

    public function enqueue(Envelope $envelope): void
    {
        ($this->queue)($envelope);
    }
}

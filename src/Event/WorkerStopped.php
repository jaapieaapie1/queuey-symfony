<?php

declare(strict_types=1);

namespace Queuey\Symfony\Event;

use Symfony\Contracts\EventDispatcher\Event;

/** The run loop returned, cleanly or because `$cause` ended it. */
final class WorkerStopped extends Event
{
    /**
     * @param int             $processed      deliveries dispatched, whatever their outcome
     * @param int             $settleFailures acks the backend refused; each one means a
     *                                        redelivery, so duplicate work is coming
     * @param \Throwable|null $cause          what ended the run, or `null` for a clean stop
     */
    public function __construct(
        public readonly string $worker,
        public readonly int $processed,
        public readonly int $settleFailures,
        public readonly ?\Throwable $cause = null,
    ) {}
}

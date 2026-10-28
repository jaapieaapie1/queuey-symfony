<?php

declare(strict_types=1);

namespace Queuey\Symfony\Event;

use Symfony\Contracts\EventDispatcher\Event;

/** `queuey:work` is about to enter the run loop. */
final class WorkerStarting extends Event
{
    /**
     * @param string       $worker the name of the definition being run
     * @param list<string> $queues broker queue names, prefix applied
     */
    public function __construct(
        public readonly string $worker,
        public readonly array $queues,
    ) {}
}

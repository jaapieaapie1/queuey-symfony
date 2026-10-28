<?php

declare(strict_types=1);

namespace Queuey\Symfony\Event;

use Queuey\Job;
use Queuey\JobContext;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * A handler is about to run.
 *
 * Dispatched by class name, like every event here. It is not stoppable in any
 * meaningful sense: {@see \Queuey\Symfony\Handler\EventDispatchingHandler} never
 * looks at the returned event, because an observer that can change what the
 * queue does is not an observer.
 */
final class JobProcessing extends Event
{
    public function __construct(
        public readonly Job $job,
        public readonly JobContext $context,
    ) {}
}

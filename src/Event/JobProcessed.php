<?php

declare(strict_types=1);

namespace Queuey\Symfony\Event;

use Queuey\Duration;
use Queuey\Job;
use Queuey\JobContext;
use Symfony\Contracts\EventDispatcher\Event;

/** A handler returned normally; the delivery is about to be acked. */
final class JobProcessed extends Event
{
    public function __construct(
        public readonly Job $job,
        public readonly JobContext $context,
        public readonly Duration $runtime,
    ) {}
}

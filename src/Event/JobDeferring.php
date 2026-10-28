<?php

declare(strict_types=1);

namespace Queuey\Symfony\Event;

use Queuey\Exception\JobDeferred;
use Queuey\Job;
use Queuey\JobContext;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * A handler asked to come back later. A deferral is not a failure: the attempt
 * counter stays put and the retry policy is never consulted.
 */
final class JobDeferring extends Event
{
    public function __construct(
        public readonly Job $job,
        public readonly JobContext $context,
        public readonly JobDeferred $deferral,
    ) {}
}

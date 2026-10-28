<?php

declare(strict_types=1);

namespace Queuey\Symfony\Event;

use Queuey\Job;
use Queuey\JobContext;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * A handler threw. `$willRetry` is this bundle's reading of what the worker is
 * about to do, not a promise: the retry policy has the last word, and a
 * {@see \Queuey\Exception\FatalFailure} or an exhausted attempt count means the
 * job is dead-lettered instead (a {@see JobDeadLettered} follows).
 */
final class JobFailed extends Event
{
    public function __construct(
        public readonly Job $job,
        public readonly JobContext $context,
        public readonly \Throwable $exception,
        public readonly bool $willRetry,
    ) {}
}

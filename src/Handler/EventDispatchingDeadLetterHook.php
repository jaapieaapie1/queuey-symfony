<?php

declare(strict_types=1);

namespace Queuey\Symfony\Handler;

use Queuey\DeadLetter;
use Queuey\DeadLetterHook;
use Queuey\Symfony\Event\JobDeadLettered;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Turns every dead letter into a {@see JobDeadLettered} event.
 *
 * Throwing is left to {@see CompositeDeadLetterHook}: this hook does not guard
 * the dispatch itself, so a listener that blows up is reported once, by the
 * composite, with the name of the hook that failed.
 */
final class EventDispatchingDeadLetterHook implements DeadLetterHook
{
    public function __construct(private readonly EventDispatcherInterface $events) {}

    public function onDeadLetter(DeadLetter $dead): void
    {
        $this->events->dispatch(new JobDeadLettered($dead));
    }
}

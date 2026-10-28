<?php

declare(strict_types=1);

namespace Queuey\Symfony\Event;

use Queuey\DeadLetter;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * A job was given up on, for any of the four causes.
 *
 * Fired from a {@see \Queuey\DeadLetterHook}, so it runs *before* the delivery
 * is settled: a listener that crashes the process leaves the message with the
 * broker, and the event fires again after the redelivery. At-least-once, like
 * everything else here.
 */
final class JobDeadLettered extends Event
{
    public function __construct(public readonly DeadLetter $deadLetter) {}
}

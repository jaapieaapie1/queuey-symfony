<?php

declare(strict_types=1);

namespace Queuey\Symfony\Tests\Fixtures;

use Queuey\Symfony\Queuey;

/**
 * An ordinary application service that injects the bundle's entry point.
 *
 * It stands in for the things the skeleton-app smoke test tripped over — a
 * `#[AsCommand]` without a `description:`, a Twig extension, an event
 * subscriber — all of which Symfony instantiates eagerly. Constructing one must
 * not dial the broker; only reaching through to the backend may.
 */
final class EagerQueueyConsumer
{
    public function __construct(public readonly Queuey $queuey) {}
}

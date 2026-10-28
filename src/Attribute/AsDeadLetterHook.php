<?php

declare(strict_types=1);

namespace Queuey\Symfony\Attribute;

/**
 * Register a {@see \Queuey\DeadLetterHook} service with one named worker.
 *
 * The dead-letter twin of {@see AsJobHandler}: repeatable, and a service that
 * carries it is not also registered with the `default` worker.
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::IS_REPEATABLE)]
final class AsDeadLetterHook
{
    public function __construct(public readonly string $worker = 'default') {}
}

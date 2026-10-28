<?php

declare(strict_types=1);

namespace Queuey\Symfony\Attribute;

/**
 * Register a {@see \Queuey\JobHandler} service with one named worker.
 *
 * Autoconfiguration already tags every `JobHandler` service for the `default`
 * worker; this attribute targets another one, and is repeatable so a handler can
 * belong to several:
 *
 * ```php
 * #[AsJobHandler(worker: 'mail')]
 * #[AsJobHandler(worker: 'priority')]
 * final class SendMailHandler implements JobHandler { ... }
 * ```
 *
 * A service that carries this attribute is *only* registered with the workers it
 * names: it does not also land on `default`.
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::IS_REPEATABLE)]
final class AsJobHandler
{
    public function __construct(public readonly string $worker = 'default') {}
}

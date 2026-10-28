<?php

declare(strict_types=1);

namespace Queuey\Symfony\Exception;

use Queuey\Exception\InvalidConfiguration;

/**
 * A `config/packages/queuey.yaml` value this bundle cannot work with.
 *
 * Extends core's {@see InvalidConfiguration} so an application that already
 * catches queuey configuration errors keeps catching these, and because most of
 * them are core's own validation re-thrown with the key that produced it.
 *
 * The Config component catches the *shape* at compile time; this is the
 * semantic layer, thrown at first use so a bad `QUEUEY_AMQP_URL` never breaks
 * `bin/console cache:clear`.
 *
 * @phpstan-consistent-constructor every subclass keeps the `(message, code,
 *                                 previous)` constructor, so `forKey()` may
 *                                 build one with `new static`
 */
class InvalidQueueyConfiguration extends InvalidConfiguration
{
    /**
     * `$key` is the full dotted config path, so the message says where to look:
     * `queuey.workers.default.queues: ...`.
     */
    public static function forKey(string $key, string $problem, ?\Throwable $previous = null): static
    {
        return new static(\sprintf('%s: %s', $key, $problem), 0, $previous);
    }
}

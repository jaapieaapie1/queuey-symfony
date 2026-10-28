<?php

declare(strict_types=1);

namespace Queuey\Symfony;

use Queuey\FrozenClock;
use Queuey\SystemClock;

/**
 * Builds the {@see \Queuey\Clock} service for `queuey.clock`.
 *
 * `system` needs no factory — the container can call `new SystemClock()` itself
 * — but a frozen clock has to start at the real current time, or every job's
 * `age` is measured from the epoch.
 */
final class ClockFactory
{
    /** A clock frozen at the moment it was built. */
    public static function frozen(): FrozenClock
    {
        return new FrozenClock((new SystemClock())->nowMs());
    }
}

<?php

declare(strict_types=1);

namespace Queuey\Symfony\Exception;

use Queuey\DeadLetterHook;
use Queuey\JobHandler;

/**
 * A service listed in a worker definition came out of the container, but not as
 * the interface that slot needs.
 *
 * Separate from a plain {@see InvalidQueueyConfiguration} because the service id
 * may well exist and be spelled right — it is the *shape* that is wrong, and the
 * message has to say which interface was expected and what arrived instead.
 */
final class NotAHandler extends InvalidQueueyConfiguration
{
    /**
     * @param string $id       the service id that was resolved
     * @param string $expected the interface the slot needs
     * @param string $actual   what the container handed back, as `get_debug_type()` spells it
     */
    public static function service(string $id, string $expected, string $actual): self
    {
        return new self(\sprintf('service `%s` must implement %s, got %s', $id, $expected, $actual));
    }

    public static function jobHandler(string $id, string $actual): self
    {
        return self::service($id, JobHandler::class, $actual);
    }

    public static function deadLetterHook(string $id, string $actual): self
    {
        return self::service($id, DeadLetterHook::class, $actual);
    }
}

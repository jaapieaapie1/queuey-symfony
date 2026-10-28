<?php

declare(strict_types=1);

namespace Queuey\Symfony\Exception;

/** `queuey:work <name>` (or `Queuey::worker($name)`) named a worker nobody defined. */
final class UnknownWorker extends InvalidQueueyConfiguration
{
    /**
     * @param list<string> $known every worker name that *is* defined, so the message
     *                            can carry the likely typo's neighbours
     */
    public static function named(string $name, array $known): self
    {
        return new self(\sprintf(
            'unknown queuey worker `%s`; defined workers: %s',
            $name,
            $known === [] ? '(none)' : implode(', ', $known),
        ));
    }
}

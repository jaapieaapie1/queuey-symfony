<?php

declare(strict_types=1);

namespace Queuey\Symfony\Tests\Fixtures;

/**
 * A plain service the fixture handlers depend on, so a test can prove the
 * container — not `new` — built the handler.
 */
final class Recorder
{
    /** @var list<string> */
    private array $entries = [];

    public function record(string $entry): void
    {
        $this->entries[] = $entry;
    }

    /** @return list<string> */
    public function entries(): array
    {
        return $this->entries;
    }

    public function clear(): void
    {
        $this->entries = [];
    }
}

<?php

declare(strict_types=1);

namespace Queuey\Symfony\Tests\Fixtures;

use Psr\Log\AbstractLogger;

/** Keeps every log record so a test can assert what was reported and at which level. */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    public array $records = [];

    /**
     * @param array<string, mixed> $context
     */
    public function log(mixed $level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = [
            'level' => \is_string($level) ? $level : get_debug_type($level),
            'message' => (string) $message,
            'context' => $context,
        ];
    }

    /** @return list<string> */
    public function messages(string $level): array
    {
        return array_values(array_map(
            static fn(array $record): string => $record['message'],
            array_filter($this->records, static fn(array $record): bool => $record['level'] === $level),
        ));
    }
}

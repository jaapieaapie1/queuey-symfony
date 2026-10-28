<?php

declare(strict_types=1);

use Queuey\Backend;
use Queuey\Exception\BackendException;
use Queuey\Memory\MemoryBackend;
use Queuey\Symfony\Command\DeclareCommand;
use Queuey\Symfony\Tests\Fixtures\AppQueues;
use Queuey\Symfony\Tests\Fixtures\Console;
use Queuey\Symfony\Tests\TestCase;
use Queuey\Symfony\WorkerRegistry;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/** The `queuey:declare` command, on a kernel booted here and kept for the test. */
function declareTester(TestCase $case): CommandTester
{
    return Console::tester($case->bootedKernel(), 'queuey:declare');
}

/** @return list<string> */
function fixtureQueues(): array
{
    return array_map(static fn(AppQueues $case): string => $case->queueName(), AppQueues::all());
}

it('declares every configured worker\'s queue set', function (): void {
    /** @var TestCase $this */
    $tester = declareTester($this);
    $backend = $this->service(MemoryBackend::class);

    $status = $tester->execute([]);

    expect($status)->toBe(Command::SUCCESS)
        ->and($tester->getDisplay())->toContain(AppQueues::class)
        ->and($tester->getDisplay())->toContain(AppQueues::Emails->queueName())
        // The memory backend has no dead-letter queues to declare, so the
        // count is the whole story here.
        ->and($tester->getDisplay())->toContain('Declared 3 queue(s).')
        ->and($tester->getDisplay())->not->toContain('dead-letter queue')
        // Both fixture workers share one set, so it is declared once.
        ->and($backend->declared())->toBe(fixtureQueues());
});

it('declares the set of a named worker', function (): void {
    /** @var TestCase $this */
    $tester = declareTester($this);
    $backend = $this->service(MemoryBackend::class);

    $status = $tester->execute(['worker' => ['mail']]);

    expect($status)->toBe(Command::SUCCESS)
        ->and($backend->declared())->toBe(fixtureQueues());
});

it('declares the classes --set names', function (): void {
    /** @var TestCase $this */
    $tester = declareTester($this);
    $backend = $this->service(MemoryBackend::class);

    $status = $tester->execute(['--set' => [AppQueues::class]]);

    expect($status)->toBe(Command::SUCCESS)
        ->and($tester->getDisplay())->toContain(AppQueues::Emails->queueName())
        // The memory backend has no dead-letter queues to declare, so the
        // count is the whole story here.
        ->and($tester->getDisplay())->toContain('Declared 3 queue(s).')
        ->and($tester->getDisplay())->not->toContain('dead-letter queue')
        ->and($backend->declared())->toBe(fixtureQueues());
});

it('exits 2 when --set names something that is not a queue set', function (): void {
    /** @var TestCase $this */
    $tester = declareTester($this);

    $status = $tester->execute(['--set' => [stdClass::class]]);

    expect($status)->toBe(Command::INVALID)
        ->and($tester->getDisplay())->toContain('--set')
        ->and($this->service(MemoryBackend::class)->declared())->toBe([]);
});

it('exits 2 for an unknown worker', function (): void {
    /** @var TestCase $this */
    $tester = declareTester($this);

    $status = $tester->execute(['worker' => ['nope']]);

    expect($status)->toBe(Command::INVALID)
        ->and($tester->getDisplay())->toContain('unknown queuey worker');
});

it('exits 1 and reports the broker on stderr when it cannot be reached', function (): void {
    // Built by hand around a closure that throws what an unreachable broker
    // throws: no kernel can produce that without a dead RabbitMQ to point at.
    $command = new DeclareCommand(
        new WorkerRegistry([]),
        static function (): Backend {
            throw new BackendException('connection refused: localhost:5672');
        },
    );
    $tester = new CommandTester($command);

    $status = $tester->execute(['--set' => [AppQueues::class]], ['capture_stderr_separately' => true]);

    expect($status)->toBe(Command::FAILURE)
        ->and($tester->getErrorOutput())->toContain('connection refused: localhost:5672')
        ->and($tester->getDisplay())->not->toContain('Declared');
});

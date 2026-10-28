<?php

declare(strict_types=1);

use Queuey\Symfony\Tests\Fixtures\AppQueues;
use Queuey\Symfony\Tests\Fixtures\Console;
use Queuey\Symfony\Tests\Fixtures\Handlers\ConfigOnlyHandler;
use Queuey\Symfony\Tests\Fixtures\Handlers\FlakyHandler;
use Queuey\Symfony\Tests\Fixtures\Handlers\MailWorkerHandler;
use Queuey\Symfony\Tests\Fixtures\Handlers\RecordingHandler;
use Queuey\Symfony\Tests\Fixtures\OtherQueues;
use Queuey\Symfony\Tests\TestCase;
use Queuey\Symfony\WorkerDefinition;
use Queuey\Symfony\WorkerRegistry;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/** The `queuey:queues` command, on a kernel booted here and kept for the test. */
function queuesTester(TestCase $case): CommandTester
{
    return Console::tester($case->bootedKernel(), 'queuey:queues');
}

it('tables every worker, queue and handler', function (): void {
    /** @var TestCase $this */
    $tester = queuesTester($this);

    $status = $tester->execute([]);
    $display = $tester->getDisplay();

    expect($status)->toBe(Command::SUCCESS)
        ->and($display)->toContain('Worker')
        ->and($display)->toContain('Prefetch')
        ->and($display)->toContain('AppQueues')
        ->and($display)->toContain('Emails')
        ->and($display)->toContain(AppQueues::Emails->queueName())
        ->and($display)->toContain(AppQueues::Images->queueName())
        ->and($display)->toContain(AppQueues::Reports->queueName())
        // Both workers are listed; `mail` selects `Emails` only.
        ->and($display)->toContain('default')
        ->and($display)->toContain('mail');
});

it('shows each queue\'s own configuration and the retry in force', function (): void {
    /** @var TestCase $this */
    $tester = queuesTester($this);

    $tester->execute(['worker' => 'default']);
    $rows = array_values(array_filter(
        array_map(trim(...), explode("\n", $tester->getDisplay())),
        static fn(string $line): bool => str_starts_with($line, '| default'),
    ));

    // `#[Queue(prefetch: 4, maxPriority: 5)]` on `Emails`, no TTL, no retry.
    expect($rows[0])->toContain('| 4 ')
        ->and($rows[0])->toContain('| yes ')
        ->and($rows[0])->toContain('| 5 ')
        ->and($rows[0])->toContain('1/None')
        ->and($rows[0])->toContain(RecordingHandler::class)
        // `queuey.workers.default.retry_overrides.queues.Images` beats the set.
        ->and($rows[1])->toContain('2/Fixed')
        ->and($rows[1])->toContain(ConfigOnlyHandler::class)
        // `#[Queue(retry: ...)]` on `Reports`, untouched, two handlers.
        ->and($rows[2])->toContain('3/Fixed')
        ->and($rows[2])->toContain(FlakyHandler::class);
});

it('lists one worker when it is named', function (): void {
    /** @var TestCase $this */
    $tester = queuesTester($this);

    $status = $tester->execute(['worker' => 'mail']);
    $display = $tester->getDisplay();

    expect($status)->toBe(Command::SUCCESS)
        ->and($display)->toContain('mail')
        ->and($display)->toContain(MailWorkerHandler::class)
        ->and($display)->toContain(AppQueues::Emails->queueName())
        // `only: [Emails]`, so the other two cases are not consumed here.
        ->and($display)->not->toContain(AppQueues::Images->queueName())
        ->and($display)->not->toContain('default');
});

it('exits 2 for an unknown worker', function (): void {
    /** @var TestCase $this */
    $tester = queuesTester($this);

    $status = $tester->execute(['worker' => 'nope']);

    expect($status)->toBe(Command::INVALID)
        ->and($tester->getDisplay())->toContain('unknown queuey worker');
});

it('warns about a consumed queue no handler in this worker serves', function (): void {
    /** @var TestCase $this */
    $tester = queuesTester($this);
    // The overlapping-workers trap: a worker consuming a queue it has no
    // handler for takes the delivery anyway and dead-letters it.
    $this->service(WorkerRegistry::class)->define(new WorkerDefinition(
        name: 'idle',
        queueSet: OtherQueues::class,
    ));

    $status = $tester->execute(['worker' => 'idle'], ['capture_stderr_separately' => true]);
    $stderr = $tester->getErrorOutput();

    expect($status)->toBe(Command::SUCCESS)
        ->and($tester->getDisplay())->toContain(OtherQueues::Misc->queueName())
        ->and($stderr)->toContain('worker `idle` consumes ' . OtherQueues::Misc->queueName())
        ->and($stderr)->toContain('but has no handler')
        ->and($stderr)->toContain('every job on it will be dead-lettered (no handler)');
});

it('says nothing when every consumed queue has a handler', function (): void {
    /** @var TestCase $this */
    $tester = queuesTester($this);

    $status = $tester->execute([], ['capture_stderr_separately' => true]);

    expect($status)->toBe(Command::SUCCESS)
        ->and($tester->getErrorOutput())->not->toContain('but has no handler');
});

it('warns about a handler whose job belongs to another queue set', function (): void {
    /** @var TestCase $this */
    $tester = queuesTester($this);
    // `RecordingHandler` serves `AppQueues::Emails`; this worker consumes a
    // different set entirely, so its job can never be delivered here.
    $this->service(WorkerRegistry::class)->define(new WorkerDefinition(
        name: 'other',
        queueSet: OtherQueues::class,
        handlers: [RecordingHandler::class],
    ));

    $status = $tester->execute(['worker' => 'other'], ['capture_stderr_separately' => true]);

    expect($status)->toBe(Command::SUCCESS)
        ->and($tester->getErrorOutput())->toContain(RecordingHandler::class)
        ->and($tester->getErrorOutput())->toContain('does not consume')
        ->and($tester->getErrorOutput())->toContain('Emails');
});

it('warns about a handler whose case the worker\'s only excludes', function (): void {
    /** @var TestCase $this */
    $tester = queuesTester($this);
    // Right set, wrong case: `MailWorkerHandler` serves `Emails`, and `only`
    // leaves this worker consuming `Reports` alone. The row loop never shows
    // the case, so without the warning the handler vanishes from the listing.
    $this->service(WorkerRegistry::class)->define(new WorkerDefinition(
        name: 'reports',
        queueSet: AppQueues::class,
        only: ['Reports'],
        handlers: [MailWorkerHandler::class],
    ));

    $status = $tester->execute(['worker' => 'reports'], ['capture_stderr_separately' => true]);
    $stderr = $tester->getErrorOutput();

    expect($status)->toBe(Command::SUCCESS)
        ->and($tester->getDisplay())->toContain(AppQueues::Reports->queueName())
        ->and($stderr)->toContain(MailWorkerHandler::class)
        ->and($stderr)->toContain('AppQueues::Emails')
        ->and($stderr)->toContain('worker `reports` does not consume')
        ->and($stderr)->toContain('`only` selects Reports');
});

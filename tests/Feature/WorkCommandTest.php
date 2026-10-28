<?php

declare(strict_types=1);

use Queuey\Envelope;
use Queuey\Exception\ConsumerStopped;
use Queuey\Memory\MemoryBackend;
use Queuey\Symfony\Event\WorkerStarting;
use Queuey\Symfony\Event\WorkerStopped;
use Queuey\Symfony\Queuey;
use Queuey\Symfony\Tests\Fixtures\AppQueues;
use Queuey\Symfony\Tests\Fixtures\Console;
use Queuey\Symfony\Tests\Fixtures\Jobs\Flaky;
use Queuey\Symfony\Tests\Fixtures\Jobs\Newsletter;
use Queuey\Symfony\Tests\Fixtures\Jobs\ResizeImage;
use Queuey\Symfony\Tests\Fixtures\Jobs\SendMail;
use Queuey\Symfony\Tests\Fixtures\Recorder;
use Queuey\Symfony\Tests\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/*
 * Every test boots the kernel *first* and keeps it: `bootKernel()` shuts the
 * previous one down, so fetching a service before building the application
 * would hand the command a different memory backend than the one the jobs were
 * published to.
 *
 * On the frozen clock the memory backend's sleeper is a no-op, so an idle
 * `wait()` returns at once — a run with no stopping condition would spin
 * forever. Every run below therefore pre-publishes exactly as many jobs as it
 * lets the worker process (`--once`, `--max-jobs=N`), or stops on time
 * (`--for=0s`).
 */

/** The `queuey:work` command, on a kernel booted here and kept for the test. */
function workTester(TestCase $case): CommandTester
{
    return Console::tester($case->bootedKernel(), 'queuey:work');
}

it('processes one pre-published job with --once and reports it', function (): void {
    /** @var TestCase $this */
    $tester = workTester($this);
    $this->service(Queuey::class)->enqueue(new SendMail('ada@example.com'));

    $status = $tester->execute(['--once' => true]);
    $display = $tester->getDisplay();

    expect($status)->toBe(Command::SUCCESS)
        ->and($display)->toContain('Running queuey worker default')
        ->and($display)->toContain(MemoryBackend::class)
        ->and($display)->toContain(AppQueues::Emails->queueName())
        ->and($display)->toContain('queuey_symfony_test.send_mail')
        ->and($display)->toContain('DONE')
        ->and($display)->toContain('Stopped after 1 job(s).')
        ->and($this->service(Recorder::class)->entries())->toBe(['mail:ada@example.com:attempt=1']);
});

it('stops after --max-jobs deliveries', function (): void {
    /** @var TestCase $this */
    $tester = workTester($this);
    $queuey = $this->service(Queuey::class);
    $queuey->enqueue(new SendMail('ada@example.com'));
    $queuey->enqueue(new SendMail('grace@example.com'));
    $queuey->enqueue(new ResizeImage('/tmp/a.png'));

    $status = $tester->execute(['--max-jobs' => '2']);

    expect($status)->toBe(Command::SUCCESS)
        ->and($tester->getDisplay())->toContain('Stopped after 2 job(s).')
        ->and($this->service(Recorder::class)->entries())->toHaveCount(2);
});

it('restricts consumption to the queues --queue names', function (): void {
    /** @var TestCase $this */
    $tester = workTester($this);
    $queuey = $this->service(Queuey::class);
    $queuey->enqueue(new SendMail('ada@example.com'));
    $queuey->enqueue(new ResizeImage('/tmp/a.png'));

    $status = $tester->execute(['--queue' => ['Emails'], '--once' => true]);
    $backend = $this->service(MemoryBackend::class);

    expect($status)->toBe(Command::SUCCESS)
        ->and($tester->getDisplay())->not->toContain(AppQueues::Images->queueName())
        ->and($this->service(Recorder::class)->entries())->toBe(['mail:ada@example.com:attempt=1'])
        // Untouched: the worker never consumed that queue.
        ->and($backend->pending(AppQueues::Images->queueName()))->toHaveCount(1);
});

it('prints a FAIL line naming the retry delay', function (): void {
    /** @var TestCase $this */
    $tester = workTester($this);
    $this->service(Queuey::class)->enqueue(new Flaky('deploy'));

    $status = $tester->execute(['--once' => true]);

    // `AppQueues::Reports` retries 3 times with a fixed 2s backoff.
    expect($status)->toBe(Command::SUCCESS)
        ->and($tester->getDisplay())->toContain('FAIL (retry in 2s)')
        ->and($this->service(MemoryBackend::class)->retried())->toBe(1);
});

it('prints a DEAD line for a delivery no handler claims', function (): void {
    /** @var TestCase $this */
    $tester = workTester($this);
    $backend = $this->service(MemoryBackend::class);
    $this->service(Queuey::class)->producer(AppQueues::class);
    $backend->publish(Envelope::raw('queuey_symfony_test.unknown', AppQueues::Emails->queueName(), (object) []));

    $status = $tester->execute(['--once' => true]);

    expect($status)->toBe(Command::SUCCESS)
        ->and($tester->getDisplay())->toContain('DEAD')
        ->and($tester->getDisplay())->toContain('no handler for job type')
        ->and($backend->dead(AppQueues::Emails->queueName()))->toHaveCount(1);
});

it('suppresses the per-job lines with --quiet-jobs', function (): void {
    /** @var TestCase $this */
    $tester = workTester($this);
    $this->service(Queuey::class)->enqueue(new SendMail('ada@example.com'));

    $status = $tester->execute(['--once' => true, '--quiet-jobs' => true]);
    $display = $tester->getDisplay();

    expect($status)->toBe(Command::SUCCESS)
        ->and($display)->not->toContain('DONE')
        ->and($display)->not->toContain('queuey_symfony_test.send_mail')
        // The header and the summary are not per-job output.
        ->and($display)->toContain('Stopped after 1 job(s).')
        ->and($this->service(Recorder::class)->entries())->toHaveCount(1);
});

it('takes its listeners back off the dispatcher when the run ends', function (): void {
    /** @var TestCase $this */
    $tester = workTester($this);
    $dispatcher = $this->container()->get('event_dispatcher');
    \assert($dispatcher instanceof EventDispatcherInterface);
    $before = \count($dispatcher->getListeners());

    $this->service(Queuey::class)->enqueue(new SendMail('ada@example.com'));
    $tester->execute(['--once' => true]);

    expect(\count($dispatcher->getListeners()))->toBe($before);
});

it('dispatches WorkerStarting and WorkerStopped around the run', function (): void {
    /** @var TestCase $this */
    $tester = workTester($this);
    $dispatcher = $this->container()->get('event_dispatcher');
    \assert($dispatcher instanceof EventDispatcherInterface);

    $seen = [];
    $dispatcher->addListener(WorkerStarting::class, static function (WorkerStarting $event) use (&$seen): void {
        $seen['starting'] = [$event->worker, $event->queues];
    });
    $dispatcher->addListener(WorkerStopped::class, static function (WorkerStopped $event) use (&$seen): void {
        $seen['stopped'] = [$event->worker, $event->processed, $event->cause];
    });

    $this->service(Queuey::class)->enqueue(new SendMail('ada@example.com'));
    $tester->execute(['--once' => true]);

    expect($seen['starting'][0])->toBe('default')
        ->and($seen['starting'][1])->toContain(AppQueues::Emails->queueName())
        ->and($seen['stopped'])->toBe(['default', 1, null]);
});

it('runs the named worker only over its own queues', function (): void {
    /** @var TestCase $this */
    $tester = workTester($this);
    $this->service(Queuey::class)->enqueue(new SendMail('ada@example.com'));

    // `mail` consumes `Emails` only, and no handler there claims `send_mail`.
    $status = $tester->execute(['worker' => 'mail', '--once' => true]);

    expect($status)->toBe(Command::SUCCESS)
        ->and($tester->getDisplay())->toContain('Running queuey worker mail')
        ->and($tester->getDisplay())->toContain('DEAD');
});

it('stops on the --for deadline without touching the backlog', function (): void {
    /** @var TestCase $this */
    $tester = workTester($this);
    $this->service(Queuey::class)->enqueue(new SendMail('ada@example.com'));

    // The clock is frozen, so a zero limit is the only deadline that can pass:
    // `runFor()` checks it before the first `wait()`.
    $status = $tester->execute(['--for' => '0s']);

    expect($status)->toBe(Command::SUCCESS)
        ->and($tester->getDisplay())->toContain('Stopped after 0 job(s).')
        ->and($this->service(Recorder::class)->entries())->toBe([]);
});

it('exits 2 for an unknown worker', function (): void {
    /** @var TestCase $this */
    $tester = workTester($this);

    $status = $tester->execute(['worker' => 'nope']);

    expect($status)->toBe(Command::INVALID)
        ->and($tester->getDisplay())->toContain('unknown queuey worker');
});

it('exits 2 for a queue case the set does not have', function (): void {
    /** @var TestCase $this */
    $tester = workTester($this);

    $status = $tester->execute(['--queue' => ['Nope'], '--once' => true]);

    expect($status)->toBe(Command::INVALID)
        ->and($tester->getDisplay())->toContain('--queue');
});

it('exits 2 when --queue names a case the worker\'s only excludes', function (): void {
    /** @var TestCase $this */
    $tester = workTester($this);

    // `mail` is configured `only: [Emails]`. `--queue` narrows, never widens: a
    // restricted worker must not start draining `Images` off the command line.
    $status = $tester->execute(['worker' => 'mail', '--queue' => ['Images'], '--once' => true], ['capture_stderr_separately' => true]);

    expect($status)->toBe(Command::INVALID)
        ->and($tester->getErrorOutput())->toContain('--queue')
        ->and($tester->getErrorOutput())->toContain('worker `mail` does not consume `Images`')
        // The block wraps, so the allowed set is checked without the prefix.
        ->and($tester->getErrorOutput())->toContain('Emails');
});

it('still lets --queue narrow a restricted worker to one of its own cases', function (): void {
    /** @var TestCase $this */
    $tester = workTester($this);
    $this->service(Queuey::class)->enqueue(new Newsletter('spring'));

    $status = $tester->execute(['worker' => 'mail', '--queue' => ['Emails'], '--once' => true]);

    expect($status)->toBe(Command::SUCCESS)
        ->and($tester->getDisplay())->toContain(AppQueues::Emails->queueName())
        ->and($this->service(Recorder::class)->entries())->toBe(['newsletter:spring']);
});

it('exits 2 for an unreadable --max-jobs', function (): void {
    /** @var TestCase $this */
    $tester = workTester($this);

    $status = $tester->execute(['--max-jobs' => 'lots']);

    expect($status)->toBe(Command::INVALID)
        ->and($tester->getDisplay())->toContain('--max-jobs');
});

it('exits 2 for an unparseable --for', function (): void {
    /** @var TestCase $this */
    $tester = workTester($this);

    $status = $tester->execute(['--for' => 'soonish']);

    expect($status)->toBe(Command::INVALID)
        ->and($tester->getDisplay())->toContain('--for');
});

it('exits 1 and reports the cause when the backend goes away', function (): void {
    /** @var TestCase $this */
    $tester = workTester($this);
    $dispatcher = $this->container()->get('event_dispatcher');
    \assert($dispatcher instanceof EventDispatcherInterface);
    $backend = $this->service(MemoryBackend::class);

    // Closing the backend between "starting" and the first `consume()` is the
    // cheapest stand-in for a broker that went away for good.
    $dispatcher->addListener(WorkerStarting::class, static function () use ($backend): void {
        $backend->close();
    });
    $stopped = null;
    $dispatcher->addListener(WorkerStopped::class, static function (WorkerStopped $event) use (&$stopped): void {
        $stopped = $event;
    });

    $status = $tester->execute(['--once' => true]);

    // Never swallowed: a supervisor needs the non-zero exit to restart.
    expect($status)->toBe(Command::FAILURE)
        ->and($stopped?->cause)->toBeInstanceOf(ConsumerStopped::class);
});

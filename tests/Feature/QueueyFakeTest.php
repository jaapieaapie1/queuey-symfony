<?php

declare(strict_types=1);

use PHPUnit\Framework\AssertionFailedError;
use Queuey\Clock;
use Queuey\Envelope;
use Queuey\FrozenClock;
use Queuey\Memory\MemoryBackend;
use Queuey\RetryPolicy;
use Queuey\Symfony\Exception\InvalidQueueyConfiguration;
use Queuey\Symfony\Queuey;
use Queuey\Symfony\Test\QueueyFake;
use Queuey\Symfony\Tests\Fixtures\AppQueues;
use Queuey\Symfony\Tests\Fixtures\Handlers\DeferringHandler;
use Queuey\Symfony\Tests\Fixtures\Jobs\BuildReport;
use Queuey\Symfony\Tests\Fixtures\Jobs\DeferOnce;
use Queuey\Symfony\Tests\Fixtures\Jobs\Flaky;
use Queuey\Symfony\Tests\Fixtures\Jobs\Newsletter;
use Queuey\Symfony\Tests\Fixtures\Jobs\SendMail;
use Queuey\Symfony\Tests\Fixtures\Recorder;
use Queuey\Symfony\Tests\TestCase;
use Queuey\Symfony\WorkerFactory;
use Queuey\Symfony\WorkerRegistry;
use Queuey\SystemClock;

it('resolves from the container and shares the backend and the clock', function (): void {
    /** @var TestCase $this */
    $fake = $this->queuey();

    expect($fake)->toBeInstanceOf(QueueyFake::class)
        ->and($fake->backend())->toBe($this->service(MemoryBackend::class))
        ->and($fake->clock())->toBe($this->service(FrozenClock::class))
        ->and($fake->worker('mail')->queues())->toHaveCount(1);
});

it('refuses to be built on a clock that does not stand still', function (): void {
    /** @var TestCase $this */
    $build = fn(): QueueyFake => new QueueyFake(
        $this->service(Queuey::class),
        $this->service(WorkerFactory::class),
        $this->service(WorkerRegistry::class),
        $this->service(MemoryBackend::class),
        new SystemClock(),
    );

    expect($build)->toThrow(InvalidQueueyConfiguration::class, 'queuey.clock');
});

it('sees a published job, whatever queue of whatever worker it landed on', function (): void {
    /** @var TestCase $this */
    $queuey = $this->service(Queuey::class);
    $queuey->enqueue(new SendMail('ada@example.com'));
    $queuey->enqueue(new SendMail('bob@example.com'));
    $queuey->enqueue(new BuildReport('q3'));

    $this->assertJobEnqueued(SendMail::class);
    $this->assertJobEnqueued(SendMail::class, fn(SendMail $job): bool => $job->to === 'bob@example.com');
    $this->assertJobEnqueuedTimes(SendMail::class, 2);
    $this->assertJobEnqueuedTimes(BuildReport::class, 1);
    $this->assertJobNotEnqueued(Newsletter::class);
    $this->assertJobNotEnqueued(SendMail::class, fn(SendMail $job): bool => $job->to === 'nobody@example.com');
    $this->assertNothingDeadLettered();

    expect(array_map(
        static fn(SendMail $job): string => $job->to,
        $this->queuey()->enqueued(SendMail::class),
    ))->toBe(['ada@example.com', 'bob@example.com']);
});

it('hands the envelope to the callback alongside the job', function (): void {
    /** @var TestCase $this */
    $this->service(Queuey::class)->enqueue(new SendMail('ada@example.com'));

    $this->assertJobEnqueued(
        SendMail::class,
        fn(SendMail $job, Envelope $envelope): bool => $envelope->queue === AppQueues::Emails->queueName()
            && $envelope->attempt === 1
            && $job->to === 'ada@example.com',
    );
});

it('starts every test with an empty backend', function (): void {
    /** @var TestCase $this */
    $this->assertNothingEnqueued();
    $this->assertJobNotEnqueued(SendMail::class);
    $this->assertNothingDeadLettered();
});

it('fails an assertion with a message listing what was enqueued', function (): void {
    /** @var TestCase $this */
    $queuey = $this->service(Queuey::class);
    $queuey->enqueue(new SendMail('ada@example.com'));
    $queuey->enqueue(new SendMail('bob@example.com'));
    $queuey->enqueue(new BuildReport('q3'));

    expect(fn() => $this->assertNothingEnqueued())
        ->toThrow(AssertionFailedError::class, 'queuey_symfony_test.send_mail (2)');
    expect(fn() => $this->assertNothingEnqueued())
        ->toThrow(AssertionFailedError::class, 'queuey_symfony_test.build_report (1)');
    expect(fn() => $this->assertJobEnqueued(Newsletter::class))
        ->toThrow(AssertionFailedError::class, 'Expected ' . Newsletter::class . ' to be enqueued;');
    expect(fn() => $this->assertJobEnqueued(SendMail::class, static fn(): bool => false))
        ->toThrow(AssertionFailedError::class, 'matching the callback');
    expect(fn() => $this->assertJobEnqueuedTimes(SendMail::class, 1))
        ->toThrow(AssertionFailedError::class, 'enqueued 1 time(s), found 2');
    expect(fn() => $this->assertJobNotEnqueued(SendMail::class))
        ->toThrow(AssertionFailedError::class, 'not to be enqueued');
});

it('says so when nothing at all was enqueued', function (): void {
    /** @var TestCase $this */
    expect(fn() => $this->assertJobEnqueued(SendMail::class))
        ->toThrow(AssertionFailedError::class, 'nothing is enqueued');
    expect(fn() => $this->assertJobDelayed(SendMail::class))
        ->toThrow(AssertionFailedError::class, 'nothing is delayed');
    expect(fn() => $this->assertJobDeadLettered(SendMail::class))
        ->toThrow(AssertionFailedError::class, 'nothing was dead-lettered');
});

it('counts a held job as enqueued and as delayed', function (): void {
    /** @var TestCase $this */
    $queuey = $this->service(Queuey::class);
    $queuey->enqueueAfter(new SendMail('later@example.com'), '30s');
    $queuey->defer(new SendMail('front@example.com'), '5s');
    $queuey->enqueue(new BuildReport('q3'));

    $this->assertJobEnqueuedTimes(SendMail::class, 2);
    $this->assertJobDelayed(SendMail::class);
    $this->assertJobDelayed(SendMail::class, fn(SendMail $job): bool => $job->to === 'later@example.com');

    expect($this->queuey()->delayed(SendMail::class))->toHaveCount(2)
        ->and($this->queuey()->delayed(BuildReport::class))->toBe([]);

    // `BuildReport` is waiting, not held.
    expect(fn() => $this->assertJobDelayed(BuildReport::class))
        ->toThrow(AssertionFailedError::class, 'to be delayed');
});

it('runs handlers the container built and reports how many jobs it processed', function (): void {
    /** @var TestCase $this */
    $queuey = $this->service(Queuey::class);
    $queuey->enqueue(new SendMail('ada@example.com'));
    $queuey->enqueue(new BuildReport('q3'));

    expect($this->queuey()->work())->toBe(2)
        ->and($this->service(Recorder::class)->entries())->toBe([
            'mail:ada@example.com:attempt=1',
            'report:q3:max=7',
        ]);

    $this->assertNothingEnqueued();
});

it('returns at once on an empty backend', function (): void {
    /** @var TestCase $this */
    expect($this->queuey()->work())->toBe(0)
        ->and($this->queuey()->workAll())->toBe(0);
});

it('stops at maxJobs', function (): void {
    /** @var TestCase $this */
    $queuey = $this->service(Queuey::class);
    $queuey->enqueue(new SendMail('ada@example.com'));
    $queuey->enqueue(new SendMail('bob@example.com'));

    expect($this->queuey()->work('default', 1))->toBe(1);
    $this->assertJobEnqueuedTimes(SendMail::class, 1);
});

it('leaves a held job alone until the clock is travelled past it', function (): void {
    /** @var TestCase $this */
    $this->service(Queuey::class)->enqueueAfter(new SendMail('later@example.com'), '30s');

    expect($this->queuey()->work())->toBe(0);
    $this->assertJobDelayed(SendMail::class);

    $this->queuey()->travel('30s');

    expect($this->queuey()->work())->toBe(1)
        ->and($this->service(Recorder::class)->entries())->toBe(['mail:later@example.com:attempt=1']);
    $this->assertNothingEnqueued();
});

it('drives a flaky job through its retries and a deferral through its hold', function (): void {
    /** @var TestCase $this */
    $queuey = $this->service(Queuey::class);
    $queuey->enqueue(new Flaky('deploy'));
    $queuey->enqueue(new DeferOnce('upstream'));

    // 3 attempts of `Flaky` + 2 runs of `DeferOnce` (the deferral does not burn one).
    expect($this->queuey()->workAll())->toBe(5)
        // One round per pass over the queues, `Images` (the deferral) before
        // `Reports` (the retry), so the two interleave.
        ->and($this->service(Recorder::class)->entries())->toBe([
            'defer:upstream:deferrals=0',
            'flaky:deploy:attempt=1',
            'defer:upstream:deferrals=1',
            'flaky:deploy:attempt=2',
            'flaky:deploy:attempt=3',
        ])
        ->and($this->service(MemoryBackend::class)->succeeded())->toBe(2);

    $this->assertNothingEnqueued();
    $this->assertNothingDeadLettered();
    expect(DeferringHandler::DELAY)->toBe('5s');
});

it('gives up rather than loop when a job is on a queue the worker does not consume', function (): void {
    /** @var TestCase $this */
    $this->service(Queuey::class)->enqueue(new BuildReport('q3'));

    // The `mail` worker consumes `Emails` only, so nothing can move.
    expect($this->queuey()->workAll('mail'))->toBe(0);
    $this->assertJobEnqueued(BuildReport::class);
});

it('runs only the named worker\'s handlers', function (): void {
    /** @var TestCase $this */
    $queuey = $this->service(Queuey::class);
    $queuey->enqueue(new Newsletter('spring'));
    $queuey->enqueue(new BuildReport('q3'));

    expect($this->queuey()->work('mail'))->toBe(1)
        ->and($this->service(Recorder::class)->entries())->toBe(['newsletter:spring']);

    $this->assertJobNotEnqueued(Newsletter::class);
    $this->assertJobEnqueued(BuildReport::class);
});

it('sees a job the worker gave up on, with the reason', function (): void {
    /** @var TestCase $this */
    $registry = $this->service(WorkerRegistry::class);
    // One attempt only, so `FlakyHandler` never gets to the run that succeeds.
    $registry->define($registry->get('default')->withJobRetry(Flaky::class, RetryPolicy::none()));

    $this->service(Queuey::class)->enqueue(new Flaky('deploy'));

    expect($this->queuey()->workAll())->toBe(1);

    $this->assertJobDeadLettered(Flaky::class);
    $this->assertJobDeadLettered(
        Flaky::class,
        fn(?Flaky $job, Envelope $envelope, string $reason): bool => $job?->label === 'deploy'
            && $envelope->attempt === 1
            && str_contains($reason, 'max attempts (1) exhausted'),
    );

    expect($this->queuey()->deadLettered(Flaky::class))->toHaveCount(1)
        ->and($this->service(Recorder::class)->entries())->toBe([
            'flaky:deploy:attempt=1',
            'dead:queuey_symfony_test.flaky:Exhausted',
        ]);

    expect(fn() => $this->assertNothingDeadLettered())
        ->toThrow(AssertionFailedError::class, 'queuey_symfony_test.flaky (1)');
});

it('dead-letters a job no worker handles and still decodes it for the callback', function (): void {
    /** @var TestCase $this */
    // `Newsletter` is the `mail` worker's job; `default` has no handler for it.
    $this->service(Queuey::class)->enqueue(new Newsletter('spring'));

    expect($this->queuey()->work())->toBe(1);

    $this->assertJobDeadLettered(
        Newsletter::class,
        fn(?Newsletter $job, Envelope $envelope, string $reason): bool => $job?->subject === 'spring'
            && str_contains($reason, 'no handler for job type'),
    );
    $this->assertNothingEnqueued();
});

it('exposes the same clock the container handed the backend', function (): void {
    /** @var TestCase $this */
    $before = $this->queuey()->clock()->nowMs();
    $this->queuey()->travel('2m');

    expect($this->service(Clock::class)->nowMs())->toBe($before + 120_000);
});

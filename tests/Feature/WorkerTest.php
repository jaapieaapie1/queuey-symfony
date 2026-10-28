<?php

declare(strict_types=1);

use Queuey\BackoffKind;
use Queuey\Duration;
use Queuey\Envelope;
use Queuey\FrozenClock;
use Queuey\Memory\MemoryBackend;
use Queuey\QueueConfig;
use Queuey\Symfony\Queuey;
use Queuey\Symfony\Tests\Fixtures\AppQueues;
use Queuey\Symfony\Tests\Fixtures\Handlers\ConfigOnlyHandler;
use Queuey\Symfony\Tests\Fixtures\Handlers\DeferringHandler;
use Queuey\Symfony\Tests\Fixtures\Handlers\FlakyHandler;
use Queuey\Symfony\Tests\Fixtures\Handlers\MailWorkerHandler;
use Queuey\Symfony\Tests\Fixtures\Handlers\RecordingHandler;
use Queuey\Symfony\Tests\Fixtures\Handlers\ReportHandler;
use Queuey\Symfony\Tests\Fixtures\Hooks\RecordingDeadLetterHook;
use Queuey\Symfony\Tests\Fixtures\Jobs\BuildReport;
use Queuey\Symfony\Tests\Fixtures\Jobs\DeferOnce;
use Queuey\Symfony\Tests\Fixtures\Jobs\Flaky;
use Queuey\Symfony\Tests\Fixtures\Jobs\Newsletter;
use Queuey\Symfony\Tests\Fixtures\Jobs\ResizeImage;
use Queuey\Symfony\Tests\Fixtures\Jobs\SendMail;
use Queuey\Symfony\Tests\Fixtures\Recorder;
use Queuey\Symfony\Tests\TestCase;
use Queuey\Worker;

/**
 * Run until nothing is waiting on any of the worker's queues.
 *
 * The first round never stops: `runUntil()` checks the predicate *before* it
 * waits, and only `MemoryBackend::wait()` releases the holds whose deadline the
 * clock has passed. Without that one forced round a `travel()` followed by a
 * drain would see an empty queue and return before the retry was let out.
 */
function drain(Worker $worker, MemoryBackend $backend): void
{
    $queues = array_map(static fn(QueueConfig $config): string => $config->name, $worker->queues());
    $round = 0;

    $worker->runUntil(static function () use ($backend, $queues, &$round): bool {
        if ($round++ === 0) {
            return false;
        }

        foreach ($queues as $queue) {
            if ($backend->pending($queue) !== []) {
                return false;
            }
        }

        return true;
    });
}

it('builds the default worker from the config list and the autoconfigured services', function (): void {
    /** @var TestCase $this */
    $definition = $this->service(Queuey::class)->definition('default');

    expect($definition->handlers)->toBe([
        ConfigOnlyHandler::class,
        RecordingHandler::class,
        ReportHandler::class,
        FlakyHandler::class,
        DeferringHandler::class,
    ])->and($definition->deadLetterHooks)->toBe([RecordingDeadLetterHook::class]);
});

it('gives the mail worker only the handler its attribute targets', function (): void {
    /** @var TestCase $this */
    $definition = $this->service(Queuey::class)->definition('mail');

    expect($definition->handlers)->toBe([MailWorkerHandler::class])
        ->and($definition->deadLetterHooks)->toBe([])
        ->and($definition->only)->toBe(['Emails'])
        ->and($definition->maxJobs)->toBe(5);
});

it('registers the right job types on each worker', function (): void {
    /** @var TestCase $this */
    $queuey = $this->service(Queuey::class);

    $default = $queuey->worker();
    expect($default->retryPolicyFor('queuey_symfony_test.send_mail'))->not->toBeNull()
        ->and($default->retryPolicyFor('queuey_symfony_test.newsletter'))->toBeNull()
        ->and($default->queues())->toHaveCount(3);

    $mail = $queuey->worker('mail');
    expect($mail->retryPolicyFor('queuey_symfony_test.newsletter'))->not->toBeNull()
        ->and($mail->retryPolicyFor('queuey_symfony_test.send_mail'))->toBeNull()
        ->and($mail->queues())->toHaveCount(1)
        ->and($mail->queues()[0]->name)->toBe('queuey_symfony_test.emails');
});

it('applies the configured retry overrides on top of the queue attributes', function (): void {
    /** @var TestCase $this */
    $worker = $this->service(Queuey::class)->worker();

    // `queuey.workers.default.retry_overrides.queues.Images` beats the queue's own default.
    $images = $worker->retryPolicyFor('queuey_symfony_test.resize_image');
    expect($images?->maxAttempts)->toBe(2)
        ->and($images?->backoff->kind())->toBe(BackoffKind::Fixed)
        ->and($images?->backoff->delay()->toMs())->toBe(1000);

    // `#[Queue(retry: ...)]` on AppQueues::Reports, untouched.
    expect($worker->retryPolicyFor('queuey_symfony_test.build_report')?->maxAttempts)->toBe(3);
});

it('builds a fresh worker every call', function (): void {
    /** @var TestCase $this */
    $queuey = $this->service(Queuey::class);

    expect($queuey->worker())->not->toBe($queuey->worker());
});

it('runs a handler the container built, with its dependency injected', function (): void {
    /** @var TestCase $this */
    $queuey = $this->service(Queuey::class);
    $backend = $this->service(MemoryBackend::class);
    $recorder = $this->service(Recorder::class);

    $queuey->enqueue(new SendMail('ada@example.com'));
    $queuey->enqueue(new ResizeImage('/tmp/a.png'));
    $queuey->enqueue(new BuildReport('q3'));

    drain($queuey->worker(), $backend);

    expect($recorder->entries())->toBe([
        'mail:ada@example.com:attempt=1',
        'resize:/tmp/a.png',
        // 7, not the queue's 3: `ReportHandler` is a `ProvidesRetryPolicy` and
        // the event decorator delegates to it (INTEROP-SPEC §5).
        'report:q3:max=7',
    ])->and($backend->succeeded())->toBe(3)
        ->and($backend->pending(AppQueues::Emails->queueName()))->toBe([]);
});

it('lets the queue policy stand when the handler has no opinion', function (): void {
    /** @var TestCase $this */
    $queuey = $this->service(Queuey::class);

    $queuey->enqueue(new BuildReport(ReportHandler::INHERIT));
    drain($queuey->worker(), $this->service(MemoryBackend::class));

    // `retryPolicyFor()` returned `null`, so `#[Queue(retry: ...)]` on
    // AppQueues::Reports decided: 3 attempts.
    expect($this->service(Recorder::class)->entries())->toBe(['report:inherit:max=3']);
});

it('leaves another worker\'s jobs alone', function (): void {
    /** @var TestCase $this */
    $queuey = $this->service(Queuey::class);
    $backend = $this->service(MemoryBackend::class);
    $recorder = $this->service(Recorder::class);

    $queuey->enqueue(new Newsletter('spring'));
    $queuey->enqueue(new SendMail('ada@example.com'));

    drain($queuey->worker('mail'), $backend);

    expect($recorder->entries())->toBe(['newsletter:spring']);
    // `SendMail` has no handler on the mail worker, so it was dead-lettered.
    expect($backend->dead(AppQueues::Emails->queueName()))->toHaveCount(1);
});

it('retries a failing job until the policy runs out of patience', function (): void {
    /** @var TestCase $this */
    $queuey = $this->service(Queuey::class);
    $backend = $this->service(MemoryBackend::class);
    $clock = $this->service(FrozenClock::class);
    $recorder = $this->service(Recorder::class);

    $queuey->enqueue(new Flaky('deploy'));

    for ($round = 0; $round < FlakyHandler::SUCCEEDS_ON; ++$round) {
        drain($queuey->worker(), $backend);
        $clock->advance(Duration::seconds(2));
    }

    expect($recorder->entries())->toBe([
        'flaky:deploy:attempt=1',
        'flaky:deploy:attempt=2',
        'flaky:deploy:attempt=3',
    ])->and($backend->retried())->toBe(2)
        ->and($backend->succeeded())->toBe(1)
        ->and($backend->dead(AppQueues::Reports->queueName()))->toBe([]);
});

it('brings a deferred job back without burning an attempt', function (): void {
    /** @var TestCase $this */
    $queuey = $this->service(Queuey::class);
    $backend = $this->service(MemoryBackend::class);
    $clock = $this->service(FrozenClock::class);
    $recorder = $this->service(Recorder::class);

    $queuey->enqueue(new DeferOnce('upstream'));

    drain($queuey->worker(), $backend);
    expect($backend->held(AppQueues::Images->queueName()))->toHaveCount(1);

    $clock->advance(Duration::parse(DeferringHandler::DELAY));
    drain($queuey->worker(), $backend);

    expect($recorder->entries())->toBe([
        'defer:upstream:deferrals=0',
        'defer:upstream:deferrals=1',
    ])->and($backend->succeeded())->toBe(1);
});

it('notifies the dead-letter hook the container built', function (): void {
    /** @var TestCase $this */
    $queuey = $this->service(Queuey::class);
    $backend = $this->service(MemoryBackend::class);
    $recorder = $this->service(Recorder::class);

    // No handler claims this job type on any worker, so it is dead-lettered.
    $queuey->producer(AppQueues::class);
    $envelope = Envelope::raw('queuey_symfony_test.unknown', AppQueues::Emails->queueName(), (object) []);
    $backend->publish($envelope);

    drain($queuey->worker(), $backend);

    expect($recorder->entries())->toBe(['dead:queuey_symfony_test.unknown:NoHandler'])
        ->and($backend->dead(AppQueues::Emails->queueName()))->toHaveCount(1);
});

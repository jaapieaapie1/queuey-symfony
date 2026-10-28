<?php

declare(strict_types=1);

use Psr\Log\LogLevel;
use Queuey\Duration;
use Queuey\Exception\FatalFailure;
use Queuey\Exception\JobDeferred;
use Queuey\Exception\RetryableFailure;
use Queuey\FnHandler;
use Queuey\FrozenClock;
use Queuey\Job;
use Queuey\JobContext;
use Queuey\JobHandler;
use Queuey\ProvidesRetryPolicy;
use Queuey\RetryPolicy;
use Queuey\Symfony\Event\JobDeferring;
use Queuey\Symfony\Event\JobFailed;
use Queuey\Symfony\Event\JobProcessed;
use Queuey\Symfony\Event\JobProcessing;
use Queuey\Symfony\Handler\EventDispatchingHandler;
use Queuey\Symfony\Tests\Fixtures\Jobs\SendMail;
use Queuey\Symfony\Tests\Fixtures\RecordingLogger;
use Symfony\Component\EventDispatcher\EventDispatcher;

function jobContext(int $attempt = 1, int $maxAttempts = 3): JobContext
{
    return new JobContext(
        jobId: 'job-1',
        jobType: 'queuey_symfony_test.send_mail',
        queue: 'queuey_symfony_test.emails',
        attempt: $attempt,
        maxAttempts: $maxAttempts,
    );
}

/**
 * @template T of Job
 *
 * @param JobHandler<T> $inner
 * @param list<object>  $seen
 *
 * @return EventDispatchingHandler<T>
 */
function decorated(JobHandler $inner, array &$seen, ?EventDispatcher $dispatcher = null, ?RecordingLogger $logger = null, ?FrozenClock $clock = null): EventDispatchingHandler
{
    $dispatcher ??= new EventDispatcher();
    foreach ([JobProcessing::class, JobProcessed::class, JobFailed::class, JobDeferring::class] as $event) {
        $dispatcher->addListener($event, static function (object $e) use (&$seen): void {
            $seen[] = $e;
        });
    }

    return new EventDispatchingHandler($inner, $dispatcher, $logger ?? new RecordingLogger(), $clock ?? new FrozenClock(1_000));
}

it('delegates jobClass and announces a successful run', function (): void {
    $clock = new FrozenClock(1_000);
    $seen = [];
    $handler = decorated(
        FnHandler::for(SendMail::class, static function (SendMail $job, JobContext $ctx) use ($clock): void {
            $clock->advance(Duration::ms(12));
        }),
        $seen,
        clock: $clock,
    );

    expect($handler->jobClass())->toBe(SendMail::class);

    $handler->handle(new SendMail('a@b.c'), jobContext());

    expect($seen)->toHaveCount(2)
        ->and($seen[0])->toBeInstanceOf(JobProcessing::class)
        ->and($seen[1])->toBeInstanceOf(JobProcessed::class);

    $processed = $seen[1];
    \assert($processed instanceof JobProcessed);
    expect($processed->runtime->toMs())->toBe(12)
        ->and($processed->job)->toBeInstanceOf(SendMail::class);
});

it('announces a failure and rethrows the very same exception', function (): void {
    $boom = new RetryableFailure('boom');
    $seen = [];
    $handler = decorated(
        FnHandler::for(SendMail::class, static function () use ($boom): void {
            throw $boom;
        }),
        $seen,
    );

    $caught = null;

    try {
        $handler->handle(new SendMail('a@b.c'), jobContext(attempt: 1));
    } catch (RetryableFailure $e) {
        $caught = $e;
    }

    // The very same instance, not a copy: the decorator observes, it does not wrap.
    expect($caught)->toBe($boom);

    expect($seen)->toHaveCount(2);
    $failed = $seen[1];
    \assert($failed instanceof JobFailed);
    expect($failed->exception)->toBe($boom)
        ->and($failed->willRetry)->toBeTrue();
});

it('reads willRetry from the attempt count and from FatalFailure', function (): void {
    $seen = [];
    $handler = decorated(
        FnHandler::for(SendMail::class, static function (): void {
            throw new RetryableFailure('boom');
        }),
        $seen,
    );

    try {
        $handler->handle(new SendMail('a@b.c'), jobContext(attempt: 3, maxAttempts: 3));
    } catch (RetryableFailure) {
    }

    $lastAttempt = $seen[1];
    \assert($lastAttempt instanceof JobFailed);
    expect($lastAttempt->willRetry)->toBeFalse();

    $seen = [];
    $fatal = decorated(
        FnHandler::for(SendMail::class, static function (): void {
            throw new FatalFailure('never');
        }),
        $seen,
    );

    try {
        $fatal->handle(new SendMail('a@b.c'), jobContext(attempt: 1, maxAttempts: 9));
    } catch (FatalFailure) {
    }

    $event = $seen[1];
    \assert($event instanceof JobFailed);
    expect($event->willRetry)->toBeFalse();
});

it('announces a deferral as its own event', function (): void {
    $deferred = JobDeferred::for(Duration::seconds(5), 'later');
    $seen = [];
    $handler = decorated(
        FnHandler::for(SendMail::class, static function () use ($deferred): void {
            throw $deferred;
        }),
        $seen,
    );

    expect(fn() => $handler->handle(new SendMail('a@b.c'), jobContext()))->toThrow(JobDeferred::class);

    expect($seen[1])->toBeInstanceOf(JobDeferring::class);
    $event = $seen[1];
    \assert($event instanceof JobDeferring);
    expect($event->deferral)->toBe($deferred);
});

it('logs a throwing listener and leaves the outcome alone', function (): void {
    $dispatcher = new EventDispatcher();
    $dispatcher->addListener(JobProcessed::class, static function (): void {
        throw new \RuntimeException('listener exploded');
    });
    $logger = new RecordingLogger();
    $ran = false;
    $handler = new EventDispatchingHandler(
        FnHandler::for(SendMail::class, static function () use (&$ran): void {
            $ran = true;
        }),
        $dispatcher,
        $logger,
        new FrozenClock(0),
    );

    $handler->handle(new SendMail('a@b.c'), jobContext());

    expect($ran)->toBeTrue()
        ->and($logger->messages(LogLevel::ERROR))->toBe(['queuey event listener failed']);
});

it('delegates retryPolicyFor, and answers null when the inner handler has no opinion', function (): void {
    $policy = RetryPolicy::fixed(4, Duration::seconds(3));

    /**
     * @implements JobHandler<SendMail>
     * @implements ProvidesRetryPolicy<SendMail>
     */
    $opinionated = new class ($policy) implements JobHandler, ProvidesRetryPolicy {
        public function __construct(private readonly ?RetryPolicy $policy) {}

        public function jobClass(): string
        {
            return SendMail::class;
        }

        public function retryPolicyFor(Job $job): ?RetryPolicy
        {
            return $this->policy;
        }

        public function handle(Job $job, JobContext $ctx): void {}
    };

    $seen = [];
    expect(decorated($opinionated, $seen)->retryPolicyFor(new SendMail('a@b.c')))->toBe($policy);

    $seen = [];
    $plain = decorated(FnHandler::for(SendMail::class, static function (): void {}), $seen);
    // `null` is "no opinion", not "no retries": core falls through to the
    // worker's overrides, the job's policy and then the queue's.
    expect($plain->retryPolicyFor(new SendMail('a@b.c')))->toBeNull();
});

it('exposes the handler it wraps', function (): void {
    $inner = FnHandler::for(SendMail::class, static function (): void {});
    $seen = [];

    expect(decorated($inner, $seen)->inner())->toBe($inner);
});

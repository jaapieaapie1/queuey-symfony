<?php

declare(strict_types=1);

namespace Queuey\Symfony\Handler;

use Psr\Log\LoggerInterface;
use Queuey\Clock;
use Queuey\Duration;
use Queuey\Exception\FatalFailure;
use Queuey\Exception\JobDeferred;
use Queuey\Job;
use Queuey\JobContext;
use Queuey\JobHandler;
use Queuey\ProvidesRetryPolicy;
use Queuey\RetryPolicy;
use Queuey\Symfony\Event\JobDeferring;
use Queuey\Symfony\Event\JobFailed;
use Queuey\Symfony\Event\JobProcessed;
use Queuey\Symfony\Event\JobProcessing;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Wraps a {@see JobHandler} so every run is announced on the event dispatcher,
 * and nothing else.
 *
 * The decorator is transparent by construction: a listener that throws is logged
 * and dropped, and the handler's own outcome — its return, or the exact
 * exception instance it threw — travels on untouched.
 *
 * @template T of Job
 *
 * @implements JobHandler<T>
 * @implements ProvidesRetryPolicy<T>
 */
final class EventDispatchingHandler implements JobHandler, ProvidesRetryPolicy
{
    /**
     * @param JobHandler<T> $inner
     */
    public function __construct(
        private readonly JobHandler $inner,
        private readonly EventDispatcherInterface $events,
        private readonly LoggerInterface $logger,
        private readonly Clock $clock,
    ) {}

    /**
     * The handler this decorator wraps, for tests and for introspection.
     *
     * @return JobHandler<T>
     */
    public function inner(): JobHandler
    {
        return $this->inner;
    }

    public function jobClass(): string
    {
        return $this->inner->jobClass();
    }

    /**
     * Delegate when the inner handler has an opinion, and `null` otherwise.
     *
     * `null` is not "no retries": {@see \Queuey\WorkerBuilder::handler()} keeps
     * the decision open and `build()` falls through to the worker's overrides,
     * the job's own policy and then the queue's (INTEROP-SPEC §5, and
     * `WorkerBuilder.php` around line 106 where `policyFor` is bound). Returning
     * `RetryPolicy::none()` here would silently disable every configured retry.
     *
     * @param T $job
     */
    public function retryPolicyFor(Job $job): ?RetryPolicy
    {
        return $this->inner instanceof ProvidesRetryPolicy
            ? $this->inner->retryPolicyFor($job)
            : null;
    }

    /**
     * @param T $job
     */
    public function handle(Job $job, JobContext $ctx): void
    {
        $startedAtMs = $this->clock->nowMs();
        $this->dispatch(new JobProcessing($job, $ctx));

        try {
            $this->inner->handle($job, $ctx);
        } catch (JobDeferred $deferred) {
            $this->dispatch(new JobDeferring($job, $ctx, $deferred));

            throw $deferred;
        } catch (\Throwable $e) {
            // The worker has not consulted the policy yet, so this is the best
            // reading available: a fatal failure never retries, and a policy that
            // is out of attempts dead-letters. `JobDeadLettered` is the event that
            // states what actually happened.
            $willRetry = !$e instanceof FatalFailure && !$ctx->isLastAttempt();
            $this->dispatch(new JobFailed($job, $ctx, $e, $willRetry));

            throw $e;
        }

        $runtime = Duration::ms(max(0, $this->clock->nowMs() - $startedAtMs));
        $this->dispatch(new JobProcessed($job, $ctx, $runtime));
    }

    /** Dispatch `$event`, absorbing whatever a listener does with it. */
    private function dispatch(object $event): void
    {
        try {
            $this->events->dispatch($event);
        } catch (\Throwable $e) {
            $this->logger->error('queuey event listener failed', [
                'event' => $event::class,
                'error' => \sprintf('%s: %s', $e::class, $e->getMessage()),
            ]);
        }
    }
}

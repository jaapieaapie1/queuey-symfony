<?php

declare(strict_types=1);

namespace Queuey\Symfony\Test;

use Queuey\Envelope;
use Queuey\Job;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Queue assertions for a booted-kernel test case.
 *
 * Works in `KernelTestCase` and in `WebTestCase` (which extends it): everything
 * goes through `static::getContainer()`, which hands out the bundle's private
 * services too. The kernel is rebooted per test, so each test starts with an
 * empty backend and a clock frozen at that moment.
 *
 * Needs `backend: memory` and `clock: frozen` — put them behind `when@test:` in
 * `config/packages/queuey.yaml`.
 *
 * ```php
 * final class SignupTest extends WebTestCase
 * {
 *     use QueueyAssertionsTrait;
 *
 *     public function testItQueuesTheWelcomeMail(): void
 *     {
 *         // ... act ...
 *         $this->assertJobEnqueued(SendWelcomeMail::class, fn (SendWelcomeMail $job) => $job->to === 'a@b.c');
 *         $this->queuey()->workAll();
 *         $this->assertNothingEnqueued();
 *     }
 * }
 * ```
 *
 * @phpstan-require-extends KernelTestCase
 */
trait QueueyAssertionsTrait
{
    /** The fake over the in-process backend: assertions, `travel()`, `work()`. */
    public function queuey(): QueueyFake
    {
        $fake = static::getContainer()->get(QueueyFake::class);
        \assert($fake instanceof QueueyFake);

        return $fake;
    }

    /**
     * @param class-string<J>                    $jobClass
     * @param (callable(J, Envelope): bool)|null $callback
     *
     * @template J of Job
     */
    public function assertJobEnqueued(string $jobClass, ?callable $callback = null): void
    {
        $this->queuey()->assertEnqueued($jobClass, $callback);
    }

    /**
     * @param class-string<Job> $jobClass
     */
    public function assertJobEnqueuedTimes(string $jobClass, int $times): void
    {
        $this->queuey()->assertEnqueuedTimes($jobClass, $times);
    }

    /**
     * @param class-string<J>                    $jobClass
     * @param (callable(J, Envelope): bool)|null $callback
     *
     * @template J of Job
     */
    public function assertJobNotEnqueued(string $jobClass, ?callable $callback = null): void
    {
        $this->queuey()->assertNotEnqueued($jobClass, $callback);
    }

    public function assertNothingEnqueued(): void
    {
        $this->queuey()->assertNothingEnqueued();
    }

    /**
     * @param class-string<J>                    $jobClass
     * @param (callable(J, Envelope): bool)|null $callback
     *
     * @template J of Job
     */
    public function assertJobDelayed(string $jobClass, ?callable $callback = null): void
    {
        $this->queuey()->assertDelayed($jobClass, $callback);
    }

    /**
     * @param class-string<J>                                 $jobClass
     * @param (callable(J|null, Envelope, string): bool)|null $callback
     *
     * @template J of Job
     */
    public function assertJobDeadLettered(string $jobClass, ?callable $callback = null): void
    {
        $this->queuey()->assertDeadLettered($jobClass, $callback);
    }

    public function assertNothingDeadLettered(): void
    {
        $this->queuey()->assertNothingDeadLettered();
    }
}

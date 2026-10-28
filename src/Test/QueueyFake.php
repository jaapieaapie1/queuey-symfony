<?php

declare(strict_types=1);

namespace Queuey\Symfony\Test;

use PHPUnit\Framework\Assert;
use Queuey\Clock;
use Queuey\Duration;
use Queuey\Envelope;
use Queuey\Exception\DecodeError;
use Queuey\FrozenClock;
use Queuey\Internal\JobMetadata;
use Queuey\Job;
use Queuey\Memory\MemoryBackend;
use Queuey\QueueConfig;
use Queuey\Symfony\Exception\InvalidQueueyConfiguration;
use Queuey\Symfony\Queuey;
use Queuey\Symfony\WorkerFactory;
use Queuey\Symfony\WorkerRegistry;
use Queuey\Worker;
use Queuey\WorkerBuilder;

/**
 * The in-process test double: assertions over what was published, plus
 * `work()` / `workAll()` to run the real handlers with real DI.
 *
 * Registered as a service only when `backend: memory`, and only useful with
 * `clock: frozen` — which the constructor insists on, because `travel()` and the
 * hold releases are the whole point.
 *
 * Nothing here is a stand-in for the queue: the jobs really are published, the
 * real {@see Worker} really consumes them and the container really builds the
 * handlers. Only the broker and the passage of time are in-process.
 */
final class QueueyFake
{
    /**
     * How far {@see self::workAll()} moves the clock per round.
     *
     * The memory backend keeps a hold's due time to itself — `held()` hands back
     * envelopes, not deadlines — so "release everything" is a jump big enough to
     * cover any delay a test would plausibly wait on. One hour a round, capped at
     * {@see self::MAX_ROUNDS}, covers about six weeks of virtual time.
     */
    private const RELEASE_STEP_MS = 3_600_000;

    /** The bail-out for a workload that will not settle; see {@see self::workAll()}. */
    private const MAX_ROUNDS = 1000;

    private readonly FrozenClock $clock;

    /**
     * @param Clock $clock the container's `Queuey\Clock`; must be a {@see FrozenClock}
     *
     * @throws InvalidQueueyConfiguration when the configured clock is not frozen,
     *                                    or the manager publishes somewhere else
     */
    public function __construct(
        Queuey $queuey,
        private readonly WorkerFactory $factory,
        private readonly WorkerRegistry $workers,
        private readonly MemoryBackend $backend,
        Clock $clock,
    ) {
        if (!$clock instanceof FrozenClock) {
            throw InvalidQueueyConfiguration::forKey(
                'queuey.clock',
                \sprintf('the fake needs `frozen`, got %s', $clock::class),
            );
        }
        if ($queuey->backend() !== $backend) {
            // Assertions over what was published are worthless if the manager
            // publishes into another backend than the one they read.
            throw InvalidQueueyConfiguration::forKey(
                'queuey.backend',
                'the fake and the manager must share one backend',
            );
        }

        $this->clock = $clock;
    }

    public function backend(): MemoryBackend
    {
        return $this->backend;
    }

    public function clock(): FrozenClock
    {
        return $this->clock;
    }

    /** The named worker, built on the fake backend. */
    public function worker(string $name = 'default'): Worker
    {
        return $this->factory->make($this->workers->get($name), $this->backend);
    }

    /**
     * Move the frozen clock forward. Holds whose delay has elapsed are released
     * by the next `work()` — only {@see MemoryBackend::wait()} lets them out.
     */
    public function travel(Duration|string $by): void
    {
        $this->clock->advance(\is_string($by) ? Duration::parse($by) : $by);
    }

    /**
     * Assert `$jobClass` was published — waiting now, or held for later.
     *
     * @param class-string<J>                  $jobClass
     * @param (callable(J, Envelope): bool)|null $callback
     *
     * @template J of Job
     */
    public function assertEnqueued(string $jobClass, ?callable $callback = null): void
    {
        $envelopes = $this->envelopes();

        self::assert(
            $this->matching($envelopes, $jobClass, $callback) !== [],
            static fn(): string => \sprintf(
                'Expected %s to be enqueued%s; %s.',
                $jobClass,
                $callback === null ? '' : ' matching the callback',
                self::summarise($envelopes),
            ),
        );
    }

    /**
     * @param class-string<Job> $jobClass
     */
    public function assertEnqueuedTimes(string $jobClass, int $times): void
    {
        $envelopes = $this->envelopes();
        $found = \count($this->matching($envelopes, $jobClass));

        // `assertSame` takes a string, so the summary is skipped by hand here.
        Assert::assertSame($times, $found, $times === $found ? '' : \sprintf(
            'Expected %s to be enqueued %d time(s), found %d; %s.',
            $jobClass,
            $times,
            $found,
            self::summarise($envelopes),
        ));
    }

    /**
     * @param class-string<J>                  $jobClass
     * @param (callable(J, Envelope): bool)|null $callback
     *
     * @template J of Job
     */
    public function assertNotEnqueued(string $jobClass, ?callable $callback = null): void
    {
        $envelopes = $this->envelopes();

        self::assert(
            $this->matching($envelopes, $jobClass, $callback) === [],
            static fn(): string => \sprintf(
                'Expected %s not to be enqueued%s; %s.',
                $jobClass,
                $callback === null ? '' : ' matching the callback',
                self::summarise($envelopes),
            ),
        );
    }

    public function assertNothingEnqueued(): void
    {
        $envelopes = $this->envelopes();

        self::assert($envelopes === [], static fn(): string => \sprintf(
            'Expected nothing to be enqueued; %s.',
            self::summarise($envelopes),
        ));
    }

    /**
     * Assert `$jobClass` is being held: `enqueueAfter()`, `defer()` or a retry
     * whose backoff has not elapsed.
     *
     * @param class-string<J>                  $jobClass
     * @param (callable(J, Envelope): bool)|null $callback
     *
     * @template J of Job
     */
    public function assertDelayed(string $jobClass, ?callable $callback = null): void
    {
        $held = $this->heldEnvelopes();

        self::assert(
            $this->matching($held, $jobClass, $callback) !== [],
            static fn(): string => \sprintf(
                'Expected %s to be delayed%s; %s.',
                $jobClass,
                $callback === null ? '' : ' matching the callback',
                self::summarise($held, 'delayed'),
            ),
        );
    }

    /**
     * @param class-string<J>                                 $jobClass
     * @param (callable(J|null, Envelope, string): bool)|null $callback receives the decoded job
     *                                                                  (`null` when the payload
     *                                                                  does not decode), the
     *                                                                  envelope and the
     *                                                                  dead-letter reason
     *
     * @template J of Job
     */
    public function assertDeadLettered(string $jobClass, ?callable $callback = null): void
    {
        $entries = $this->deadEntries();

        self::assert(
            self::matchingDead($entries, $jobClass, $callback) !== [],
            static fn(): string => \sprintf(
                'Expected %s to be dead-lettered%s; %s.',
                $jobClass,
                $callback === null ? '' : ' matching the callback',
                self::summariseDead($entries),
            ),
        );
    }

    public function assertNothingDeadLettered(): void
    {
        $entries = $this->deadEntries();

        self::assert($entries === [], static fn(): string => \sprintf(
            'Expected nothing to be dead-lettered; %s.',
            self::summariseDead($entries),
        ));
    }

    /**
     * Every published `$jobClass`, decoded — pending and held alike.
     *
     * @param class-string<J> $jobClass
     *
     * @return list<J>
     *
     * @template J of Job
     */
    public function enqueued(string $jobClass): array
    {
        return $this->decodeAll($this->matching($this->envelopes(), $jobClass), $jobClass);
    }

    /**
     * @param class-string<J> $jobClass
     *
     * @return list<J>
     *
     * @template J of Job
     */
    public function delayed(string $jobClass): array
    {
        return $this->decodeAll($this->matching($this->heldEnvelopes(), $jobClass), $jobClass);
    }

    /**
     * @param class-string<Job> $jobClass
     *
     * @return list<array{envelope: Envelope, reason: string}>
     */
    public function deadLettered(string $jobClass): array
    {
        return self::matchingDead($this->deadEntries(), $jobClass);
    }

    /**
     * `Assert::assertTrue()` with the failure message built only on failure.
     *
     * Every assertion here summarises the whole backend for its message, which
     * means walking and counting every envelope. That work is wasted on the
     * passing case — which is nearly every case — so the message is a closure,
     * and each assertion scans the backend once rather than once per clause.
     *
     * @param callable(): string $message
     */
    private static function assert(bool $condition, callable $message): void
    {
        Assert::assertTrue($condition, $condition ? '' : $message());
    }

    /**
     * Run `$worker`'s handlers over everything waiting on its queues right now.
     *
     * The first round always waits: `Worker::runUntil()` checks the predicate
     * *before* `wait()`, and only {@see MemoryBackend::wait()} releases the holds
     * the clock has passed. Without that forced round a `travel()` followed by a
     * `work()` would see empty queues and return before the retry was let out.
     *
     * A frozen clock plus the memory backend's no-op sleeper makes an idle
     * `wait()` return at once, so an empty backend costs one round, not a spin.
     *
     * @return int jobs processed
     */
    public function work(string $worker = 'default', ?int $maxJobs = null): int
    {
        $built = $this->factory->make(
            $this->workers->get($worker),
            $this->backend,
            $maxJobs === null ? null : static function (WorkerBuilder $builder) use ($maxJobs): void {
                $builder->maxJobs($maxJobs);
            },
        );

        $queues = array_map(static fn(QueueConfig $config): string => $config->name, $built->queues());
        $round = 0;

        $built->runUntil(function () use ($queues, &$round): bool {
            if ($round++ === 0) {
                return false;
            }

            foreach ($queues as $queue) {
                if ($this->backend->pending($queue) !== []) {
                    return false;
                }
            }

            return true;
        });

        return $built->handle()->processed();
    }

    /**
     * Drain the queue: release every hold as if its delay had elapsed, work, and
     * go round again, because a retry or a deferral puts the job straight back
     * into hold.
     *
     * Stops when nothing is pending and nothing is held, or when a round changed
     * nothing and there is nothing left to release — a job on a queue this worker
     * does not consume never moves, and waiting for it would hang the test.
     *
     * @return int jobs processed across every round
     *
     * @throws \LogicException when the workload has not settled after
     *                         {@see self::MAX_ROUNDS} rounds
     */
    public function workAll(string $worker = 'default'): int
    {
        $processed = 0;

        for ($round = 0; $round < self::MAX_ROUNDS; ++$round) {
            $pendingBefore = \count($this->pendingEnvelopes());
            if ($this->heldEnvelopes() !== []) {
                $this->travel(Duration::ms(self::RELEASE_STEP_MS));
            }

            $processed += $this->work($worker);

            $pending = \count($this->pendingEnvelopes());
            $held = \count($this->heldEnvelopes());
            if ($pending === 0 && $held === 0) {
                return $processed;
            }
            if ($held === 0 && $pending === $pendingBefore) {
                // Nothing left to release and nothing moved: whatever is still
                // waiting belongs to a queue this worker does not consume.
                return $processed;
            }
        }

        throw new \LogicException(\sprintf(
            'queuey: workAll(%s) gave up after %d rounds; %s, %s.',
            $worker,
            self::MAX_ROUNDS,
            self::summarise($this->pendingEnvelopes()),
            self::summarise($this->heldEnvelopes(), 'delayed'),
        ));
    }

    /**
     * Everything published and not yet consumed: pending first, then held.
     *
     * @return list<Envelope>
     */
    private function envelopes(): array
    {
        return [...$this->pendingEnvelopes(), ...$this->heldEnvelopes()];
    }

    /**
     * @return list<Envelope>
     */
    private function pendingEnvelopes(): array
    {
        $envelopes = [];
        foreach ($this->queues() as $queue) {
            foreach ($this->backend->pending($queue) as $envelope) {
                $envelopes[] = $envelope;
            }
        }

        return $envelopes;
    }

    /**
     * @return list<Envelope>
     */
    private function heldEnvelopes(): array
    {
        $envelopes = [];
        foreach ($this->queues() as $queue) {
            foreach ($this->backend->held($queue) as $envelope) {
                $envelopes[] = $envelope;
            }
        }

        return $envelopes;
    }

    /**
     * @return list<array{envelope: Envelope, reason: string}>
     */
    private function deadEntries(): array
    {
        $entries = [];
        foreach ($this->queues() as $queue) {
            foreach ($this->backend->dead($queue) as $entry) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    /**
     * The queues worth looking at: every queue the backend has declared, plus
     * every queue of every configured worker's set — so an assertion works
     * before anything was published and with `declare_on_publish: false`.
     *
     * @return list<string>
     */
    private function queues(): array
    {
        $names = $this->backend->declared();
        foreach ($this->workers->all() as $definition) {
            foreach ($definition->queueSet::all() as $case) {
                $names[] = $case->queueName();
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * @param list<Envelope>                     $envelopes
     * @param class-string<J>                    $jobClass
     * @param (callable(J, Envelope): bool)|null $callback
     *
     * @return list<Envelope>
     *
     * @template J of Job
     */
    private function matching(array $envelopes, string $jobClass, ?callable $callback = null): array
    {
        $wireName = self::wireName($jobClass);
        $matches = [];

        foreach ($envelopes as $envelope) {
            if ($envelope->jobType !== $wireName) {
                continue;
            }
            if ($callback === null) {
                $matches[] = $envelope;

                continue;
            }

            $job = self::decode($envelope, $jobClass);
            if ($job !== null && $callback($job, $envelope)) {
                $matches[] = $envelope;
            }
        }

        return $matches;
    }

    /**
     * @param list<array{envelope: Envelope, reason: string}> $entries
     * @param class-string<J>                                 $jobClass
     * @param (callable(J|null, Envelope, string): bool)|null $callback
     *
     * @return list<array{envelope: Envelope, reason: string}>
     *
     * @template J of Job
     */
    private static function matchingDead(array $entries, string $jobClass, ?callable $callback = null): array
    {
        $wireName = self::wireName($jobClass);
        $matches = [];

        foreach ($entries as $entry) {
            if ($entry['envelope']->jobType !== $wireName) {
                continue;
            }
            if ($callback === null || $callback(self::decode($entry['envelope'], $jobClass), $entry['envelope'], $entry['reason'])) {
                $matches[] = $entry;
            }
        }

        return $matches;
    }

    /**
     * @param list<Envelope>  $envelopes
     * @param class-string<J> $jobClass
     *
     * @return list<J>
     *
     * @template J of Job
     */
    private function decodeAll(array $envelopes, string $jobClass): array
    {
        $jobs = [];
        foreach ($envelopes as $envelope) {
            $job = self::decode($envelope, $jobClass);
            if ($job !== null) {
                $jobs[] = $job;
            }
        }

        return $jobs;
    }

    /**
     * @param list<Envelope> $envelopes
     */
    private static function summarise(array $envelopes, string $verb = 'enqueued'): string
    {
        return $envelopes === []
            ? 'nothing is ' . $verb
            : $verb . ': ' . self::counts(array_map(static fn(Envelope $e): string => $e->jobType, $envelopes));
    }

    /**
     * @param list<array{envelope: Envelope, reason: string}> $entries
     */
    private static function summariseDead(array $entries): string
    {
        return $entries === []
            ? 'nothing was dead-lettered'
            : 'dead-lettered: ' . self::counts(array_map(
                static fn(array $entry): string => $entry['envelope']->jobType,
                $entries,
            ));
    }

    /**
     * `job.type (2), other.type (1)`, in alphabetical order so a failure message
     * reads the same every run.
     *
     * @param list<string> $jobTypes
     */
    private static function counts(array $jobTypes): string
    {
        $counts = array_count_values($jobTypes);
        ksort($counts);

        $parts = [];
        foreach ($counts as $type => $count) {
            $parts[] = \sprintf('%s (%d)', $type, $count);
        }

        return implode(', ', $parts);
    }

    /**
     * The `job_type` `$jobClass` goes on the wire as, `#[OnQueue(name:)]` and all.
     *
     * `JobMetadata` is `@internal` to core, but this package is the same project
     * and there is no public accessor for a job's wire name yet; kept in one
     * method so it is a one-line change if core grows one.
     *
     * @param class-string<Job> $jobClass
     */
    private static function wireName(string $jobClass): string
    {
        return JobMetadata::for($jobClass)->name;
    }

    /**
     * @param class-string<J> $jobClass
     *
     * @return J|null `null` when the payload does not decode into `$jobClass`
     *
     * @template J of Job
     */
    private static function decode(Envelope $envelope, string $jobClass): ?Job
    {
        try {
            return $envelope->decode($jobClass);
        } catch (DecodeError) {
            return null;
        }
    }
}

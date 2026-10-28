<?php

declare(strict_types=1);

namespace Queuey\Symfony;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Queuey\Backend;
use Queuey\Clock;
use Queuey\Duration;
use Queuey\EnqueueOptions;
use Queuey\Internal\JobMetadata;
use Queuey\Job;
use Queuey\Producer;
use Queuey\QueueSet;
use Queuey\Worker;

/**
 * The bundle's front door: one place to publish jobs and to build workers.
 * Autowire it by type, or by the `queuey` alias.
 *
 * Nothing here talks to a broker on its own. It caches one {@see Producer} per
 * queue set on top of the container's single {@see Backend}, and hands worker
 * construction to {@see WorkerFactory}.
 */
final class Queuey
{
    /** @var array<class-string<QueueSet>, Producer> */
    private array $producers = [];

    private readonly LoggerInterface $logger;

    /**
     * @param LoggerInterface|null $logger `null` when the application has no `logger`
     *                                     service; a {@see NullLogger} stands in
     */
    public function __construct(
        private readonly Backend $backend,
        private readonly WorkerRegistry $workers,
        private readonly WorkerFactory $factory,
        private readonly bool $declareOnPublish,
        ?LoggerInterface $logger,
        private readonly Clock $clock,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /** The one backend of this process. */
    public function backend(): Backend
    {
        return $this->backend;
    }

    /**
     * The producer for `$queueSet`, cached for the life of this service.
     *
     * `queuey.declare_on_publish` decides whether the first use declares the
     * set's topology; with it off the queues have to exist already, declared by
     * `queuey:declare` or by the Rust side.
     *
     * @param class-string<QueueSet> $queueSet
     */
    public function producer(string $queueSet): Producer
    {
        return $this->producers[$queueSet] ??= $this->declareOnPublish
            ? new Producer($queueSet, $this->backend, $this->logger, $this->clock)
            : Producer::undeclared($queueSet, $this->backend, $this->logger, $this->clock);
    }

    /** Publish `$job` to the queue its `#[OnQueue]` names. Returns the job id. */
    public function enqueue(Job $job, ?EnqueueOptions $options = null): string
    {
        return $this->producer($this->queueSetOf($job))->enqueue($job, $options);
    }

    /** Publish `$job` to become visible after `$delay`, behind the backlog. */
    public function enqueueAfter(Job $job, Duration|string $delay): string
    {
        return $this->producer($this->queueSetOf($job))->enqueueAfter($job, self::duration($delay));
    }

    /** Hold `$job` for `$delay`, then release it at the front of its queue. */
    public function defer(Job $job, Duration|string $delay): string
    {
        return $this->producer($this->queueSetOf($job))->defer($job, self::duration($delay));
    }

    /**
     * Build the named worker. Fresh every call: a worker owns consumers and a
     * shutdown handle, so two callers must not share one.
     */
    public function worker(string $name = 'default'): Worker
    {
        return $this->factory->make($this->definition($name), $this->backend);
    }

    public function definition(string $name = 'default'): WorkerDefinition
    {
        return $this->workers->get($name);
    }

    public function workers(): WorkerRegistry
    {
        return $this->workers;
    }

    /** Forget the cached producers. */
    public function flush(): void
    {
        $this->producers = [];
    }

    /**
     * The queue set `$job` is published to.
     *
     * `JobMetadata` is `@internal` to core, but this package is the same project
     * and there is no public accessor for a job's queue yet. Kept in one method
     * so it is a one-line change if core grows one.
     *
     * @return class-string<QueueSet>
     */
    private function queueSetOf(Job $job): string
    {
        return JobMetadata::for($job::class)->queue::class;
    }

    private static function duration(Duration|string $delay): Duration
    {
        return \is_string($delay) ? Duration::parse($delay) : $delay;
    }
}

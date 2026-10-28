<?php

declare(strict_types=1);

namespace Queuey\Symfony;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Queuey\Backend;
use Queuey\Clock;
use Queuey\DeadLetterHook;
use Queuey\Job;
use Queuey\JobHandler;
use Queuey\QueueSet;
use Queuey\Symfony\Exception\InvalidQueueyConfiguration;
use Queuey\Symfony\Exception\NotAHandler;
use Queuey\Symfony\Handler\CompositeDeadLetterHook;
use Queuey\Symfony\Handler\EventDispatchingDeadLetterHook;
use Queuey\Symfony\Handler\EventDispatchingHandler;
use Queuey\Worker;
use Queuey\WorkerBuilder;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Turns a {@see WorkerDefinition} into a built {@see Worker}, pulling the
 * handlers and hooks out of a service locator so they get constructor injection
 * — and only when a worker is actually built, never at kernel boot.
 */
final class WorkerFactory
{
    private readonly LoggerInterface $logger;

    /**
     * @param ContainerInterface $services every handler and hook service id any worker
     *                                     references; the locator is built by
     *                                     {@see DependencyInjection\HandlerPass}
     * @param EventDispatcherInterface|null $dispatcher `null` behaves like `events: false`
     * @param bool                          $events     the `%queuey.events%` setting
     * @param LoggerInterface|null          $logger     `null` when the application has no
     *                                                  `logger` service; a {@see NullLogger}
     *                                                  stands in
     */
    public function __construct(
        private readonly ContainerInterface $services,
        private readonly WorkerRegistry $workers,
        private readonly ?EventDispatcherInterface $dispatcher,
        private readonly bool $events,
        ?LoggerInterface $logger,
        private readonly Clock $clock,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /** The registry this factory resolves names against. */
    public function workers(): WorkerRegistry
    {
        return $this->workers;
    }

    /**
     * One handler or hook service, straight out of the locator.
     *
     * `queuey:queues` has to look at the handlers a worker *would* build — to
     * say which queue each one serves — without building the worker, and this
     * locator is the only place those service ids resolve. Deliberately
     * untyped: the caller is the one that knows what it expects, and
     * {@see self::handler()} stays the authority on what a worker accepts.
     */
    public function service(string $id): mixed
    {
        return $this->services->get($id);
    }

    /**
     * @param (callable(WorkerBuilder): void)|null $configure applied last, so a command's CLI
     *                                                        overrides beat the config file
     * @param bool                                 $forceEvents turn the decorators on even with
     *                                                          `events: false`; `queuey:work` does
     *                                                          this because the operator asked for
     *                                                          per-job output
     */
    public function make(
        WorkerDefinition $definition,
        Backend $backend,
        ?callable $configure = null,
        bool $forceEvents = false,
    ): Worker {
        $dispatcher = $this->dispatcher;
        $events = $dispatcher !== null && ($this->events || $forceEvents);

        $builder = Worker::builder($definition->queueSet, $backend)
            ->logger($this->logger)
            ->clock($this->clock);

        foreach ($definition->handlers as $index => $id) {
            $handler = $this->handler($definition, $index, $id);
            $builder->handler($events && $dispatcher !== null
                ? new EventDispatchingHandler($handler, $dispatcher, $this->logger, $this->clock)
                : $handler);
        }

        $hooks = $this->deadLetterHooks($definition, $events ? $dispatcher : null);
        if ($hooks !== []) {
            // Core takes one hook, so several always go through the composite —
            // including the single-hook case, whose exception handling then reads
            // the same as everybody else's.
            $builder->onDeadLetter(new CompositeDeadLetterHook($this->logger, ...$hooks));
        }

        foreach ($definition->queueRetryOverrides as $case => $policy) {
            $builder->retryOverride($this->queueCase($definition, $case), $policy);
        }
        foreach ($definition->jobRetryOverrides as $jobClass => $policy) {
            $builder->jobRetryOverride($this->jobClass($definition, $jobClass), $policy);
        }

        if ($definition->only !== []) {
            $builder->queues(...$definition->selectedQueues());
        }
        if ($definition->jobTimeout !== null) {
            $builder->jobTimeout($definition->jobTimeout);
        }
        if ($definition->maxJobs !== null) {
            $builder->maxJobs($definition->maxJobs);
        }

        if ($configure !== null) {
            $configure($builder);
        }

        return $builder->build();
    }

    /**
     * @return JobHandler<Job>
     */
    private function handler(WorkerDefinition $definition, int $index, string $id): JobHandler
    {
        $handler = $this->services->get($id);
        if (!$handler instanceof JobHandler) {
            throw NotAHandler::jobHandler(
                \sprintf('%s (%s.handlers.%d)', $id, WorkerDefinition::keyFor($definition->name), $index),
                get_debug_type($handler),
            );
        }

        return $handler;
    }

    /**
     * @return list<DeadLetterHook>
     */
    private function deadLetterHooks(WorkerDefinition $definition, ?EventDispatcherInterface $dispatcher): array
    {
        $hooks = [];
        foreach ($definition->deadLetterHooks as $index => $id) {
            $hook = $this->services->get($id);
            if (!$hook instanceof DeadLetterHook) {
                throw NotAHandler::deadLetterHook(
                    \sprintf('%s (%s.dead_letter_hooks.%d)', $id, WorkerDefinition::keyFor($definition->name), $index),
                    get_debug_type($hook),
                );
            }
            $hooks[] = $hook;
        }

        if ($dispatcher !== null) {
            // Last, so an application hook sees the dead letter before a listener
            // that might, say, open a transaction of its own.
            $hooks[] = new EventDispatchingDeadLetterHook($dispatcher);
        }

        return $hooks;
    }

    private function queueCase(WorkerDefinition $definition, string $case): QueueSet
    {
        return $definition->casesByName()[$case] ?? throw InvalidQueueyConfiguration::forKey(
            WorkerDefinition::keyFor($definition->name) . '.retry_overrides.queues',
            \sprintf('%s has no case `%s`', $definition->queueSet, $case),
        );
    }

    /**
     * Core's `jobRetryOverride()` keys on a job *class*: it reads `#[OnQueue]` to
     * find the wire name. A bare wire name has no accessor to go the other way,
     * so an override must name the class.
     *
     * @return class-string<Job>
     */
    private function jobClass(WorkerDefinition $definition, string $jobClass): string
    {
        if (!is_a($jobClass, Job::class, true)) {
            throw InvalidQueueyConfiguration::forKey(
                WorkerDefinition::keyFor($definition->name) . '.retry_overrides.jobs',
                \sprintf('`%s` is not a %s class; a job override must name the job class', $jobClass, Job::class),
            );
        }

        return $jobClass;
    }
}

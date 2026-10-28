<?php

declare(strict_types=1);

namespace Queuey\Symfony\Command;

use Queuey\Backend;
use Queuey\Clock;
use Queuey\Duration;
use Queuey\Exception\BackendException;
use Queuey\Exception\ConsumerStopped;
use Queuey\Exception\InvalidConfiguration;
use Queuey\QueueConfig;
use Queuey\QueueSet;
use Queuey\Symfony\Event\JobDeadLettered;
use Queuey\Symfony\Event\JobDeferring;
use Queuey\Symfony\Event\JobFailed;
use Queuey\Symfony\Event\JobProcessed;
use Queuey\Symfony\Event\JobProcessing;
use Queuey\Symfony\Event\WorkerStarting;
use Queuey\Symfony\Event\WorkerStopped;
use Queuey\Symfony\Exception\InvalidQueueyConfiguration;
use Queuey\Symfony\Internal\LazyBackend;
use Queuey\Symfony\WorkerDefinition;
use Queuey\Symfony\WorkerFactory;
use Queuey\Symfony\WorkerRegistry;
use Queuey\Worker;
use Queuey\WorkerBuilder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface as ContractsEventDispatcherInterface;

/**
 * Run a configured worker in the foreground, under a supervisor.
 *
 * Deliberately *not* `messenger:consume`: there is no `--limit`, `--time-limit`,
 * `--memory-limit`, `--sleep` or `--failure-limit`, and no retry options at all.
 * Retry is policy-driven because the Rust side reads the same policy off the
 * same queues, and a per-run override here would put two workers on one queue
 * disagreeing about the wire.
 *
 * Signals are core's business: {@see Worker} installs (and restores) its own
 * `SIGTERM`/`SIGINT` handlers when ext-pcntl is loaded, so this command installs
 * none of its own and does not implement `SignalableCommandInterface`.
 *
 * Exit codes: `0` clean stop, `1` the backend went away, `2` misconfiguration.
 */
#[AsCommand(
    name: 'queuey:work',
    description: 'Consume a queuey worker\'s queues and run its handlers',
)]
final class WorkCommand extends Command
{
    /**
     * The job whose handler is running, and when it started.
     *
     * One slot rather than a map: a worker's concurrency is one — synchronous
     * PHP runs a single job at a time — so there is never a second job to time,
     * and a map would be a slow leak whenever an outcome went unobserved.
     */
    private ?string $currentJobId = null;

    private int $currentStartedAtMs = 0;

    private ?Worker $worker = null;

    /**
     * @param \Closure(): Backend            $backend    a closure, not the service: the
     *                                                   `Backend` is where an AMQP connection
     *                                                   is opened, and a worker name that does
     *                                                   not exist must fail on the name rather
     *                                                   than on the broker
     * @param ContractsEventDispatcherInterface|null $dispatcher `null` behaves like
     *                                                            `events: false`, which also
     *                                                            means no per-job output. The
     *                                                            *contracts* interface, because
     *                                                            `event_dispatcher` is injected
     *                                                            with `nullOnInvalid()` and an
     *                                                            application is free to bind it
     *                                                            to any implementation of it
     */
    public function __construct(
        private readonly WorkerRegistry $workers,
        private readonly WorkerFactory $factory,
        private readonly \Closure $backend,
        private readonly ?ContractsEventDispatcherInterface $dispatcher,
        private readonly Clock $clock,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('worker', InputArgument::OPTIONAL, 'The configured worker to run', 'default')
            ->addOption('queue', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Only these queue cases (names as in the enum)')
            ->addOption('max-jobs', null, InputOption::VALUE_REQUIRED, 'Stop after this many jobs')
            ->addOption('job-timeout', null, InputOption::VALUE_REQUIRED, 'Per-job timeout, e.g. 30s (needs ext-pcntl)')
            ->addOption('for', null, InputOption::VALUE_REQUIRED, 'Stop after this much time, e.g. 1h')
            ->addOption('once', null, InputOption::VALUE_NONE, 'Wait for one job, process it, exit (= --max-jobs=1). Blocks until a job arrives; combine with --for to bound the wait')
            ->addOption('quiet-jobs', null, InputOption::VALUE_NONE, 'Do not print a line per job');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $printJobs = !$input->getOption('quiet-jobs') && !$output->isQuiet();

        // Everything is resolved inside the try: a broken `queuey.backend` or a
        // typo'd worker name has to become exit code 2 with a readable message,
        // not an uncaught exception with a stack trace.
        try {
            $name = self::stringArgument($input, 'worker');
            $definition = $this->workers->get($name);
            $limit = self::durationOption($input, 'for');
            $configure = self::overrides($input, $definition);

            $backend = ($this->backend)();
            // Inside the `try`, because this is what forces the lazy `Backend`
            // proxy to build the real backend: a broker that is not there has
            // to become the readable exit 1 below, not a stack trace from the
            // header two lines down.
            $backendClass = self::backendClass($backend);
            $worker = $this->factory->make(
                $definition,
                $backend,
                $configure,
                // The operator asked for per-job output, so the decorator that
                // produces the events behind it goes on even when
                // `queuey.events` is off. Nothing else about the run changes.
                $printJobs,
            );
        } catch (ConsumerStopped|BackendException $e) {
            return self::reportBackendFailure($io, $e);
        } catch (InvalidConfiguration $e) {
            return self::reportConfigurationError($io, $e);
        }

        $this->worker = $worker;
        $queues = array_map(static fn(QueueConfig $config): string => $config->name, $worker->queues());

        $this->header($io, $name, $backendClass, $queues, \count($definition->handlers));

        $listeners = $printJobs ? $this->listen($output) : [];
        $cause = null;

        $this->dispatch(new WorkerStarting($name, $queues));

        try {
            $limit === null ? $worker->run() : $worker->runFor($limit);
        } catch (\Throwable $e) {
            $cause = $e;
        } finally {
            $this->unlisten($listeners);
            $this->currentJobId = null;
        }

        $handle = $worker->handle();
        $this->dispatch(new WorkerStopped($name, $handle->processed(), $handle->settleFailures(), $cause));

        if ($cause instanceof ConsumerStopped || $cause instanceof BackendException) {
            // Never swallowed: a supervisor restarts on the non-zero exit, and a
            // worker that exited 0 with a dead broker is a queue nobody drains.
            return self::reportBackendFailure($io, $cause);
        }
        if ($cause instanceof InvalidConfiguration) {
            return self::reportConfigurationError($io, $cause);
        }
        if ($cause !== null) {
            // Not ours to interpret; the console application reports it properly.
            throw $cause;
        }

        $this->summary($io, $handle->processed(), $handle->settleFailures());

        return self::SUCCESS;
    }

    /**
     * The CLI overrides, applied to the builder after everything the definition
     * said — which is what makes them overrides.
     *
     * @return callable(WorkerBuilder): void
     *
     * @throws InvalidQueueyConfiguration when an option cannot be read
     */
    private static function overrides(InputInterface $input, WorkerDefinition $definition): callable
    {
        $queues = self::selectedQueues($input, $definition);
        $maxJobs = self::maxJobs($input);
        $jobTimeout = self::durationOption($input, 'job-timeout');

        return static function (WorkerBuilder $builder) use ($queues, $maxJobs, $jobTimeout): void {
            if ($queues !== []) {
                $builder->queues(...$queues);
            }
            if ($maxJobs !== null) {
                $builder->maxJobs($maxJobs);
            }
            if ($jobTimeout !== null) {
                $builder->jobTimeout($jobTimeout);
            }
        };
    }

    /**
     * `--queue=Emails --queue=Reports` as enum cases of the worker's own set.
     *
     * The option **narrows, never widens**: it is resolved against the cases the
     * definition already selected, not against the whole enum. A worker
     * configured with `only: [Emails]` must not start draining `Images` because
     * someone typed `--queue=Images` — that restriction is a deployment
     * decision, and one command line should not quietly undo it.
     *
     * @return list<QueueSet>
     */
    private static function selectedQueues(InputInterface $input, WorkerDefinition $definition): array
    {
        $names = $input->getOption('queue');
        if (!\is_array($names) || $names === []) {
            return [];
        }

        $allowed = [];
        foreach ($definition->selectedQueues() as $case) {
            $allowed[$case->name] = $case;
        }

        $selected = [];
        foreach ($names as $name) {
            if (!\is_string($name)) {
                throw InvalidQueueyConfiguration::forKey('--queue', 'expected queue case names');
            }
            $selected[] = $allowed[$name] ?? throw InvalidQueueyConfiguration::forKey(
                '--queue',
                $definition->only === []
                    ? \sprintf('%s has no case `%s`; it has %s', $definition->queueSet, $name, implode(', ', array_keys($allowed)))
                    : \sprintf(
                        'worker `%s` does not consume `%s`; it is restricted to %s',
                        $definition->name,
                        $name,
                        implode(', ', array_keys($allowed)),
                    ),
            );
        }

        return $selected;
    }

    private static function maxJobs(InputInterface $input): ?int
    {
        if ($input->getOption('once') === true) {
            return 1;
        }

        $value = $input->getOption('max-jobs');
        if ($value === null) {
            return null;
        }
        if (!\is_string($value) || !ctype_digit($value) || (int) $value < 1) {
            throw InvalidQueueyConfiguration::forKey('--max-jobs', 'expected a positive integer');
        }

        return (int) $value;
    }

    private static function durationOption(InputInterface $input, string $option): ?Duration
    {
        $value = $input->getOption($option);
        if ($value === null) {
            return null;
        }
        if (!\is_string($value)) {
            throw InvalidQueueyConfiguration::forKey('--' . $option, 'expected a duration such as "30s"');
        }

        try {
            return Duration::parse($value);
        } catch (InvalidConfiguration $e) {
            throw InvalidQueueyConfiguration::forKey('--' . $option, $e->getMessage(), $e);
        }
    }

    private static function stringArgument(InputInterface $input, string $name): string
    {
        $value = $input->getArgument($name);

        return \is_string($value) ? $value : 'default';
    }

    /**
     * The backend class the operator means, not the one the container built.
     *
     * The `Queuey\Backend` service is lazy, so what a command is handed is a
     * generated proxy implementing the interface; `ContainerAbc123\BackendProxy…`
     * in the header would tell nobody anything. Unwrapping it is also what
     * opens the connection, which is why the caller does this inside its `try`.
     */
    private static function backendClass(Backend $backend): string
    {
        return LazyBackend::unwrap($backend)::class;
    }

    /**
     * @param list<string> $queues
     */
    private function header(SymfonyStyle $io, string $worker, string $backendClass, array $queues, int $handlers): void
    {
        $io->writeln(\sprintf('Running queuey worker <info>%s</info>.', OutputFormatter::escape($worker)));
        $io->writeln(\sprintf('  Backend   %s', $backendClass));
        $io->writeln(\sprintf('  Queues    %s', $queues === [] ? '(none)' : implode(', ', $queues)));
        $io->writeln(\sprintf('  Handlers  %d', $handlers));
        $io->writeln('');
    }

    private function summary(SymfonyStyle $io, int $processed, int $settleFailures): void
    {
        $io->writeln('');
        $io->writeln(\sprintf('Stopped after %d job(s).', $processed));
        if ($settleFailures > 0) {
            // The jobs ran; the broker was not told. Every one of them comes back.
            $io->writeln(\sprintf(
                '<comment>%d delivery/deliveries could not be settled, so they will be redelivered.</comment>',
                $settleFailures,
            ));
        }
    }

    /**
     * Turn the per-job events into output, for this run only.
     *
     * The listeners go on the application dispatcher — the same one the worker's
     * decorators publish to — and come off again in `execute()`'s `finally`, so
     * a second command in the same process (a test, a long-running runtime)
     * never inherits them.
     *
     * @return list<array{string, callable}> what {@see self::unlisten()} takes back off
     */
    private function listen(OutputInterface $output): array
    {
        $dispatcher = $this->dispatcher;
        // Adding and removing listeners is the *component's* contract, not the
        // contracts package's — that one only dispatches. A dispatcher that
        // cannot register a listener silently gets no per-job output: the run
        // itself is unaffected, and refusing to work would be worse than
        // printing less.
        if (!$dispatcher instanceof EventDispatcherInterface) {
            return [];
        }

        $listeners = [
            [JobProcessing::class, function (JobProcessing $event): void {
                $this->currentJobId = $event->context->jobId;
                $this->currentStartedAtMs = $this->clock->nowMs();
            }],
            [JobProcessed::class, function (JobProcessed $event) use ($output): void {
                $this->jobLine($output, $event->context->jobType, $event->runtime->toMs(), 'DONE', 'green');
            }],
            [JobFailed::class, function (JobFailed $event) use ($output): void {
                $this->jobLine(
                    $output,
                    $event->context->jobType,
                    $this->elapsedMs($event->context->jobId),
                    $this->failureStatus($event),
                    'yellow',
                );
            }],
            [JobDeferring::class, function (JobDeferring $event) use ($output): void {
                $this->jobLine(
                    $output,
                    $event->context->jobType,
                    $this->elapsedMs($event->context->jobId),
                    \sprintf('DEFER (%s)', self::describe($event->deferral->delay)),
                    'cyan',
                );
            }],
            [JobDeadLettered::class, function (JobDeadLettered $event) use ($output): void {
                $envelope = $event->deadLetter->envelope;
                $this->jobLine(
                    $output,
                    $envelope->jobType,
                    $this->elapsedMs($envelope->jobId),
                    \sprintf('DEAD %s', $event->deadLetter->reason),
                    'red',
                );
            }],
        ];

        foreach ($listeners as [$event, $listener]) {
            $dispatcher->addListener($event, $listener);
        }

        return $listeners;
    }

    /**
     * @param list<array{string, callable}> $listeners
     */
    private function unlisten(array $listeners): void
    {
        $dispatcher = $this->dispatcher;
        if (!$dispatcher instanceof EventDispatcherInterface) {
            // `listen()` returned `[]` for the same reason; nothing to take off.
            return;
        }

        foreach ($listeners as [$event, $listener]) {
            $dispatcher->removeListener($event, $listener);
        }
    }

    /**
     * What the worker is about to do with a failed job, said out loud.
     *
     * The delay is only quoted when it can be known: a handler implementing
     * `ProvidesRetryPolicy` picks its policy from the payload, and the worker's
     * registered policy is then not the one in force. `JobContext::$maxAttempts`
     * comes from the policy that *was* resolved, so a mismatch with the
     * registered one means the handler replaced it — and an invented "retry in
     * 2s" would be a lie. Vaguer and true beats precise and wrong.
     */
    private function failureStatus(JobFailed $event): string
    {
        if (!$event->willRetry) {
            // A dead letter follows with the reason; this line is the attempt.
            return 'FAIL (dead)';
        }

        $policy = $this->worker?->retryPolicyFor($event->context->jobType);
        if ($policy !== null && $policy->maxAttempts === $event->context->maxAttempts) {
            $delay = $policy->decide($event->context->attempt)->delay();
            if ($delay !== null) {
                return \sprintf('FAIL (retry in %s)', self::describe($delay));
            }
        }

        return 'FAIL (retrying)';
    }

    /**
     * How long `$jobId` has been running, or `null` when it was never seen
     * starting — a delivery dead-lettered for having no handler, or one whose
     * payload did not decode, never reaches a handler at all.
     */
    private function elapsedMs(string $jobId): ?int
    {
        return $jobId === $this->currentJobId
            ? max(0, $this->clock->nowMs() - $this->currentStartedAtMs)
            : null;
    }

    /**
     * `  2026-09-22 10:00:00 app.send_mail ........... 12ms DONE`
     *
     * The wire job type rather than the PHP class: it is the one identifier every
     * one of these events carries (a dead letter whose payload never decoded has
     * no class at all), and it is what the broker's management UI shows.
     */
    private function jobLine(OutputInterface $output, string $jobType, ?int $elapsedMs, string $status, string $colour): void
    {
        $left = \sprintf('%s %s', date('Y-m-d H:i:s', intdiv($this->clock->nowMs(), 1000)), $jobType);
        $right = trim(\sprintf('%s %s', $elapsedMs === null ? '' : self::describe(Duration::ms($elapsedMs)), $status));
        $dots = max(3, 100 - mb_strlen($left) - mb_strlen($right) - 4);

        // Escaped: a dead-letter reason repeats the handler's own message, which
        // may well contain something the formatter would read as a tag.
        $output->writeln(\sprintf(
            '  %s <fg=gray>%s</> <fg=%s>%s</>',
            OutputFormatter::escape($left),
            str_repeat('.', $dots),
            $colour,
            OutputFormatter::escape($right),
        ));
    }

    private function dispatch(object $event): void
    {
        $this->dispatcher?->dispatch($event);
    }

    /** A duration the way core spells one in a dead-letter reason: `500ms`, `2s`, `1.5s`. */
    private static function describe(Duration $duration): string
    {
        $ms = $duration->toMs();
        if ($ms < 1000) {
            return $ms . 'ms';
        }

        return rtrim(rtrim(\sprintf('%.3F', $ms / 1000), '0'), '.') . 's';
    }

    private static function reportConfigurationError(SymfonyStyle $io, InvalidConfiguration $e): int
    {
        $io->getErrorStyle()->error($e->getMessage());

        return self::INVALID;
    }

    private static function reportBackendFailure(SymfonyStyle $io, \Throwable $e): int
    {
        $io->getErrorStyle()->error($e->getMessage());

        return self::FAILURE;
    }
}

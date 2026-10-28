<?php

declare(strict_types=1);

namespace Queuey\Symfony\Command;

use Queuey\Backend;
use Queuey\Exception\BackendException;
use Queuey\Exception\ConsumerStopped;
use Queuey\Exception\InvalidConfiguration;
use Queuey\QueueConfig;
use Queuey\QueueSet;
use Queuey\RabbitMq\RabbitMqBackend;
use Queuey\Symfony\Exception\InvalidQueueyConfiguration;
use Queuey\Symfony\Internal\LazyBackend;
use Queuey\Symfony\WorkerRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Create every queue a worker consumes, plus the dead-letter and hold
 * infrastructure the backend puts behind it.
 *
 * Whole queue *sets* are declared, not the subset a worker's `only` selects: the
 * topology belongs to the set — a retry on a queue nobody consumes here still
 * has to land somewhere, and the Rust side declares the same set identically.
 *
 * Exit codes: `0` declared, `1` the broker refused, `2` bad input.
 */
#[AsCommand(
    name: 'queuey:declare',
    description: 'Declare the queues of the configured workers on the backend',
)]
final class DeclareCommand extends Command
{
    /**
     * @param \Closure(): Backend $backend a closure, not the service: resolving it opens the
     *                                     connection, and a bad argument must be reported
     *                                     before anything dials the broker
     */
    public function __construct(
        private readonly WorkerRegistry $workers,
        private readonly \Closure $backend,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('worker', InputArgument::OPTIONAL | InputArgument::IS_ARRAY, 'Worker names; default: every configured worker')
            ->addOption('set', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Declare these QueueSet classes directly');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $sets = $this->queueSets($input);
        } catch (InvalidConfiguration $e) {
            $io->getErrorStyle()->error($e->getMessage());

            return self::INVALID;
        }

        if ($sets === []) {
            $io->warning('Nothing to declare: no workers are configured and no --set was given.');

            return self::SUCCESS;
        }

        try {
            $backend = ($this->backend)();
        } catch (InvalidConfiguration $e) {
            $io->getErrorStyle()->error($e->getMessage());

            return self::INVALID;
        } catch (ConsumerStopped|BackendException $e) {
            // Resolving the closure is what dials the broker, so an unreachable
            // one arrives here — as a readable message and exit 1, not as an
            // uncaught exception with a `[critical]` banner and a stack trace.
            $io->getErrorStyle()->error($e->getMessage());

            return self::FAILURE;
        }

        $queues = 0;

        foreach ($sets as $set) {
            $configs = array_map(static fn(QueueSet $case): QueueConfig => $case->config(), $set::all());
            $names = array_map(static fn(QueueConfig $config): string => $config->name, $configs);

            try {
                $backend->declare(...$configs);
            } catch (BackendException $e) {
                $io->getErrorStyle()->error(\sprintf('%s: %s', $set, $e->getMessage()));

                return self::FAILURE;
            }

            $queues += \count($names);
            $io->writeln(\sprintf('  %s  <info>%s</info>', $set, implode(', ', $names)));
        }

        $io->writeln('');
        $io->writeln(self::summary($backend, $queues));

        return self::SUCCESS;
    }

    /**
     * What was actually declared, which is more than the queues named above.
     *
     * Core declares a dead-letter queue beside every queue unless
     * `declare_dead_letter_queues` is off, so a bare count of the names printed
     * undercounts the topology that now exists on the broker. The hold queues
     * are not mentioned: those are declared on demand, the first time a job is
     * retried or deferred with a delay the process has not seen before.
     *
     * The backend is unwrapped from the container's lazy proxy here — after the
     * declares, so the connection this reaches through is one that is already
     * open.
     */
    private static function summary(Backend $backend, int $queues): string
    {
        $backend = LazyBackend::unwrap($backend);

        if ($backend instanceof RabbitMqBackend && $backend->options()->declareDeadLetterQueues) {
            return \sprintf(
                'Declared %d queue(s), plus a dead-letter queue (`%s`) for each.',
                $queues,
                $backend->options()->deadSuffix,
            );
        }

        return \sprintf('Declared %d queue(s).', $queues);
    }

    /**
     * The queue sets to declare, deduplicated and in the order they were named.
     *
     * @return list<class-string<QueueSet>>
     */
    private function queueSets(InputInterface $input): array
    {
        /** @var array<class-string<QueueSet>, true> $sets */
        $sets = [];

        foreach (self::setOption($input) as $class) {
            $sets[$class] = true;
        }

        $workers = self::workerNames($input);
        if ($workers === [] && $sets === []) {
            // No arguments at all: everything the application configured.
            $workers = $this->workers->names();
        }

        foreach ($workers as $name) {
            $sets[$this->workers->get($name)->queueSet] = true;
        }

        return array_keys($sets);
    }

    /**
     * @return list<class-string<QueueSet>>
     */
    private static function setOption(InputInterface $input): array
    {
        $values = $input->getOption('set');
        if (!\is_array($values)) {
            return [];
        }

        $classes = [];
        foreach ($values as $class) {
            if (!\is_string($class) || !is_a($class, QueueSet::class, true)) {
                throw InvalidQueueyConfiguration::forKey(
                    '--set',
                    \sprintf('%s is not a %s', \is_string($class) ? $class : get_debug_type($class), QueueSet::class),
                );
            }
            $classes[] = $class;
        }

        return $classes;
    }

    /**
     * @return list<string>
     */
    private static function workerNames(InputInterface $input): array
    {
        $names = $input->getArgument('worker');
        if (!\is_array($names)) {
            return [];
        }

        $workers = [];
        foreach ($names as $name) {
            if (!\is_string($name)) {
                throw InvalidQueueyConfiguration::forKey('worker', 'expected worker names');
            }
            $workers[] = $name;
        }

        return $workers;
    }
}

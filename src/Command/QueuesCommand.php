<?php

declare(strict_types=1);

namespace Queuey\Symfony\Command;

use Queuey\Exception\InvalidConfiguration;
use Queuey\Internal\JobMetadata;
use Queuey\JobHandler;
use Queuey\QueueSet;
use Queuey\RetryPolicy;
use Queuey\Symfony\WorkerDefinition;
use Queuey\Symfony\WorkerFactory;
use Queuey\Symfony\WorkerRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Show what each configured worker consumes, and which handler serves it.
 *
 * The question this answers is "why is nothing draining that queue": a queue
 * with no handler listed is one where every delivery is dead-lettered with
 * `no handler for job type ...`.
 *
 * Exit codes: `0` printed, `2` misconfiguration.
 */
#[AsCommand(
    name: 'queuey:queues',
    description: 'List the queues each queuey worker consumes, with their handlers',
)]
final class QueuesCommand extends Command
{
    public function __construct(
        private readonly WorkerRegistry $workers,
        private readonly WorkerFactory $factory,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('worker', InputArgument::OPTIONAL, 'Only this worker; default: every configured worker');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $rows = [];
            /** @var list<string> $problems */
            $problems = [];

            foreach ($this->definitions($input) as $definition) {
                $handlers = $this->handlersByCase($definition, $problems);

                $selected = [];
                foreach ($definition->selectedQueues() as $case) {
                    $selected[$case->name] = true;
                    $serving = $handlers[$case->name] ?? [];
                    $rows[] = self::row($definition, $case, $serving);
                    if ($serving === []) {
                        $problems[] = self::unhandled($definition, $case);
                    }
                }

                self::reportUnconsumed($definition, $handlers, $selected, $problems);
            }
        } catch (InvalidConfiguration $e) {
            $io->getErrorStyle()->error($e->getMessage());

            return self::INVALID;
        }

        if ($rows === []) {
            $io->warning('No workers are configured.');

            return self::SUCCESS;
        }

        $table = new Table($output);
        $table
            ->setHeaders(['Worker', 'Set', 'Case', 'Queue', 'Prefetch', 'Durable', 'TTL', 'Priority', 'Retry', 'Handlers'])
            ->setRows($rows)
            ->render();

        foreach ($problems as $problem) {
            $io->getErrorStyle()->warning($problem);
        }

        return self::SUCCESS;
    }

    /**
     * @return array<string, WorkerDefinition>
     */
    private function definitions(InputInterface $input): array
    {
        $name = $input->getArgument('worker');
        if ($name === null) {
            return $this->workers->all();
        }
        \assert(\is_string($name));

        return [$name => $this->workers->get($name)];
    }

    /**
     * @param list<string> $handlers
     *
     * @return list<string>
     */
    private static function row(WorkerDefinition $definition, QueueSet $case, array $handlers): array
    {
        $config = $case->config();
        // The queue-level override the worker will actually apply, not merely what
        // the `#[Queue]` attribute declared. A job override or a handler's own
        // `ProvidesRetryPolicy` still beats this per job, which is why the column
        // is headed "Retry" and not "Attempts".
        $retry = $definition->queueRetryOverrides[$case->name] ?? $config->retry;

        return [
            $definition->name,
            self::shortName($definition->queueSet),
            $case->name,
            $config->name,
            (string) $config->prefetch,
            $config->durable ? 'yes' : 'no',
            $config->messageTtl === null ? '-' : (string) $config->messageTtl,
            $config->maxPriority === null ? '-' : (string) $config->maxPriority,
            self::describeRetry($retry),
            $handlers === [] ? '-' : implode(', ', $handlers),
        ];
    }

    /**
     * The other half of the question: a queue this worker *does* consume and
     * has no handler for.
     *
     * Nothing is quietly skipped — the worker takes the delivery, finds no
     * handler for its job type and dead-letters it. Two workers sharing a queue
     * is the usual way in: whichever process gets the delivery is the one that
     * has to handle it, so every worker on a queue needs handlers for every job
     * type on it.
     */
    private static function unhandled(WorkerDefinition $definition, QueueSet $case): string
    {
        return \sprintf(
            'worker `%s` consumes %s but has no handler for it: every job on it will be dead-lettered (no handler)',
            $definition->name,
            $case->config()->name,
        );
    }

    private static function describeRetry(RetryPolicy $policy): string
    {
        return \sprintf('%d/%s', $policy->maxAttempts, $policy->backoff->kind()->name);
    }

    /**
     * Which handler *services* serve which case of this worker's queue set.
     *
     * Handlers come out of the container's locator because that is where the
     * worker gets them, so a constructor dependency that cannot be resolved
     * shows up here too — as a note beside the table rather than as a crash,
     * since the rest of the listing is still worth reading.
     *
     * @param list<string> $problems collects the handlers that could not be inspected
     *
     * @return array<string, list<string>> case name => handler service ids
     */
    private function handlersByCase(WorkerDefinition $definition, array &$problems): array
    {
        $byCase = [];

        foreach ($definition->handlers as $id) {
            try {
                $handler = $this->factory->service($id);
                if (!$handler instanceof JobHandler) {
                    throw new \RuntimeException(\sprintf('it is not a %s', JobHandler::class));
                }
                // `JobMetadata` is `@internal` to core, but this package is the
                // same project and there is no public accessor for a job's queue
                // yet — the same compromise `Queuey::queueSetOf()` makes.
                $case = JobMetadata::for($handler->jobClass())->queue;
            } catch (\Throwable $e) {
                $problems[] = \sprintf('%s (!) could not be inspected: %s', $id, $e->getMessage());

                continue;
            }

            if ($case::class !== $definition->queueSet) {
                $problems[] = \sprintf(
                    '%s (!) handles a job on %s::%s, which worker `%s` does not consume',
                    $id,
                    $case::class,
                    $case->name,
                    $definition->name,
                );

                continue;
            }

            $byCase[$case->name][] = $id;
        }

        return $byCase;
    }

    /**
     * Warn about a handler whose case the table never showed.
     *
     * `handlersByCase()` already caught a handler serving another queue *set*;
     * this catches the subtler one: the case is in the set, but the worker's
     * `only` left it out. The handler is registered on a worker that will never
     * be delivered its job, which looks exactly like "nothing drains that
     * queue" — and without this line the row loop simply skipped it.
     *
     * @param array<string, list<string>> $handlers case name => handler service ids
     * @param array<string, true>         $selected the case names the table showed
     * @param list<string>                $problems
     */
    private static function reportUnconsumed(
        WorkerDefinition $definition,
        array $handlers,
        array $selected,
        array &$problems,
    ): void {
        foreach ($handlers as $caseName => $ids) {
            if (isset($selected[$caseName])) {
                continue;
            }

            foreach ($ids as $id) {
                $problems[] = \sprintf(
                    '%s (!) handles a job on %s::%s, which worker `%s` does not consume: `only` selects %s',
                    $id,
                    self::shortName($definition->queueSet),
                    $caseName,
                    $definition->name,
                    implode(', ', array_keys($selected)),
                );
            }
        }
    }

    /** The class name without its namespace, so the table stays readable. */
    private static function shortName(string $class): string
    {
        $position = strrpos($class, '\\');

        return $position === false ? $class : substr($class, $position + 1);
    }
}

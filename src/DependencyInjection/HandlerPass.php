<?php

declare(strict_types=1);

namespace Queuey\Symfony\DependencyInjection;

use Queuey\Symfony\Exception\InvalidQueueyConfiguration;
use Queuey\Symfony\WorkerFactory;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\ServiceLocatorTagPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Merges the tagged handlers and hooks into the configured worker definitions,
 * and builds the one service locator {@see WorkerFactory} resolves them through.
 *
 * A locator rather than plain references: a handler is constructed when a worker
 * is built, which is `queuey:work` and the test fake, and never during a web
 * request that only publishes.
 */
final class HandlerPass implements CompilerPassInterface
{
    public const HANDLER_TAG = 'queuey.job_handler';
    public const HOOK_TAG = 'queuey.dead_letter_hook';

    private const WORKERS_PARAMETER = 'queuey.workers';

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasParameter(self::WORKERS_PARAMETER) || !$container->hasDefinition(WorkerFactory::class)) {
            return;
        }

        $workers = $container->getParameter(self::WORKERS_PARAMETER);
        if (!\is_array($workers)) {
            throw InvalidQueueyConfiguration::forKey(self::WORKERS_PARAMETER, 'expected an array of worker definitions');
        }

        /** @var array<string, array<string, mixed>> $workers */
        $known = array_map(strval(...), array_keys($workers));

        $workers = $this->merge($workers, $known, $this->collect($container, self::HANDLER_TAG, $known), 'handlers');
        $workers = $this->merge($workers, $known, $this->collect($container, self::HOOK_TAG, $known), 'dead_letter_hooks');

        $container->setParameter(self::WORKERS_PARAMETER, $workers);

        $references = [];
        foreach ($workers as $worker) {
            foreach (['handlers', 'dead_letter_hooks'] as $slot) {
                /** @var list<string> $ids */
                $ids = $worker[$slot] ?? [];
                foreach ($ids as $id) {
                    $references[$id] ??= new Reference($id);
                }
            }
        }

        // By index, not by `$services`: this pass runs after
        // `ResolveNamedArgumentsPass`, so a named key would never be mapped onto
        // the constructor parameter.
        $container->getDefinition(WorkerFactory::class)
            ->setArgument(0, ServiceLocatorTagPass::register($container, $references));
    }

    /**
     * Every tagged service id, grouped by the worker it belongs to.
     *
     * A service with at least one `worker` attribute belongs to exactly those
     * workers: the tag the interface autoconfiguration added carries no worker
     * and is ignored, so `#[AsJobHandler(worker: 'mail')]` does not also land on
     * `default`. That untargeted tag is then removed from the definition, so
     * the compiled container says the same thing this pass decided.
     *
     * @param list<string> $known
     *
     * @return array<string, list<string>>
     */
    private function collect(ContainerBuilder $container, string $tag, array $known): array
    {
        $collected = [];
        foreach ($container->findTaggedServiceIds($tag, true) as $id => $tags) {
            $targeted = [];
            /** @var list<array<string, mixed>> $keep */
            $keep = [];
            foreach ($tags as $attributes) {
                $worker = \is_array($attributes) ? ($attributes['worker'] ?? null) : null;
                if ($worker === null) {
                    continue;
                }
                if (!\is_string($worker) || $worker === '') {
                    throw InvalidQueueyConfiguration::forKey(
                        self::WORKERS_PARAMETER,
                        \sprintf('service `%s` is tagged `%s` with a non-string `worker` attribute', $id, $tag),
                    );
                }
                $targeted[$worker] = true;
                \assert(\is_array($attributes));
                /** @var array<string, mixed> $attributes */
                $keep[] = $attributes;
            }

            if ($targeted !== [] && \count($keep) !== \count($tags)) {
                self::dropUntargetedTags($container, $id, $tag, $keep);
            }

            foreach ($this->workersFor($id, $tag, array_keys($targeted), $known) as $worker) {
                $collected[$worker][] = $id;
            }
        }

        return $collected;
    }

    /**
     * Leave only the targeted tags on a service that carries both kinds.
     *
     * A handler with `#[AsJobHandler(worker: 'mail')]` also implements
     * `JobHandler`, so the interface autoconfiguration adds a second,
     * untargeted tag. This pass already ignores it — but it survives into the
     * compiled container, where `debug:container --tag=queuey.job_handler`
     * lists the service twice and reads like a handler registered on `default`
     * as well. It is not, so it goes.
     *
     * @param list<array<string, mixed>> $keep the tags that name a worker, in order
     */
    private static function dropUntargetedTags(ContainerBuilder $container, string $id, string $tag, array $keep): void
    {
        if (!$container->hasDefinition($id)) {
            return;
        }

        $definition = $container->getDefinition($id);
        $definition->clearTag($tag);
        foreach ($keep as $attributes) {
            $definition->addTag($tag, $attributes);
        }
    }

    /**
     * The worker names one tagged service belongs to, validated.
     *
     * @param list<string> $targeted
     * @param list<string> $known
     *
     * @return list<string>
     */
    private function workersFor(string $id, string $tag, array $targeted, array $known): array
    {
        if ($targeted === []) {
            if (!\in_array('default', $known, true)) {
                throw InvalidQueueyConfiguration::forKey(
                    self::WORKERS_PARAMETER,
                    \sprintf(
                        'service `%s` is tagged `%s` without a worker and there is no `default` worker; '
                        . 'name one with #[%s(worker: \'…\')] or the tag\'s `worker` attribute. Defined workers: %s',
                        $id,
                        $tag,
                        $tag === self::HANDLER_TAG ? 'AsJobHandler' : 'AsDeadLetterHook',
                        $known === [] ? '(none)' : implode(', ', $known),
                    ),
                );
            }

            return ['default'];
        }

        foreach ($targeted as $worker) {
            if (!\in_array($worker, $known, true)) {
                throw InvalidQueueyConfiguration::forKey(
                    self::WORKERS_PARAMETER,
                    \sprintf(
                        'service `%s` is tagged `%s` for the unknown worker `%s`. Defined workers: %s',
                        $id,
                        $tag,
                        $worker,
                        $known === [] ? '(none)' : implode(', ', $known),
                    ),
                );
            }
        }

        return $targeted;
    }

    /**
     * Append the collected ids to `$slot` of each worker, after the configured
     * ones and without duplicates.
     *
     * @param array<string, array<string, mixed>> $workers
     * @param list<string>                        $known
     * @param array<string, list<string>>         $collected
     *
     * @return array<string, array<string, mixed>>
     */
    private function merge(array $workers, array $known, array $collected, string $slot): array
    {
        foreach ($known as $name) {
            $configured = $workers[$name][$slot] ?? [];
            if (!\is_array($configured)) {
                throw InvalidQueueyConfiguration::forKey(
                    self::WORKERS_PARAMETER . '.' . $name . '.' . $slot,
                    'expected a list of service ids',
                );
            }

            $ids = [];
            foreach ([...array_values($configured), ...($collected[$name] ?? [])] as $id) {
                if (!\is_string($id) || $id === '') {
                    throw InvalidQueueyConfiguration::forKey(
                        self::WORKERS_PARAMETER . '.' . $name . '.' . $slot,
                        'expected a list of non-empty service ids',
                    );
                }
                if (!\in_array($id, $ids, true)) {
                    $ids[] = $id;
                }
            }

            $workers[$name][$slot] = $ids;
        }

        return $workers;
    }
}

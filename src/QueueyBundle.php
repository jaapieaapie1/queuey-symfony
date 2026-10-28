<?php

declare(strict_types=1);

namespace Queuey\Symfony;

use PHPUnit\Framework\Assert;
use Queuey\Attributes\Retry;
use Queuey\Backend;
use Queuey\Clock;
use Queuey\DeadLetterHook;
use Queuey\FrozenClock;
use Queuey\JobHandler;
use Queuey\Memory\MemoryBackend;
use Queuey\Symfony\Attribute\AsDeadLetterHook;
use Queuey\Symfony\Attribute\AsJobHandler;
use Queuey\Symfony\DependencyInjection\HandlerPass;
use Queuey\Symfony\Exception\InvalidQueueyConfiguration;
use Queuey\Symfony\Test\QueueyFake;
use Queuey\SystemClock;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

/**
 * Wires `queuey/queuey` into a Symfony application.
 *
 * This is **not** a Messenger transport: no bus, no envelopes, no stamps, no
 * `messenger:consume`. Everything sits directly on core's `Producer`, `Worker`
 * and `Backend`, because that is the only way the PHP side stays wire-compatible
 * with the Rust workers.
 */
final class QueueyBundle extends AbstractBundle
{
    /** The default the `url` env placeholder falls back to. */
    public const DEFAULT_AMQP_URL = 'amqp://guest:guest@localhost:5672/%%2f';

    public function configure(DefinitionConfigurator $definition): void
    {
        $root = $definition->rootNode();
        \assert($root instanceof ArrayNodeDefinition);

        $root
            ->children()
                ->enumNode('backend')
                    ->info('`rabbitmq`, or `memory` for tests and local experiments (one PHP process only).')
                    ->values(['rabbitmq', 'memory'])
                    ->defaultValue('rabbitmq')
                ->end()
                ->enumNode('clock')
                    ->info('`system`, or `frozen` for tests. `frozen` is refused with `backend: rabbitmq`.')
                    ->values(['system', 'frozen'])
                    ->defaultValue('system')
                ->end()
                ->booleanNode('declare_on_publish')
                    ->info('Whether producers declare their queue set on first use.')
                    ->defaultTrue()
                ->end()
                ->booleanNode('events')
                    ->info('Dispatch the Queuey\Symfony\Event\* events. `false` is zero per-job overhead.')
                    ->defaultTrue()
                ->end()
                ->append(self::rabbitMqNode())
                ->append(self::workersNode())
            ->end();
    }

    /**
     * @param array<string, mixed> $config the processed tree above
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $container->import('../config/services.php');

        $backend = self::stringValue($config, 'backend');
        $clock = self::stringValue($config, 'clock');

        // `%%` is a literal `%` once the parameter bag resolves it: the vhost of
        // the default URL is `/%2f`.
        $builder->setParameter('queuey.default_amqp_url', self::DEFAULT_AMQP_URL);
        $builder->setParameter('queuey.backend', $backend);
        $builder->setParameter('queuey.clock', $clock);
        $builder->setParameter('queuey.rabbitmq', self::arrayValue($config, 'rabbitmq'));
        $builder->setParameter('queuey.declare_on_publish', self::boolValue($config, 'declare_on_publish'));
        $builder->setParameter('queuey.events', self::boolValue($config, 'events'));
        $builder->setParameter('queuey.workers', self::workers($config));

        $this->registerClock($builder, $clock === 'frozen');

        if ($backend === 'memory') {
            $this->registerMemoryExtras($builder);
        }

        // Every `JobHandler`/`DeadLetterHook` service belongs to `default` unless
        // an attribute targets another worker; `HandlerPass` merges the tags into
        // the worker definitions.
        $builder->registerForAutoconfiguration(JobHandler::class)->addTag(HandlerPass::HANDLER_TAG);
        $builder->registerForAutoconfiguration(DeadLetterHook::class)->addTag(HandlerPass::HOOK_TAG);
        $builder->registerAttributeForAutoconfiguration(
            AsJobHandler::class,
            static function (ChildDefinition $definition, AsJobHandler $attribute): void {
                $definition->addTag(HandlerPass::HANDLER_TAG, ['worker' => $attribute->worker]);
            },
        );
        $builder->registerAttributeForAutoconfiguration(
            AsDeadLetterHook::class,
            static function (ChildDefinition $definition, AsDeadLetterHook $attribute): void {
                $definition->addTag(HandlerPass::HOOK_TAG, ['worker' => $attribute->worker]);
            },
        );
    }

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        // Before removing, so the service locator's references are in place while
        // the unused definitions are pruned.
        $container->addCompilerPass(new HandlerPass(), PassConfig::TYPE_BEFORE_REMOVING);
    }

    /**
     * The `Queuey\Clock` service. A frozen clock starts at the real current
     * time, or every job's age is measured from the epoch.
     */
    private function registerClock(ContainerBuilder $builder, bool $frozen): void
    {
        if (!$frozen) {
            $builder->register('queuey.clock.system', SystemClock::class);
            $builder->setAlias(Clock::class, 'queuey.clock.system');

            return;
        }

        $builder->register('queuey.clock.frozen', FrozenClock::class)
            ->setFactory([ClockFactory::class, 'frozen']);
        $builder->setAlias(Clock::class, 'queuey.clock.frozen');
        $builder->setAlias(FrozenClock::class, 'queuey.clock.frozen');
    }

    /**
     * With `backend: memory` the one backend *is* a {@see MemoryBackend}, so its
     * inspection methods can be autowired, and the test fake is available.
     */
    private function registerMemoryExtras(ContainerBuilder $builder): void
    {
        // Not lazy here. The `Backend` service is lazy so that injecting
        // `Queuey` into an eager service cannot dial RabbitMQ at construction,
        // but the proxy Symfony generates implements the `Backend` *interface*
        // (both concrete backends are `final`, so it cannot extend one) and the
        // alias below promises a `MemoryBackend`, which `QueueyFake` and the
        // inspection helpers type-hint. A memory backend costs an object, opens
        // nothing and resolves no env placeholder, so there is nothing to defer.
        $builder->getDefinition(Backend::class)->setLazy(false);
        $builder->setAlias(MemoryBackend::class, Backend::class);

        // The fake asserts through `PHPUnit\Framework\Assert`, so it is only
        // registered where PHPUnit is installed: an application running `dev` on
        // the memory backend without a test framework would otherwise get a
        // class-not-found the moment the container built it.
        if (!class_exists(Assert::class)) {
            return;
        }

        // Public — the definition *and* the alias. Nothing in an application
        // injects the fake, so as a private service `RemoveUnusedDefinitionsPass`
        // deletes it and `QueueyAssertionsTrait::queuey()` throws a
        // `ServiceNotFoundException` in every app but one that happens to
        // reference it.
        $builder->register('queuey.test.fake', QueueyFake::class)
            ->setPublic(true)
            ->setArguments([
                new Reference(Queuey::class),
                new Reference(WorkerFactory::class),
                new Reference(WorkerRegistry::class),
                new Reference(MemoryBackend::class),
                new Reference(Clock::class),
            ]);
        $builder->setAlias(QueueyFake::class, 'queuey.test.fake')->setPublic(true);
    }

    /**
     * The worker definitions as the `queuey.workers` parameter holds them: the
     * processed config with the `null` placeholders of the retry override keys
     * dropped, because core's `#[Retry]` rejects a key that belongs to another
     * backoff kind even when it is `null`.
     *
     * @param array<string, mixed> $config
     *
     * @return array<string, array<string, mixed>>
     */
    private static function workers(array $config): array
    {
        $cleaned = [];
        foreach (self::arrayValue($config, 'workers') as $name => $worker) {
            if (!\is_string($name) || !\is_array($worker)) {
                throw InvalidQueueyConfiguration::forKey('queuey.workers', 'expected named arrays');
            }

            /** @var array<string, mixed> $worker */
            $overrides = self::arrayValue($worker, 'retry_overrides');
            foreach (['queues', 'jobs'] as $section) {
                $specs = self::arrayValue($overrides, $section);
                $overrides[$section] = array_map(
                    static fn(mixed $spec): array => \is_array($spec)
                        ? array_filter($spec, static fn(mixed $value): bool => $value !== null)
                        : [],
                    $specs,
                );
            }

            $worker['retry_overrides'] = $overrides;
            $cleaned[$name] = $worker;
        }

        return $cleaned;
    }

    /**
     * The tree guarantees these types; the helpers are what lets the container
     * parameters stay typed without an inline `@var`.
     *
     * @param array<string, mixed> $config
     */
    private static function stringValue(array $config, string $key): string
    {
        $value = $config[$key] ?? null;
        if (!\is_string($value)) {
            throw InvalidQueueyConfiguration::forKey('queuey.' . $key, 'expected a string');
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function boolValue(array $config, string $key): bool
    {
        $value = $config[$key] ?? null;
        if (!\is_bool($value)) {
            throw InvalidQueueyConfiguration::forKey('queuey.' . $key, 'expected a boolean');
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    private static function arrayValue(array $config, string $key): array
    {
        $value = $config[$key] ?? [];
        if (!\is_array($value)) {
            throw InvalidQueueyConfiguration::forKey('queuey.' . $key, 'expected an array');
        }

        /** @var array<string, mixed> $value */
        return $value;
    }

    private static function rabbitMqNode(): ArrayNodeDefinition
    {
        $node = new ArrayNodeDefinition('rabbitmq');
        $node
            ->addDefaultsIfNotSet()
            ->children()
                ->scalarNode('url')
                    ->info('AMQP URL; query parameters (heartbeat, connection_timeout) are honoured by core.')
                    ->defaultValue('%env(default:queuey.default_amqp_url:QUEUEY_AMQP_URL)%')
                    ->cannotBeEmpty()
                ->end()
                ->scalarNode('connection_name')->defaultNull()->end()
                ->integerNode('heartbeat')->min(0)->defaultValue(30)->end()
                ->floatNode('connection_timeout')->defaultValue(10.0)->end()
                ->floatNode('read_write_timeout')->defaultValue(60.0)->end()
                ->floatNode('publish_confirm_timeout')->defaultValue(30.0)->end()
                ->scalarNode('retry_granularity')
                    ->info('Duration in core\'s grammar ("500ms", "1s", "2m"); must match the Rust side.')
                    ->defaultValue('1s')
                ->end()
                ->scalarNode('deferred_granularity')->defaultValue('1s')->end()
                ->scalarNode('dead_suffix')->defaultValue('.dead')->end()
                ->scalarNode('deferred_suffix')->defaultValue('.deferred')->end()
                ->booleanNode('declare_dead_letter_queues')->defaultTrue()->end()
                ->arrayNode('reconnect')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')->defaultTrue()->end()
                        ->integerNode('max_attempts')->min(0)->defaultNull()->end()
                        ->scalarNode('base')->defaultValue('500ms')->end()
                        ->floatNode('factor')->defaultValue(2.0)->end()
                        ->scalarNode('max')->defaultValue('30s')->end()
                        ->booleanNode('jitter')->defaultTrue()->end()
                    ->end()
                ->end()
            ->end();

        return $node;
    }

    private static function workersNode(): ArrayNodeDefinition
    {
        $node = new ArrayNodeDefinition('workers');
        $node
            ->info('Named workers; `bin/console queuey:work <name>` runs one of these.')
            ->useAttributeAsKey('name')
            ->arrayPrototype()
                ->children()
                    ->scalarNode('queues')
                        ->info('The QueueSet enum this worker consumes, e.g. App\Queue\AppQueues.')
                        ->isRequired()
                        ->cannotBeEmpty()
                    ->end()
                    ->arrayNode('only')
                        ->info('Enum case names to consume; empty means every case.')
                        ->scalarPrototype()->cannotBeEmpty()->end()
                    ->end()
                    ->arrayNode('handlers')
                        ->info('Service ids of JobHandler services; tags and attributes add more.')
                        ->scalarPrototype()->cannotBeEmpty()->end()
                    ->end()
                    ->arrayNode('dead_letter_hooks')
                        ->info('Service ids of DeadLetterHook services; tags and attributes add more.')
                        ->scalarPrototype()->cannotBeEmpty()->end()
                    ->end()
                    ->scalarNode('job_timeout')->defaultNull()->end()
                    ->integerNode('max_jobs')->min(1)->defaultNull()->end()
                    ->arrayNode('retry_overrides')
                        ->addDefaultsIfNotSet()
                        ->children()
                            ->append(self::retryOverridesNode('queues'))
                            ->append(self::retryOverridesNode('jobs'))
                        ->end()
                    ->end()
                ->end()
            ->end();

        return $node;
    }

    /** One half of `retry_overrides`: core's `#[Retry]` keys, in `snake_case`. */
    private static function retryOverridesNode(string $name): ArrayNodeDefinition
    {
        $node = new ArrayNodeDefinition($name);
        $node
            ->useAttributeAsKey('name')
            ->arrayPrototype()
                ->children()
                    ->integerNode('max_attempts')->min(1)->defaultNull()->end()
                    ->enumNode('backoff')->values([Retry::NONE, Retry::FIXED, Retry::EXPONENTIAL])->defaultNull()->end()
                    ->scalarNode('delay')->defaultNull()->end()
                    ->scalarNode('base')->defaultNull()->end()
                    ->floatNode('factor')->defaultNull()->end()
                    ->scalarNode('max')->defaultNull()->end()
                    ->booleanNode('jitter')->defaultNull()->end()
                ->end()
            ->end();

        return $node;
    }
}

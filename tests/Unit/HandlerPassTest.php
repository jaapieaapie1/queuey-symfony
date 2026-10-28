<?php

declare(strict_types=1);

use Queuey\Symfony\DependencyInjection\HandlerPass;
use Queuey\Symfony\Exception\InvalidQueueyConfiguration;
use Queuey\Symfony\Tests\Fixtures\AppQueues;
use Queuey\Symfony\Tests\Fixtures\Handlers\RecordingHandler;
use Queuey\Symfony\Tests\Fixtures\Hooks\RecordingDeadLetterHook;
use Queuey\Symfony\WorkerFactory;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\DependencyInjection\ServiceLocator;

/**
 * @param array<string, array<string, mixed>> $workers
 */
function passContainer(array $workers): ContainerBuilder
{
    $container = new ContainerBuilder();
    $container->setParameter('queuey.workers', $workers);
    $container->setDefinition(WorkerFactory::class, new Definition(WorkerFactory::class, [null, null, null, true, null, null]));

    return $container;
}

/**
 * @return array<string, array<string, mixed>>
 */
function passWorkers(ContainerBuilder $container): array
{
    $workers = $container->getParameter('queuey.workers');
    \assert(\is_array($workers));

    /** @var array<string, array<string, mixed>> $workers */
    return $workers;
}

it('appends tagged services after the configured ones, without duplicates', function (): void {
    $container = passContainer([
        'default' => ['queues' => AppQueues::class, 'handlers' => ['app.configured', 'app.tagged'], 'dead_letter_hooks' => []],
    ]);
    $container->setDefinition('app.tagged', (new Definition(RecordingHandler::class))->addTag(HandlerPass::HANDLER_TAG));
    $container->setDefinition('app.also', (new Definition(RecordingHandler::class))->addTag(HandlerPass::HANDLER_TAG));
    $container->setDefinition('app.hook', (new Definition(RecordingDeadLetterHook::class))->addTag(HandlerPass::HOOK_TAG));

    (new HandlerPass())->process($container);

    $workers = passWorkers($container);
    expect($workers['default']['handlers'])->toBe(['app.configured', 'app.tagged', 'app.also'])
        ->and($workers['default']['dead_letter_hooks'])->toBe(['app.hook']);
});

it('keeps an attribute-targeted service off the default worker', function (): void {
    $container = passContainer([
        'default' => ['queues' => AppQueues::class],
        'mail' => ['queues' => AppQueues::class],
    ]);
    // What the two autoconfigurations together produce: the interface adds an
    // untargeted tag, the attribute a targeted one.
    $container->setDefinition('app.mail', (new Definition(RecordingHandler::class))
        ->addTag(HandlerPass::HANDLER_TAG)
        ->addTag(HandlerPass::HANDLER_TAG, ['worker' => 'mail']));
    $container->setDefinition('app.both', (new Definition(RecordingHandler::class))
        ->addTag(HandlerPass::HANDLER_TAG, ['worker' => 'mail'])
        ->addTag(HandlerPass::HANDLER_TAG, ['worker' => 'default']));

    (new HandlerPass())->process($container);

    $workers = passWorkers($container);
    expect($workers['mail']['handlers'])->toBe(['app.mail', 'app.both'])
        ->and($workers['default']['handlers'])->toBe(['app.both']);
});

it('leaves only the targeted tags on the definition', function (): void {
    $container = passContainer([
        'default' => ['queues' => AppQueues::class],
        'mail' => ['queues' => AppQueues::class],
    ]);
    // The interface autoconfiguration's untargeted tag plus the attribute's
    // targeted one: `debug:container --tag=queuey.job_handler` listed this
    // service twice, which reads like a handler on `default` as well.
    $container->setDefinition('app.mail', (new Definition(RecordingHandler::class))
        ->addTag(HandlerPass::HANDLER_TAG)
        ->addTag(HandlerPass::HANDLER_TAG, ['worker' => 'mail']));
    $container->setDefinition('app.hook', (new Definition(RecordingDeadLetterHook::class))
        ->addTag(HandlerPass::HOOK_TAG)
        ->addTag(HandlerPass::HOOK_TAG, ['worker' => 'mail']));
    // Untargeted only: it belongs to `default` and keeps its one tag.
    $container->setDefinition('app.plain', (new Definition(RecordingHandler::class))
        ->addTag(HandlerPass::HANDLER_TAG));

    (new HandlerPass())->process($container);

    expect($container->getDefinition('app.mail')->getTag(HandlerPass::HANDLER_TAG))->toBe([['worker' => 'mail']])
        ->and($container->getDefinition('app.hook')->getTag(HandlerPass::HOOK_TAG))->toBe([['worker' => 'mail']])
        ->and($container->getDefinition('app.plain')->getTag(HandlerPass::HANDLER_TAG))->toBe([[]])
        ->and(array_keys($container->findTaggedServiceIds(HandlerPass::HANDLER_TAG)))->toBe(['app.mail', 'app.plain']);
});

it('keeps every targeted tag of a service that serves several workers', function (): void {
    $container = passContainer([
        'default' => ['queues' => AppQueues::class],
        'mail' => ['queues' => AppQueues::class],
    ]);
    $container->setDefinition('app.both', (new Definition(RecordingHandler::class))
        ->addTag(HandlerPass::HANDLER_TAG)
        ->addTag(HandlerPass::HANDLER_TAG, ['worker' => 'mail'])
        ->addTag(HandlerPass::HANDLER_TAG, ['worker' => 'default']));

    (new HandlerPass())->process($container);

    expect($container->getDefinition('app.both')->getTag(HandlerPass::HANDLER_TAG))
        ->toBe([['worker' => 'mail'], ['worker' => 'default']]);
});

it('refuses a tag for an unknown worker', function (): void {
    $container = passContainer(['default' => ['queues' => AppQueues::class]]);
    $container->setDefinition('app.typo', (new Definition(RecordingHandler::class))
        ->addTag(HandlerPass::HANDLER_TAG, ['worker' => 'mial']));

    expect(fn() => (new HandlerPass())->process($container))->toThrow(
        InvalidQueueyConfiguration::class,
        'service `app.typo` is tagged `queuey.job_handler` for the unknown worker `mial`. Defined workers: default',
    );
});

it('refuses an untargeted tag when there is no default worker', function (): void {
    $container = passContainer(['mail' => ['queues' => AppQueues::class]]);
    $container->setDefinition('app.orphan', (new Definition(RecordingHandler::class))->addTag(HandlerPass::HANDLER_TAG));

    expect(fn() => (new HandlerPass())->process($container))->toThrow(
        InvalidQueueyConfiguration::class,
        '#[AsJobHandler(worker: \'…\')]',
    );

    $hooks = passContainer(['mail' => ['queues' => AppQueues::class]]);
    $hooks->setDefinition('app.orphan', (new Definition(RecordingDeadLetterHook::class))->addTag(HandlerPass::HOOK_TAG));

    expect(fn() => (new HandlerPass())->process($hooks))->toThrow(
        InvalidQueueyConfiguration::class,
        '#[AsDeadLetterHook(worker: \'…\')]',
    );
});

it('injects one service locator over every referenced id', function (): void {
    $container = passContainer([
        'default' => ['queues' => AppQueues::class, 'handlers' => ['app.configured']],
        'mail' => ['queues' => AppQueues::class],
    ]);
    $container->setDefinition('app.configured', new Definition(RecordingHandler::class));
    $container->setDefinition('app.tagged', (new Definition(RecordingHandler::class))
        ->addTag(HandlerPass::HANDLER_TAG, ['worker' => 'mail']));
    $container->setDefinition('app.hook', (new Definition(RecordingDeadLetterHook::class))->addTag(HandlerPass::HOOK_TAG));

    (new HandlerPass())->process($container);

    $argument = $container->getDefinition(WorkerFactory::class)->getArgument(0);
    expect($argument)->toBeInstanceOf(Reference::class);
    \assert($argument instanceof Reference);

    $locator = $container->getDefinition((string) $argument);
    expect($locator->getClass())->toBe(ServiceLocator::class);

    $map = $locator->getArgument(0);
    \assert(\is_array($map));
    expect(array_keys($map))->toBe(['app.configured', 'app.hook', 'app.tagged']);
});

it('does nothing without the parameter or the factory', function (): void {
    $empty = new ContainerBuilder();
    (new HandlerPass())->process($empty);

    expect($empty->hasParameter('queuey.workers'))->toBeFalse();
});

<?php

declare(strict_types=1);

use Queuey\Symfony\Exception\UnknownWorker;
use Queuey\Symfony\Tests\Fixtures\AppQueues;
use Queuey\Symfony\WorkerDefinition;
use Queuey\Symfony\WorkerRegistry;

it('builds definitions lazily from the parameter', function (): void {
    $registry = new WorkerRegistry([
        'default' => ['queues' => AppQueues::class, 'handlers' => ['a']],
        'mail' => ['queues' => AppQueues::class, 'only' => ['Emails']],
    ]);

    expect($registry->names())->toBe(['default', 'mail'])
        ->and($registry->has('mail'))->toBeTrue()
        ->and($registry->has('nope'))->toBeFalse()
        ->and($registry->get('default')->handlers)->toBe(['a'])
        ->and(array_keys($registry->all()))->toBe(['default', 'mail']);

    // Cached: the same instance comes back.
    expect($registry->get('mail'))->toBe($registry->get('mail'));
});

it('never parses a definition nobody asked for', function (): void {
    $registry = new WorkerRegistry([
        'default' => ['queues' => AppQueues::class],
        'broken' => ['queues' => 'Not\\A\\QueueSet'],
    ]);

    expect($registry->get('default')->queueSet)->toBe(AppQueues::class);
});

it('lists the known workers when one is missing', function (): void {
    $registry = new WorkerRegistry(['default' => ['queues' => AppQueues::class]]);

    expect(fn() => $registry->get('reports'))
        ->toThrow(UnknownWorker::class, 'unknown queuey worker `reports`; defined workers: default');

    expect(fn() => (new WorkerRegistry([]))->get('default'))
        ->toThrow(UnknownWorker::class, 'defined workers: (none)');
});

it('takes a programmatic definition, replacing the configured one', function (): void {
    $registry = new WorkerRegistry(['default' => ['queues' => AppQueues::class]]);
    $registry->define(new WorkerDefinition('default', AppQueues::class, handlers: ['override']));
    $registry->define(new WorkerDefinition('extra', AppQueues::class));

    expect($registry->get('default')->handlers)->toBe(['override'])
        ->and($registry->names())->toBe(['default', 'extra'])
        ->and($registry->has('extra'))->toBeTrue();
});

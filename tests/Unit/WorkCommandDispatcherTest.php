<?php

declare(strict_types=1);

use Queuey\Backend;
use Queuey\Symfony\BackendFactory;
use Queuey\Symfony\ClockFactory;
use Queuey\Symfony\Command\WorkCommand;
use Queuey\Symfony\Tests\Fixtures\AppQueues;
use Queuey\Symfony\Tests\Fixtures\ContractsOnlyDispatcher;
use Queuey\Symfony\WorkerDefinition;
use Queuey\Symfony\WorkerFactory;
use Queuey\Symfony\WorkerRegistry;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\ServiceLocator;

/*
 * `config/services.php` injects `event_dispatcher` with `nullOnInvalid()`, and
 * an application is free to bind that id to any implementation of the
 * *contracts* interface — which has `dispatch()` and nothing else. The command
 * must take one without a TypeError, and simply do without the per-job output
 * it would otherwise register listeners for.
 */

it('runs with a dispatcher that only implements the contracts interface', function (): void {
    $clock = ClockFactory::frozen();
    $backend = (new BackendFactory('memory', [], $clock, null))->make();
    $registry = new WorkerRegistry([]);
    $registry->define(new WorkerDefinition('default', AppQueues::class));
    $factory = new WorkerFactory(new ServiceLocator([]), $registry, null, true, null, $clock);
    $dispatcher = new ContractsOnlyDispatcher();

    $command = new WorkCommand(
        $registry,
        $factory,
        static fn(): Backend => $backend,
        $dispatcher,
        $clock,
    );
    $tester = new CommandTester($command);

    // `--for=0s` stops on the deadline without needing a job or a signal.
    $status = $tester->execute(['--for' => '0s']);

    expect($status)->toBe(Command::SUCCESS)
        ->and($tester->getDisplay())->toContain('Stopped after 0 job(s).');
    // `WorkerStarting` and `WorkerStopped` still go out: they are dispatched,
    // never listened for, so the contracts interface is enough for them.
    expect($dispatcher->dispatched)->toHaveCount(2);
});

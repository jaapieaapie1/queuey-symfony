<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Queuey\Backend;
use Queuey\Clock;
use Queuey\Symfony\BackendFactory;
use Queuey\Symfony\Command\DeclareCommand;
use Queuey\Symfony\Command\QueuesCommand;
use Queuey\Symfony\Command\WorkCommand;
use Queuey\Symfony\Queuey;
use Queuey\Symfony\WorkerFactory;
use Queuey\Symfony\WorkerRegistry;

/*
 * Every service is private except the `queuey` alias: the bundle's entry point
 * is the `Queuey` service, autowired by type (or fetched by that alias). `logger` and `event_dispatcher`
 * are `nullOnInvalid()` so the bundle works in a container without MonologBundle
 * or an event dispatcher — a missing logger becomes a `NullLogger`, a missing
 * dispatcher behaves like `events: false`.
 */
return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services->set(BackendFactory::class)
        ->args([
            param('queuey.backend'),
            param('queuey.rabbitmq'),
            service(Clock::class),
            service('logger')->nullOnInvalid(),
        ])
        ->tag('monolog.logger', ['channel' => 'queuey']);

    // Lazy, and interface-typed on purpose: Symfony hands out a proxy
    // implementing `Queuey\Backend` (var-exporter's lazy proxies, 6.2+) and only
    // calls the factory on the first method call. That is what keeps an eager
    // application service — a `#[AsCommand]` without a `description:`, a Twig
    // extension, an event subscriber — from dialing RabbitMQ at construction:
    // injecting `Queuey` no longer opens a socket, `bin/console list` stays
    // usable with a dead broker, and `%env()%` placeholders resolve on the first
    // backend call instead of at boot.
    $services->set(Backend::class)
        ->factory([service(BackendFactory::class), 'make'])
        ->lazy();

    $services->set(WorkerRegistry::class)
        ->args([param('queuey.workers')]);

    $services->set(WorkerFactory::class)
        ->args([
            // Argument 0 is the handler/hook service locator; `HandlerPass` fills
            // it in once it knows every id any worker references.
            abstract_arg('service locator over the handler and hook services'),
            service(WorkerRegistry::class),
            service('event_dispatcher')->nullOnInvalid(),
            param('queuey.events'),
            service('logger')->nullOnInvalid(),
            service(Clock::class),
        ])
        ->tag('monolog.logger', ['channel' => 'queuey']);

    $services->set(Queuey::class)
        ->args([
            service(Backend::class),
            service(WorkerRegistry::class),
            service(WorkerFactory::class),
            param('queuey.declare_on_publish'),
            service('logger')->nullOnInvalid(),
            service(Clock::class),
        ])
        ->tag('monolog.logger', ['channel' => 'queuey']);

    // The one public service: `Queuey` is the bundle's documented entry point,
    // and in an application that only runs `queuey:*` commands nothing injects
    // it, so as a wholly private service the container would prune it away
    // before a test — or a `$container->get('queuey')` — could reach it. The
    // alias is public; the definition it points at stays private, which is
    // enough to keep it alive.
    $services->alias('queuey', Queuey::class)->public();

    // The commands carry `#[AsCommand]`, but these services are not
    // autoconfigured, so the tag is written out. `AddConsoleCommandPass` still
    // reads the name and description off the attribute, which is what keeps the
    // command lazy: `bin/console list` never instantiates one, so nothing here
    // opens an AMQP connection until a `queuey:*` command actually runs.
    //
    // `service_closure(Backend::class)` for the same reason: injecting the
    // backend itself would dial the broker while the command is being built,
    // before a typo'd worker name or a bad `--set` could be reported.
    $services->set(WorkCommand::class)
        ->args([
            service(WorkerRegistry::class),
            service(WorkerFactory::class),
            service_closure(Backend::class),
            service('event_dispatcher')->nullOnInvalid(),
            service(Clock::class),
        ])
        ->tag('console.command');

    $services->set(DeclareCommand::class)
        ->args([
            service(WorkerRegistry::class),
            service_closure(Backend::class),
        ])
        ->tag('console.command');

    $services->set(QueuesCommand::class)
        ->args([
            service(WorkerRegistry::class),
            service(WorkerFactory::class),
        ])
        ->tag('console.command');
};

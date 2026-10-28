<?php

declare(strict_types=1);

namespace Queuey\Symfony\Tests\Fixtures\App;

use Psr\Log\NullLogger;
use Queuey\Symfony\QueueyBundle;
use Queuey\Symfony\Tests\Fixtures\AppQueues;
use Queuey\Symfony\Tests\Fixtures\EagerQueueyConsumer;
use Queuey\Symfony\Tests\Fixtures\Handlers\ConfigOnlyHandler;
use Queuey\Symfony\Tests\Fixtures\Handlers\DeferringHandler;
use Queuey\Symfony\Tests\Fixtures\Handlers\FlakyHandler;
use Queuey\Symfony\Tests\Fixtures\Handlers\MailWorkerHandler;
use Queuey\Symfony\Tests\Fixtures\Handlers\RecordingHandler;
use Queuey\Symfony\Tests\Fixtures\Handlers\ReportHandler;
use Queuey\Symfony\Tests\Fixtures\Hooks\RecordingDeadLetterHook;
use Queuey\Symfony\Tests\Fixtures\Recorder;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/**
 * The smallest kernel that can hold the bundle: FrameworkBundle, QueueyBundle
 * and the fixture services.
 *
 * `test` runs on the memory backend and a frozen clock; `integration` swaps in
 * RabbitMQ from `AMQP_URL`, which is what the broker-backed suite boots;
 * `deadbroker` points the RabbitMQ backend at a closed port, which is how the
 * suite proves that building a service is not the same as dialing the broker.
 */
final class TestKernel extends Kernel
{
    use MicroKernelTrait;

    /**
     * An AMQP URL whose port nothing can be listening on. `%%` is a literal `%`
     * once the parameter bag resolves it, so the vhost is the usual `/`.
     */
    public const DEAD_BROKER_URL = 'amqp://guest:guest@127.0.0.1:1/%%2f';

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new QueueyBundle();
    }

    public function getProjectDir(): string
    {
        return __DIR__;
    }

    public function getCacheDir(): string
    {
        return $this->varDir() . '/cache/' . $this->getEnvironment();
    }

    public function getBuildDir(): string
    {
        return $this->getCacheDir();
    }

    public function getLogDir(): string
    {
        return $this->varDir() . '/log';
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', [
            'test' => true,
            'secret' => 'test',
            'http_method_override' => false,
            'handle_all_throwables' => true,
            'php_errors' => ['log' => true],
        ]);

        $container->extension('queuey', $this->queueyConfig());

        $services = $container->services()
            ->defaults()
            ->autowire()
            ->autoconfigure();

        // FrameworkBundle's stand-in logger writes `error` to the process
        // output, PHPUnit counts that as unexpected output and `failOnRisky`
        // fails the test. A worker that gives up on a job logs at exactly that
        // level by design, so the fixture app stays quiet instead.
        $services->set('logger', NullLogger::class);

        $services->set(Recorder::class)->public();
        // The smoke test's crash in one service: an ordinary, eager, non-lazy
        // application service that injects `Queuey`. Building it must not open
        // a socket.
        $services->set(EagerQueueyConsumer::class)->public();
        $services->set(RecordingHandler::class);
        $services->set(ReportHandler::class);
        $services->set(FlakyHandler::class);
        $services->set(DeferringHandler::class);
        $services->set(MailWorkerHandler::class);
        $services->set(RecordingDeadLetterHook::class);
        // Not autoconfigured: this one reaches its worker through the config
        // file's `handlers` list alone.
        $services->set(ConfigOnlyHandler::class)->autoconfigure(false);

        // No public aliases for the bundle's own services on purpose: whatever
        // `static::getContainer()` can hand out here is exactly what an
        // application's test suite gets, so this fixture app is a real check
        // that nothing the bundle documents is pruned away.
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        // No routes: this kernel exists for the container and the console.
    }

    /**
     * @return array<string, mixed>
     */
    private function queueyConfig(): array
    {
        $backend = match ($this->getEnvironment()) {
            'integration' => ['backend' => 'rabbitmq', 'clock' => 'system', 'rabbitmq' => ['url' => '%env(AMQP_URL)%']],
            // Nothing listens on port 1. Connecting is guaranteed to fail, so
            // any test that gets as far as a value out of this container has
            // proved the connection was never opened.
            'deadbroker' => ['backend' => 'rabbitmq', 'clock' => 'system', 'rabbitmq' => ['url' => self::DEAD_BROKER_URL]],
            default => ['backend' => 'memory', 'clock' => 'frozen'],
        };

        return $backend + [
            'workers' => [
                'default' => [
                    'queues' => AppQueues::class,
                    'handlers' => [ConfigOnlyHandler::class],
                    'retry_overrides' => [
                        'queues' => [
                            'Images' => ['max_attempts' => 2, 'backoff' => 'fixed', 'delay' => '1s'],
                        ],
                    ],
                ],
                'mail' => [
                    'queues' => AppQueues::class,
                    'only' => ['Emails'],
                    'max_jobs' => 5,
                ],
            ],
        ];
    }

    private function varDir(): string
    {
        return \dirname(__DIR__, 3) . '/var';
    }
}

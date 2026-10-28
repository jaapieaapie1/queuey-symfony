<?php

declare(strict_types=1);

use Queuey\Symfony\QueueyBundle;
use Queuey\Symfony\Tests\Fixtures\AppQueues;
use Symfony\Component\Config\Definition\Configuration;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;

/**
 * @param array<string, mixed> $config
 *
 * @return array<array-key, mixed>
 */
function processQueueyConfig(array $config = []): array
{
    $configuration = new Configuration(new QueueyBundle(), null, 'queuey');

    return (new Processor())->processConfiguration($configuration, [$config]);
}

it('defaults to rabbitmq on the system clock', function (): void {
    $config = processQueueyConfig();

    expect($config['backend'])->toBe('rabbitmq')
        ->and($config['clock'])->toBe('system')
        ->and($config['declare_on_publish'])->toBeTrue()
        ->and($config['events'])->toBeTrue()
        ->and($config['workers'])->toBe([]);
});

it('fills in every rabbitmq default', function (): void {
    $rabbitmq = processQueueyConfig()['rabbitmq'];
    \assert(\is_array($rabbitmq));

    expect($rabbitmq['url'])->toBe('%env(default:queuey.default_amqp_url:QUEUEY_AMQP_URL)%')
        ->and($rabbitmq['connection_name'])->toBeNull()
        ->and($rabbitmq['heartbeat'])->toBe(30)
        ->and($rabbitmq['connection_timeout'])->toBe(10.0)
        ->and($rabbitmq['read_write_timeout'])->toBe(60.0)
        ->and($rabbitmq['publish_confirm_timeout'])->toBe(30.0)
        ->and($rabbitmq['retry_granularity'])->toBe('1s')
        ->and($rabbitmq['deferred_granularity'])->toBe('1s')
        ->and($rabbitmq['dead_suffix'])->toBe('.dead')
        ->and($rabbitmq['deferred_suffix'])->toBe('.deferred')
        ->and($rabbitmq['declare_dead_letter_queues'])->toBeTrue()
        ->and($rabbitmq['reconnect'])->toBe([
            'enabled' => true,
            'max_attempts' => null,
            'base' => '500ms',
            'factor' => 2.0,
            'max' => '30s',
            'jitter' => true,
        ]);
});

it('refuses an unknown backend and an unknown clock', function (): void {
    expect(fn() => processQueueyConfig(['backend' => 'redis']))
        ->toThrow(InvalidConfigurationException::class, 'queuey.backend');

    expect(fn() => processQueueyConfig(['clock' => 'stopped']))
        ->toThrow(InvalidConfigurationException::class, 'queuey.clock');
});

it('requires a queue set per worker', function (): void {
    expect(fn() => processQueueyConfig(['workers' => ['default' => []]]))
        ->toThrow(InvalidConfigurationException::class, 'The child config "queues" under "queuey.workers.default" must be configured');
});

it('defaults a worker entry and keeps the retry override keys optional', function (): void {
    $workers = processQueueyConfig([
        'workers' => [
            'default' => [
                'queues' => AppQueues::class,
                'retry_overrides' => ['queues' => ['Emails' => ['max_attempts' => 5, 'backoff' => 'exponential']]],
            ],
        ],
    ])['workers'];
    \assert(\is_array($workers));

    // `toEqual`, not `toBe`: the processed tree's key order is Symfony's business.
    expect($workers['default'])->toEqual([
        'queues' => AppQueues::class,
        'only' => [],
        'handlers' => [],
        'dead_letter_hooks' => [],
        'job_timeout' => null,
        'max_jobs' => null,
        'retry_overrides' => [
            'queues' => [
                'Emails' => [
                    'max_attempts' => 5,
                    'backoff' => 'exponential',
                    'delay' => null,
                    'base' => null,
                    'factor' => null,
                    'max' => null,
                    'jitter' => null,
                ],
            ],
            'jobs' => [],
        ],
    ]);
});

it('refuses an unknown backoff kind in an override', function (): void {
    expect(fn() => processQueueyConfig([
        'workers' => ['default' => [
            'queues' => AppQueues::class,
            'retry_overrides' => ['queues' => ['Emails' => ['backoff' => 'linear']]],
        ]],
    ]))->toThrow(InvalidConfigurationException::class, 'backoff');
});

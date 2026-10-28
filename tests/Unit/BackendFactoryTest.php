<?php

declare(strict_types=1);

use Psr\Log\NullLogger;
use Queuey\Backoff;
use Queuey\BackoffKind;
use Queuey\Duration;
use Queuey\FrozenClock;
use Queuey\Memory\MemoryBackend;
use Queuey\RabbitMq\BackoffPolicy;
use Queuey\Symfony\BackendFactory;
use Queuey\Symfony\Exception\InvalidQueueyConfiguration;
use Queuey\SystemClock;

/**
 * @param array<string, mixed> $rabbitmq
 */
function backendFactory(string $backend = 'rabbitmq', array $rabbitmq = [], ?Queuey\Clock $clock = null): BackendFactory
{
    return new BackendFactory($backend, $rabbitmq, $clock ?? new SystemClock(), new NullLogger());
}

it('builds a memory backend', function (): void {
    expect(backendFactory('memory', [], new FrozenClock(0))->make())->toBeInstanceOf(MemoryBackend::class);
});

it('names queuey.backend for an unknown backend', function (): void {
    expect(fn() => backendFactory('redis')->make())
        ->toThrow(InvalidQueueyConfiguration::class, 'queuey.backend: expected `rabbitmq` or `memory`, got `redis`');
});

it('refuses a frozen clock with rabbitmq, naming queuey.clock', function (): void {
    $factory = backendFactory('rabbitmq', ['url' => 'amqp://guest:guest@localhost:5672/'], new FrozenClock(0));

    expect(fn() => $factory->make())->toThrow(InvalidQueueyConfiguration::class, 'queuey.clock');
});

it('refuses an empty url, naming queuey.rabbitmq.url', function (): void {
    expect(fn() => backendFactory('rabbitmq', ['url' => '   '])->make())
        ->toThrow(InvalidQueueyConfiguration::class, 'queuey.rabbitmq.url');
});

it('maps every rabbitmq key onto core options', function (): void {
    $options = backendFactory('rabbitmq', [
        'url' => 'amqp://localhost',
        'connection_name' => 'web-1',
        'heartbeat' => 15,
        'connection_timeout' => 4.5,
        'read_write_timeout' => 45.0,
        'publish_confirm_timeout' => 7.5,
        'retry_granularity' => '250ms',
        'deferred_granularity' => '2s',
        'dead_suffix' => '.buried',
        'deferred_suffix' => '.later',
        'declare_dead_letter_queues' => false,
        'reconnect' => [
            'enabled' => true,
            'max_attempts' => 7,
            'base' => '100ms',
            'factor' => 3.0,
            'max' => '12s',
            'jitter' => false,
        ],
    ])->options();

    expect($options->connectionName)->toBe('web-1')
        ->and($options->heartbeat)->toBe(15)
        ->and($options->connectionTimeout)->toBe(4.5)
        ->and($options->readWriteTimeout)->toBe(45.0)
        ->and($options->publishConfirmTimeout)->toBe(7.5)
        ->and($options->retryGranularity->toMs())->toBe(250)
        ->and($options->deferredGranularity->toMs())->toBe(2000)
        ->and($options->deadSuffix)->toBe('.buried')
        ->and($options->deferredSuffix)->toBe('.later')
        ->and($options->declareDeadLetterQueues)->toBeFalse();

    $reconnect = $options->reconnect;
    expect($reconnect)->toBeInstanceOf(BackoffPolicy::class);
    \assert($reconnect instanceof BackoffPolicy);
    expect($reconnect->maxAttempts)->toBe(7)
        ->and($reconnect->backoff->kind())->toBe(BackoffKind::Exponential)
        ->and($reconnect->backoff->base()->toMs())->toBe(100)
        ->and($reconnect->backoff->factor())->toBe(3.0)
        ->and($reconnect->backoff->max()->toMs())->toBe(12_000)
        ->and($reconnect->backoff->jitter())->toBeFalse();
});

it('falls back to core defaults for an empty block', function (): void {
    $options = backendFactory('rabbitmq')->options();

    expect($options->connectionName)->toBeNull()
        ->and($options->heartbeat)->toBe(30)
        ->and($options->retryGranularity->toMs())->toBe(1000)
        ->and($options->reconnect)->toBeInstanceOf(BackoffPolicy::class);

    $reconnect = $options->reconnect;
    \assert($reconnect instanceof BackoffPolicy);
    expect($reconnect->maxAttempts)->toBeNull()
        ->and($reconnect->backoff->equals(Backoff::exponential(
            base: Duration::ms(BackoffPolicy::DEFAULT_BASE_MS),
            factor: 2.0,
            max: Duration::ms(BackoffPolicy::DEFAULT_MAX_MS),
            jitter: true,
        )))->toBeTrue();
});

it('spells a disabled reconnect as false', function (): void {
    expect(backendFactory('rabbitmq', ['reconnect' => ['enabled' => false]])->options()->reconnect)->toBeFalse();
});

it('names the key of a malformed duration', function (): void {
    expect(fn() => backendFactory('rabbitmq', ['retry_granularity' => 'soon'])->options())
        ->toThrow(InvalidQueueyConfiguration::class, 'queuey.rabbitmq.retry_granularity');
});

it('names the key of a malformed scalar', function (): void {
    expect(fn() => backendFactory('rabbitmq', ['heartbeat' => '15'])->options())
        ->toThrow(InvalidQueueyConfiguration::class, 'queuey.rabbitmq.heartbeat: expected an integer, got `15`');

    expect(fn() => backendFactory('rabbitmq', ['dead_suffix' => 3])->options())
        ->toThrow(InvalidQueueyConfiguration::class, 'queuey.rabbitmq.dead_suffix: expected a string, got 3');

    expect(fn() => backendFactory('rabbitmq', ['declare_dead_letter_queues' => 'yes'])->options())
        ->toThrow(InvalidQueueyConfiguration::class, 'queuey.rabbitmq.declare_dead_letter_queues: expected a boolean');

    expect(fn() => backendFactory('rabbitmq', ['reconnect' => ['max_attempts' => -1]])->options())
        ->toThrow(InvalidQueueyConfiguration::class, 'queuey.rabbitmq.reconnect.max_attempts');
});

it('re-throws a cross-key rule of core as the whole block', function (): void {
    // core: a read/write timeout below twice the heartbeat tears down an idle connection.
    expect(fn() => backendFactory('rabbitmq', ['heartbeat' => 30, 'read_write_timeout' => 10.0])->options())
        ->toThrow(InvalidQueueyConfiguration::class, 'queuey.rabbitmq: `readWriteTimeout` must be at least twice');
});

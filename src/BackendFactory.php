<?php

declare(strict_types=1);

namespace Queuey\Symfony;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Queuey\Backend;
use Queuey\Backoff;
use Queuey\Clock;
use Queuey\Duration;
use Queuey\Exception\InvalidConfiguration;
use Queuey\FrozenClock;
use Queuey\Memory\MemoryBackend;
use Queuey\RabbitMq\BackoffPolicy;
use Queuey\RabbitMq\RabbitMqBackend;
use Queuey\RabbitMq\RabbitMqOptions;
use Queuey\RabbitMq\Topology;
use Queuey\Symfony\Exception\InvalidQueueyConfiguration;

/**
 * Builds the one {@see Backend} the container holds, from the `queuey` config.
 *
 * Every error names the config key that produced it, because the alternative —
 * core's own message about `readWriteTimeout` — leaves an operator hunting
 * through a yaml file for a key that is not spelled that way.
 *
 * Nothing here runs at boot: the `Queuey\Backend` service is built by
 * {@see self::make()} on first use, which is also when the `%env()%`
 * placeholders in the URL are resolved.
 */
final class BackendFactory
{
    private const KEY = 'queuey.rabbitmq';

    private readonly LoggerInterface $logger;

    /**
     * @param string               $backend  the `queuey.backend` value
     * @param array<string, mixed> $rabbitmq the processed `queuey.rabbitmq` block
     * @param LoggerInterface|null $logger   `null` when the application has no `logger`
     *                                       service; a {@see NullLogger} stands in
     */
    public function __construct(
        private readonly string $backend,
        private readonly array $rabbitmq,
        private readonly Clock $clock,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * @throws InvalidQueueyConfiguration when `queuey.backend` names no known backend,
     *                                    or the RabbitMQ settings do not add up
     */
    public function make(): Backend
    {
        return match ($this->backend) {
            'memory' => new MemoryBackend($this->clock, $this->sleeper()),
            'rabbitmq' => $this->rabbitMq(),
            default => throw InvalidQueueyConfiguration::forKey(
                'queuey.backend',
                \sprintf('expected `rabbitmq` or `memory`, got `%s`', $this->backend),
            ),
        };
    }

    /**
     * Connect, once it is certain the clock can actually move.
     *
     * A frozen clock is a test fixture: with a real broker every job age, every
     * `runFor()` deadline and every hold would be measured against an instant
     * that never advances.
     */
    private function rabbitMq(): Backend
    {
        if ($this->clock instanceof FrozenClock) {
            throw InvalidQueueyConfiguration::forKey(
                'queuey.clock',
                '`frozen` only works with `backend: memory`; a real broker needs a clock that moves',
            );
        }

        return RabbitMqBackend::connect($this->url(), $this->options(), $this->logger);
    }

    /**
     * The `queuey.rabbitmq` block as core options.
     *
     * Public so a test can see the mapping without a broker anywhere near it.
     */
    public function options(): RabbitMqOptions
    {
        try {
            return new RabbitMqOptions(
                connectionName: $this->connectionName(),
                deadSuffix: $this->string('dead_suffix', Topology::DEFAULT_DEAD_SUFFIX),
                declareDeadLetterQueues: $this->bool('declare_dead_letter_queues', true),
                deferredSuffix: $this->string('deferred_suffix', Topology::DEFAULT_DEFERRED_SUFFIX),
                retryGranularity: $this->duration('retry_granularity'),
                deferredGranularity: $this->duration('deferred_granularity'),
                reconnect: $this->reconnect(),
                heartbeat: $this->int('heartbeat', RabbitMqOptions::DEFAULT_HEARTBEAT),
                connectionTimeout: $this->float('connection_timeout', RabbitMqOptions::DEFAULT_CONNECTION_TIMEOUT),
                readWriteTimeout: $this->float('read_write_timeout', RabbitMqOptions::DEFAULT_READ_WRITE_TIMEOUT),
                publishConfirmTimeout: $this->float('publish_confirm_timeout', RabbitMqOptions::DEFAULT_PUBLISH_CONFIRM_TIMEOUT),
            );
        } catch (InvalidConfiguration $e) {
            // Core validates across keys (a read/write timeout against the
            // heartbeat, say), so the block is the most precise thing we can name.
            throw InvalidQueueyConfiguration::forKey(self::KEY, $e->getMessage(), $e);
        }
    }

    /**
     * How an idle {@see MemoryBackend::wait()} yields.
     *
     * On a frozen clock nothing can become due while we sleep and nothing else
     * runs, so core returns from `wait()` as soon as it sees the clock did not
     * move: a no-op sleeper makes that immediate instead of costing 2ms a turn.
     * On a system clock the default `usleep` stays, because a no-op there turns
     * the worker loop's 1s idle `wait()` into a busy spin.
     *
     * @return (callable(float): void)|null
     */
    private function sleeper(): ?callable
    {
        if (!$this->clock instanceof FrozenClock) {
            return null;
        }

        return static function (float $seconds): void {};
    }

    /** The broker URL, which core parses and validates itself. */
    private function url(): string
    {
        $url = $this->rabbitmq['url'] ?? null;
        if (!\is_string($url) || trim($url) === '') {
            throw InvalidQueueyConfiguration::forKey(
                self::KEY . '.url',
                \sprintf('expected a non-empty AMQP URL, got %s', self::describe($url)),
            );
        }

        return $url;
    }

    /** `null` stays `null`: core and php-amqplib pick their own default name. */
    private function connectionName(): ?string
    {
        $name = $this->rabbitmq['connection_name'] ?? null;
        if ($name === null) {
            return null;
        }
        if (!\is_string($name)) {
            throw InvalidQueueyConfiguration::forKey(
                self::KEY . '.connection_name',
                \sprintf('expected a string or null, got %s', self::describe($name)),
            );
        }

        return $name;
    }

    private function reconnect(): BackoffPolicy|false
    {
        $key = self::KEY . '.reconnect';
        $reconnect = $this->rabbitmq['reconnect'] ?? null;
        if ($reconnect === null) {
            return new BackoffPolicy();
        }
        if (!\is_array($reconnect)) {
            throw InvalidQueueyConfiguration::forKey($key, \sprintf('expected an array, got %s', self::describe($reconnect)));
        }

        $enabled = $reconnect['enabled'] ?? true;
        if (!\is_bool($enabled)) {
            throw InvalidQueueyConfiguration::forKey($key . '.enabled', \sprintf('expected a boolean, got %s', self::describe($enabled)));
        }
        if ($enabled === false) {
            // Core spells "never reconnect" as `false` rather than a policy that
            // gives up at once, so a dropped connection surfaces as an error.
            return false;
        }

        $maxAttempts = $reconnect['max_attempts'] ?? null;
        if ($maxAttempts !== null && (!\is_int($maxAttempts) || $maxAttempts < 0)) {
            throw InvalidQueueyConfiguration::forKey(
                $key . '.max_attempts',
                \sprintf('expected a non-negative integer or null, got %s', self::describe($maxAttempts)),
            );
        }

        $factor = $reconnect['factor'] ?? 2.0;
        if (!\is_int($factor) && !\is_float($factor)) {
            throw InvalidQueueyConfiguration::forKey($key . '.factor', \sprintf('expected a number, got %s', self::describe($factor)));
        }

        $jitter = $reconnect['jitter'] ?? true;
        if (!\is_bool($jitter)) {
            throw InvalidQueueyConfiguration::forKey($key . '.jitter', \sprintf('expected a boolean, got %s', self::describe($jitter)));
        }

        $backoff = Backoff::exponential(
            base: self::parseDuration($key . '.base', $reconnect['base'] ?? null) ?? Duration::ms(BackoffPolicy::DEFAULT_BASE_MS),
            factor: (float) $factor,
            max: self::parseDuration($key . '.max', $reconnect['max'] ?? null) ?? Duration::ms(BackoffPolicy::DEFAULT_MAX_MS),
            jitter: $jitter,
        );

        try {
            return new BackoffPolicy($maxAttempts, $backoff);
        } catch (InvalidConfiguration $e) {
            throw InvalidQueueyConfiguration::forKey($key, $e->getMessage(), $e);
        }
    }

    private function duration(string $key): ?Duration
    {
        return self::parseDuration(self::KEY . '.' . $key, $this->rabbitmq[$key] ?? null);
    }

    private static function parseDuration(string $key, mixed $value): ?Duration
    {
        if ($value === null) {
            return null;
        }
        if (!\is_string($value)) {
            throw InvalidQueueyConfiguration::forKey($key, \sprintf('expected a duration string, got %s', self::describe($value)));
        }

        try {
            return Duration::parse($value);
        } catch (InvalidConfiguration $e) {
            throw InvalidQueueyConfiguration::forKey($key, $e->getMessage(), $e);
        }
    }

    private function string(string $key, string $default): string
    {
        $value = $this->rabbitmq[$key] ?? null;
        if ($value === null) {
            return $default;
        }
        if (!\is_string($value)) {
            throw InvalidQueueyConfiguration::forKey(self::KEY . '.' . $key, \sprintf('expected a string, got %s', self::describe($value)));
        }

        return $value;
    }

    private function bool(string $key, bool $default): bool
    {
        $value = $this->rabbitmq[$key] ?? null;
        if ($value === null) {
            return $default;
        }
        if (!\is_bool($value)) {
            throw InvalidQueueyConfiguration::forKey(self::KEY . '.' . $key, \sprintf('expected a boolean, got %s', self::describe($value)));
        }

        return $value;
    }

    private function int(string $key, int $default): int
    {
        $value = $this->rabbitmq[$key] ?? null;
        if ($value === null) {
            return $default;
        }
        if (!\is_int($value)) {
            throw InvalidQueueyConfiguration::forKey(self::KEY . '.' . $key, \sprintf('expected an integer, got %s', self::describe($value)));
        }

        return $value;
    }

    private function float(string $key, float $default): float
    {
        $value = $this->rabbitmq[$key] ?? null;
        if ($value === null) {
            return $default;
        }
        if (!\is_int($value) && !\is_float($value)) {
            throw InvalidQueueyConfiguration::forKey(self::KEY . '.' . $key, \sprintf('expected a number, got %s', self::describe($value)));
        }

        return (float) $value;
    }

    /** A value as it should read inside an error message: the value when it is short, the type otherwise. */
    private static function describe(mixed $value): string
    {
        if (\is_string($value)) {
            return \sprintf('`%s`', $value);
        }
        if (\is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (\is_int($value) || \is_float($value)) {
            return (string) $value;
        }

        return get_debug_type($value);
    }
}

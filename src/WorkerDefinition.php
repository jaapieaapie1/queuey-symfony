<?php

declare(strict_types=1);

namespace Queuey\Symfony;

use Queuey\Attributes\Retry;
use Queuey\Duration;
use Queuey\Exception\InvalidConfiguration;
use Queuey\QueueSet;
use Queuey\RetryPolicy;
use Queuey\Symfony\Exception\InvalidQueueyConfiguration;

/**
 * One entry of `queuey.workers`, validated and turned into values.
 *
 * Handlers and hooks are **service ids**, not class names: the container
 * resolves them, so a handler gets constructor injection and an application may
 * register the same class twice under different ids. The ids come from the
 * config file and from whatever {@see DependencyInjection\HandlerPass} merged in
 * off the tags.
 *
 * Immutable, with withers, so a compiler pass or a test can take the configured
 * definition and add to it.
 */
final class WorkerDefinition
{
    /**
     * @param class-string<QueueSet>     $queueSet
     * @param list<string>               $only                case names; empty means every case
     * @param list<string>               $handlers            service ids, resolved in order
     * @param list<string>               $deadLetterHooks     service ids, resolved in order
     * @param array<string, RetryPolicy> $queueRetryOverrides keyed by queue case name
     * @param array<string, RetryPolicy> $jobRetryOverrides   keyed by job class or wire name
     */
    public function __construct(
        public readonly string $name,
        public readonly string $queueSet,
        public readonly array $only = [],
        public readonly array $handlers = [],
        public readonly array $deadLetterHooks = [],
        public readonly ?Duration $jobTimeout = null,
        public readonly ?int $maxJobs = null,
        public readonly array $queueRetryOverrides = [],
        public readonly array $jobRetryOverrides = [],
    ) {}

    /**
     * @param array<string, mixed> $config the processed `queuey.workers.<name>` array
     *
     * @throws InvalidQueueyConfiguration naming the exact key that is wrong
     */
    public static function fromConfig(string $name, array $config): self
    {
        $key = self::keyFor($name);

        return new self(
            name: $name,
            queueSet: self::queueSetFrom($config, $key),
            only: self::stringList($config, $key, 'only'),
            handlers: self::stringList($config, $key, 'handlers'),
            deadLetterHooks: self::stringList($config, $key, 'dead_letter_hooks'),
            jobTimeout: self::durationFrom($config, $key, 'job_timeout'),
            maxJobs: self::maxJobsFrom($config, $key),
            queueRetryOverrides: self::retryOverrides($config, $key, 'queues'),
            jobRetryOverrides: self::retryOverrides($config, $key, 'jobs'),
        );
    }

    /** Append handler service ids. */
    public function withHandlers(string ...$serviceIds): self
    {
        return $this->with(handlers: [...$this->handlers, ...array_values($serviceIds)]);
    }

    /** Append dead-letter hook service ids. */
    public function withDeadLetterHooks(string ...$serviceIds): self
    {
        return $this->with(deadLetterHooks: [...$this->deadLetterHooks, ...array_values($serviceIds)]);
    }

    /** Replace the selection of queue cases; no arguments means every case. */
    public function only(string ...$cases): self
    {
        return $this->with(only: array_values($cases));
    }

    public function withJobTimeout(Duration|string|null $t): self
    {
        return $this->with(
            jobTimeout: \is_string($t) ? Duration::parse($t) : $t,
            jobTimeoutSet: true,
        );
    }

    public function withMaxJobs(?int $n): self
    {
        return $this->with(maxJobs: $n, maxJobsSet: true);
    }

    public function withQueueRetry(string $case, RetryPolicy $p): self
    {
        return $this->with(queueRetryOverrides: [...$this->queueRetryOverrides, $case => $p]);
    }

    public function withJobRetry(string $jobClass, RetryPolicy $p): self
    {
        return $this->with(jobRetryOverrides: [...$this->jobRetryOverrides, $jobClass => $p]);
    }

    /**
     * The enum cases `only` selects, in the set's declaration order, or every
     * case when `only` is empty.
     *
     * @return list<QueueSet>
     *
     * @throws InvalidQueueyConfiguration when a name matches no case of the set
     */
    public function selectedQueues(): array
    {
        if ($this->only === []) {
            return $this->queueSet::all();
        }

        $byName = $this->casesByName();
        $selected = [];
        foreach ($this->only as $name) {
            $selected[] = $byName[$name] ?? throw InvalidQueueyConfiguration::forKey(
                self::keyFor($this->name) . '.only',
                \sprintf('%s has no case `%s`; it has %s', $this->queueSet, $name, implode(', ', array_keys($byName))),
            );
        }

        return $selected;
    }

    /**
     * The cases of this definition's queue set, keyed by case name.
     *
     * @return array<string, QueueSet>
     */
    public function casesByName(): array
    {
        $byName = [];
        foreach ($this->queueSet::all() as $case) {
            $byName[$case->name] = $case;
        }

        return $byName;
    }

    /** The dotted config path this definition came from. */
    public static function keyFor(string $name): string
    {
        return 'queuey.workers.' . $name;
    }

    /**
     * @param list<string>|null               $only
     * @param list<string>|null               $handlers
     * @param list<string>|null               $deadLetterHooks
     * @param array<string, RetryPolicy>|null $queueRetryOverrides
     * @param array<string, RetryPolicy>|null $jobRetryOverrides
     */
    private function with(
        ?array $only = null,
        ?array $handlers = null,
        ?array $deadLetterHooks = null,
        ?Duration $jobTimeout = null,
        bool $jobTimeoutSet = false,
        ?int $maxJobs = null,
        bool $maxJobsSet = false,
        ?array $queueRetryOverrides = null,
        ?array $jobRetryOverrides = null,
    ): self {
        return new self(
            name: $this->name,
            queueSet: $this->queueSet,
            only: $only ?? $this->only,
            handlers: $handlers ?? $this->handlers,
            deadLetterHooks: $deadLetterHooks ?? $this->deadLetterHooks,
            jobTimeout: $jobTimeoutSet ? $jobTimeout : $this->jobTimeout,
            maxJobs: $maxJobsSet ? $maxJobs : $this->maxJobs,
            queueRetryOverrides: $queueRetryOverrides ?? $this->queueRetryOverrides,
            jobRetryOverrides: $jobRetryOverrides ?? $this->jobRetryOverrides,
        );
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return class-string<QueueSet>
     */
    private static function queueSetFrom(array $config, string $key): string
    {
        $queues = $config['queues'] ?? null;
        if (!\is_string($queues) || $queues === '') {
            throw InvalidQueueyConfiguration::forKey(
                $key . '.queues',
                'a worker must name the QueueSet enum it consumes, e.g. App\\Queue\\AppQueues',
            );
        }
        if (!is_a($queues, QueueSet::class, true)) {
            throw InvalidQueueyConfiguration::forKey(
                $key . '.queues',
                \sprintf('%s is not a %s', $queues, QueueSet::class),
            );
        }

        return $queues;
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return list<string>
     */
    private static function stringList(array $config, string $key, string $name): array
    {
        $values = $config[$name] ?? [];
        if (!\is_array($values)) {
            throw InvalidQueueyConfiguration::forKey($key . '.' . $name, 'expected a list of strings');
        }

        $list = [];
        foreach ($values as $value) {
            if (!\is_string($value) || $value === '') {
                throw InvalidQueueyConfiguration::forKey($key . '.' . $name, 'expected a list of non-empty strings');
            }
            $list[] = $value;
        }

        return $list;
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function durationFrom(array $config, string $key, string $name): ?Duration
    {
        $value = $config[$name] ?? null;
        if ($value === null) {
            return null;
        }
        if ($value instanceof Duration) {
            return $value;
        }
        if (!\is_string($value)) {
            throw InvalidQueueyConfiguration::forKey($key . '.' . $name, 'expected a duration string such as "30s"');
        }

        try {
            return Duration::parse($value);
        } catch (InvalidConfiguration $e) {
            throw InvalidQueueyConfiguration::forKey($key . '.' . $name, $e->getMessage(), $e);
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function maxJobsFrom(array $config, string $key): ?int
    {
        $value = $config['max_jobs'] ?? null;
        if ($value === null) {
            return null;
        }
        if (!\is_int($value) || $value < 1) {
            throw InvalidQueueyConfiguration::forKey($key . '.max_jobs', 'expected a positive integer or null');
        }

        return $value;
    }

    /**
     * Turn one half of `retry_overrides` into policies.
     *
     * The value keys are core's `#[Retry]` keys, so the attribute does the
     * validating and this stays a translation from `snake_case`.
     *
     * @param array<string, mixed> $config
     *
     * @return array<string, RetryPolicy>
     */
    private static function retryOverrides(array $config, string $key, string $name): array
    {
        $overrides = $config['retry_overrides'] ?? [];
        if (!\is_array($overrides)) {
            throw InvalidQueueyConfiguration::forKey($key . '.retry_overrides', 'expected an array with `queues` and `jobs`');
        }

        $section = $overrides[$name] ?? [];
        $sectionKey = $key . '.retry_overrides.' . $name;
        if (!\is_array($section)) {
            throw InvalidQueueyConfiguration::forKey($sectionKey, 'expected an array of overrides');
        }

        $policies = [];
        foreach ($section as $target => $spec) {
            if (!\is_string($target) || $target === '') {
                throw InvalidQueueyConfiguration::forKey($sectionKey, 'override keys must be non-empty strings');
            }
            if (!\is_array($spec)) {
                throw InvalidQueueyConfiguration::forKey($sectionKey . '.' . $target, 'expected an array of Retry keys');
            }
            $policies[$target] = self::policyFrom($spec, $sectionKey . '.' . $target);
        }

        return $policies;
    }

    /**
     * @param array<array-key, mixed> $spec
     */
    private static function policyFrom(array $spec, string $key): RetryPolicy
    {
        $known = ['max_attempts', 'backoff', 'delay', 'base', 'factor', 'max', 'jitter'];
        foreach (array_keys($spec) as $name) {
            if (!\in_array($name, $known, true)) {
                throw InvalidQueueyConfiguration::forKey(
                    $key,
                    \sprintf('unknown key `%s`; expected one of %s', (string) $name, implode(', ', $known)),
                );
            }
        }

        try {
            $retry = new Retry(
                maxAttempts: self::retryInt($spec, 'max_attempts', $key) ?? 1,
                backoff: self::retryString($spec, 'backoff', $key) ?? Retry::NONE,
                delay: self::retryString($spec, 'delay', $key),
                base: self::retryString($spec, 'base', $key),
                factor: self::retryFloat($spec, 'factor', $key),
                max: self::retryString($spec, 'max', $key),
                jitter: self::retryBool($spec, 'jitter', $key),
            );

            return $retry->toPolicy();
        } catch (InvalidConfiguration $e) {
            throw InvalidQueueyConfiguration::forKey($key, $e->getMessage(), $e);
        }
    }

    /**
     * @param array<array-key, mixed> $spec
     */
    private static function retryInt(array $spec, string $name, string $key): ?int
    {
        $value = $spec[$name] ?? null;
        if ($value === null || \is_int($value)) {
            return $value;
        }

        throw InvalidQueueyConfiguration::forKey($key, \sprintf('`%s` must be an integer', $name));
    }

    /**
     * @param array<array-key, mixed> $spec
     */
    private static function retryString(array $spec, string $name, string $key): ?string
    {
        $value = $spec[$name] ?? null;
        if ($value === null || \is_string($value)) {
            return $value;
        }

        throw InvalidQueueyConfiguration::forKey($key, \sprintf('`%s` must be a string', $name));
    }

    /**
     * @param array<array-key, mixed> $spec
     */
    private static function retryFloat(array $spec, string $name, string $key): ?float
    {
        $value = $spec[$name] ?? null;
        if ($value === null) {
            return null;
        }
        if (\is_int($value) || \is_float($value)) {
            return (float) $value;
        }

        throw InvalidQueueyConfiguration::forKey($key, \sprintf('`%s` must be a number', $name));
    }

    /**
     * @param array<array-key, mixed> $spec
     */
    private static function retryBool(array $spec, string $name, string $key): ?bool
    {
        $value = $spec[$name] ?? null;
        if ($value === null || \is_bool($value)) {
            return $value;
        }

        throw InvalidQueueyConfiguration::forKey($key, \sprintf('`%s` must be a boolean', $name));
    }
}

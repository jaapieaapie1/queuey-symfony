<?php

declare(strict_types=1);

namespace Queuey\Symfony;

use Queuey\Symfony\Exception\InvalidQueueyConfiguration;
use Queuey\Symfony\Exception\UnknownWorker;

/**
 * The named worker definitions: what `%queuey.workers%` holds after
 * {@see DependencyInjection\HandlerPass} merged the tagged services in.
 *
 * Parsing is lazy — on the first read, not in the constructor — so a
 * misconfigured worker cannot break `bin/console cache:clear` or any other
 * command that never touches the queue.
 */
final class WorkerRegistry
{
    /** @var array<string, WorkerDefinition> */
    private array $definitions = [];

    /** @var array<string, array<string, mixed>>|null the raw parameter, until it is needed */
    private ?array $raw = null;

    /**
     * @param array<string, array<string, mixed>> $config `%queuey.workers%` after HandlerPass
     */
    public function __construct(private readonly array $config) {}

    /**
     * Add or replace a definition.
     *
     * Programmatic registration in Symfony is a tag or `#[AsJobHandler]`; this
     * exists for tests and for applications that build definitions in a compiler
     * pass of their own.
     */
    public function define(WorkerDefinition $definition): void
    {
        $this->definitions[$definition->name] = $definition;
    }

    /**
     * @throws UnknownWorker when nothing defines `$name`
     */
    public function get(string $name): WorkerDefinition
    {
        return $this->definitions[$name]
            ?? $this->fromConfig($name)
            ?? throw UnknownWorker::named($name, $this->names());
    }

    public function has(string $name): bool
    {
        return isset($this->definitions[$name]) || \array_key_exists($name, $this->rawConfig());
    }

    /**
     * Every definition, config and programmatic.
     *
     * @return array<string, WorkerDefinition>
     */
    public function all(): array
    {
        $all = [];
        foreach ($this->names() as $name) {
            $all[$name] = $this->get($name);
        }

        return $all;
    }

    /**
     * Every defined worker name, config order first.
     *
     * @return list<string>
     */
    public function names(): array
    {
        return array_values(array_unique([
            ...array_keys($this->rawConfig()),
            ...array_keys($this->definitions),
        ]));
    }

    /** Parse and cache the config entry for `$name`, or `null` when there is none. */
    private function fromConfig(string $name): ?WorkerDefinition
    {
        $config = $this->rawConfig()[$name] ?? null;
        if ($config === null) {
            return null;
        }

        return $this->definitions[$name] = WorkerDefinition::fromConfig($name, $config);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function rawConfig(): array
    {
        if ($this->raw !== null) {
            return $this->raw;
        }

        $raw = [];
        foreach ($this->config as $name => $definition) {
            if (!\is_string($name)) {
                throw InvalidQueueyConfiguration::forKey('queuey.workers', 'worker names must be strings');
            }
            if (!\is_array($definition)) {
                throw InvalidQueueyConfiguration::forKey(WorkerDefinition::keyFor($name), 'expected an array');
            }
            $raw[$name] = $definition;
        }

        return $this->raw = $raw;
    }
}

# queuey-symfony design (v1)

Symfony bundle for [`queuey/queuey`](https://github.com/jaapieaapie1/queuey-php), the pure-PHP, Rust-wire-compatible job
queue. **This is not a Messenger transport.** Symfony Messenger (`MessageBusInterface`,
transports, `messenger:consume`, `#[AsMessageHandler]`, envelopes and stamps) is not used, not
extended and not made compatible: its serialization and retry semantics cannot match the queuey
wire contract that Rust workers rely on. Everything here sits directly on top of
`Queuey\Producer`, `Queuey\Worker` and `Queuey\Backend`.

Sibling of `queuey-laravel`: same scope, same decisions
where they translate, Symfony idioms where they do not (service tags and attributes instead of
config-only class lists, the Config component instead of a PHP array, `EventDispatcher` events,
`KernelTestCase` instead of a facade `fake()`).

What the bundle adds, and nothing more:

1. **Container wiring** — one configured `Backend` service, a `Producer` per queue set, handlers
   and dead-letter hooks resolved from the container (constructor injection, lazily through a
   service locator).
2. **Configuration** — `config/packages/queuey.yaml` under the `queuey` root: backend choice,
   RabbitMQ options, named worker definitions.
3. **Console commands** — `queuey:work`, `queuey:declare`, `queuey:queues`.
4. **Events** — Symfony events around each job and each dead letter, for metrics, tracing, UIs.
5. **Testing** — a `memory` backend + frozen clock configured for the `test` environment, a
   `QueueyFake` with assertions and `work()` / `workAll()`, and a trait for `KernelTestCase`.

Wire compatibility is entirely the core library's job. This bundle never touches envelopes,
queue arguments, suffixes, TTL rounding or dead-letter reason strings. **Do not add anything
here that changes what goes on the wire.**

## Package

- Repository `jaapieaapie1/queuey-symfony`, next to `queuey-php`, `queuey-laravel` and `queuey`.
- Composer `queuey/symfony`, type `symfony-bundle`, namespace `Queuey\Symfony\`, PSR-4 `src/` →
  `Queuey\Symfony\`, `tests/` → `Queuey\Symfony\Tests\`. MIT, same author as core.
- `require`: `php ^8.3`, `queuey/queuey ^1.0`, `psr/log ^3`, `psr/container ^2`,
  `symfony/config ^6.4 || ^7.0`, `symfony/console ^6.4 || ^7.0`,
  `symfony/dependency-injection ^6.4 || ^7.0`, `symfony/event-dispatcher ^6.4 || ^7.0`,
  `symfony/event-dispatcher-contracts ^3`, `symfony/http-kernel ^6.4 || ^7.0` (for
  `AbstractBundle`). `suggest`: `ext-pcntl` (graceful shutdown and per-job timeouts).
- `require-dev`: `symfony/framework-bundle ^6.4 || ^7.0`, `symfony/yaml ^6.4 || ^7.0`,
  `pestphp/pest ^4`, `phpstan/phpstan ^2`, `phpstan/phpstan-symfony ^2`,
  `friendsofphp/php-cs-fixer ^3`.
- `queuey/queuey` comes from Packagist; `composer.json` has no path repositories. The release
  workflow refuses to publish the bundle until `queuey/queuey` 1.x is installable from there.
- No Flex recipe in this repo (recipes live in the contrib repo), so nothing registers the
  bundle automatically — `type: symfony-bundle` alone does not. README shows the manual
  `config/bundles.php` line and the yaml file.
- Tooling identical to core: `declare(strict_types=1)` everywhere, `final` by default,
  php-cs-fixer with **exactly** core's rule set (`@PER-CS2.0`, `declare_strict_types`,
  `strict_param`, `ordered_imports` alpha class/function/const, `no_unused_imports`; finder
  over `src`, `tests`, `config`), PHPStan level max on `src` + `tests` + `config`
  (`phpstan/phpstan-symfony` + Pest's `vendor/pestphp/pest/extension.neon`,
  `treatPhpDocTypesAsCertain: false`), Pest 4. Scripts `test`, `test:integration` (gated on
  `AMQP_URL`), `test:all`, `stan`, `cs`, `cs:fix`, `check` (cs + stan + test).
- Layout:
  ```
  config/services.php                                  (PHP DI config loaded by the bundle)
  src/QueueyBundle.php                                 (AbstractBundle: config tree + loadExtension + compiler pass)
  src/DependencyInjection/HandlerPass.php              (merges tagged/attributed handlers & hooks into worker definitions)
  src/Attribute/AsJobHandler.php
  src/Attribute/AsDeadLetterHook.php
  src/Queuey.php                                       (manager; the Laravel QueueyManager)
  src/BackendFactory.php
  src/ClockFactory.php
  src/WorkerDefinition.php
  src/WorkerRegistry.php
  src/WorkerFactory.php
  src/Handler/EventDispatchingHandler.php
  src/Handler/EventDispatchingDeadLetterHook.php
  src/Handler/CompositeDeadLetterHook.php
  src/Event/*.php
  src/Command/WorkCommand.php
  src/Command/DeclareCommand.php
  src/Command/QueuesCommand.php
  src/Test/QueueyFake.php
  src/Test/QueueyAssertionsTrait.php
  src/Exception/*.php
  tests/Pest.php, tests/TestCase.php (extends KernelTestCase), tests/bootstrap.php
  tests/Fixtures/App/TestKernel.php + tests/Fixtures/App/config/*.yaml   (MicroKernelTrait kernel)
  tests/Fixtures/AppQueues.php, tests/Fixtures/Jobs/*, tests/Fixtures/Handlers/*, tests/Fixtures/Hooks/*
  tests/Unit/**  tests/Feature/**  tests/Integration/** (AMQP_URL gated)
  docs/DESIGN.md, README.md, CHANGELOG.md, LICENSE, .github/workflows/ci.yml
  ```

## Configuration — `config/packages/queuey.yaml`

Defined with the Config component in `QueueyBundle::configure()`. Defaults shown; every key
optional except `workers.<name>.queues`.

```yaml
queuey:
  # rabbitmq or memory. memory lives inside one PHP process: a producer in a request and a
  # worker in bin/console never meet. For tests and local experiments only.
  backend: rabbitmq
  # system or frozen. frozen is for tests (QueueyFake::travel()); refused with rabbitmq.
  clock: system

  rabbitmq:
    # Query parameters (heartbeat, connection_timeout, channel_max) are honoured by core.
    # '%%2f' because '%' is special in DI parameters.
    url: '%env(default:queuey.default_amqp_url:QUEUEY_AMQP_URL)%'   # default param = amqp://guest:guest@localhost:5672/%%2f
    connection_name: null            # shown in the management UI; null → core default
    heartbeat: 30                    # seconds, 0 disables
    connection_timeout: 10.0         # seconds
    read_write_timeout: 60.0         # seconds, >= 2 * heartbeat
    publish_confirm_timeout: 30.0    # seconds
    # Duration strings in core's grammar ("500ms", "1s", "2m"); must match the Rust side.
    retry_granularity: 1s
    deferred_granularity: 1s
    dead_suffix: .dead
    deferred_suffix: .deferred
    declare_dead_letter_queues: true
    reconnect:
      enabled: true
      max_attempts: null             # null = unlimited
      base: 500ms
      factor: 2.0
      max: 30s
      jitter: true

  # Whether producers declare their queue set's topology on first use. false →
  # Producer::undeclared(); queues must already exist (Rust side, or queuey:declare).
  declare_on_publish: true

  # Dispatch the Queuey\Symfony\Event\* events. false = zero per-job overhead.
  events: true

  # Named workers. `bin/console queuey:work <name>` runs one of these.
  workers:
    default:
      queues: App\Queue\AppQueues    # QueueSet enum FQCN. Required.
      only: []                       # case names to consume; empty = all
      handlers: []                   # service ids of JobHandler services (tags/attributes add more, see below)
      dead_letter_hooks: []          # service ids of DeadLetterHook services (idem)
      job_timeout: null              # duration string; needs ext-pcntl
      max_jobs: null                 # exit after N jobs (supervisor restarts)
      # Same keys as core's Queuey\Attributes\Retry: build a Retry, call toPolicy().
      #   {max_attempts: 5, backoff: exponential, base: 1s, factor: 2.0, max: 5m, jitter: true}
      #   {max_attempts: 3, backoff: fixed, delay: 2s}
      #   {max_attempts: 1, backoff: none}
      retry_overrides:
        queues: {}                   # keyed by enum case name
        jobs: {}                     # keyed by job class (or wire name)
```

Two layers of validation, both naming the key path:

- **Shape** at container compile, by the Config component (enum values for `backend` /
  `clock` / `backoff`, ints, floats, lists, required `queues`). That is the Symfony way and
  costs nothing at runtime.
- **Semantics** at first use, in PHP, as `Queuey\Symfony\Exception\InvalidQueueyConfiguration`
  (extends core `InvalidConfiguration`; message names the key, e.g.
  `queuey.workers.default.queues`): the class is a `QueueSet` enum, `only` names existing
  cases, duration strings parse, `clock: frozen` only with `backend: memory`, a service is a
  `JobHandler`. Never at boot, and never while compiling, so a bad `QUEUEY_AMQP_URL` or a
  typo in a case name does not break `bin/console cache:clear` or unrelated commands.
  Env vars are only resolved when the `Backend` service is instantiated, which only
  `queuey:*` commands and code that publishes do.

`connection_name: null` stays `null` (core/php-amqplib default). Symfony has no `app.name`.

## Bundle — `QueueyBundle extends AbstractBundle`

- `configure(DefinitionConfigurator)`: the tree above.
- `loadExtension(array $config, ContainerConfigurator, ContainerBuilder)`:
  - `$container->import('../config/services.php')`.
  - Parameters: `queuey.backend`, `queuey.clock`, `queuey.rabbitmq` (array),
    `queuey.declare_on_publish`, `queuey.events`, `queuey.workers` (processed array, handlers
    and hooks as service-id lists). Parameter `queuey.default_amqp_url` =
    `amqp://guest:guest@localhost:5672/%%2f` (for the `env(default:…)` above).
  - `Queuey\Clock` alias → `queuey.clock.system` (`SystemClock`) or `queuey.clock.frozen`
    (`ClockFactory::frozen()`: a `FrozenClock` at the real current time) per `clock`.
  - `Queuey\Backend` → `BackendFactory::make()` (factory service). Instantiated on demand only.
  - When `backend: memory`: also alias `Queuey\Memory\MemoryBackend` → `Queuey\Backend` and,
    **when PHPUnit is installed** (`class_exists(PHPUnit\Framework\Assert::class)` — the fake
    hard-depends on it, and a `dev` container on the memory backend must not blow up without a
    test framework), register `Queuey\Symfony\Test\QueueyFake` (needs `FrozenClock`; the fake
    asserts `clock: frozen` at construction and throws `InvalidQueueyConfiguration` for
    `queuey.clock` otherwise). Definition **and** alias are `public`: nothing in an application
    injects the fake, so as a private service `RemoveUnusedDefinitionsPass` deletes it and
    `QueueyAssertionsTrait::queuey()` throws `ServiceNotFoundException`.
  - `registerForAutoconfiguration(JobHandler::class)->addTag('queuey.job_handler')` and
    `registerForAutoconfiguration(DeadLetterHook::class)->addTag('queuey.dead_letter_hook')`:
    every autoconfigured handler/hook service belongs to worker `default` unless targeted.
  - `registerAttributeForAutoconfiguration(AsJobHandler::class, …)` adds
    `queuey.job_handler` with `worker: <name>` (the attribute is repeatable, one tag per
    worker). Same for `AsDeadLetterHook`. An attribute-targeted service does **not** also land
    on `default` through the interface autoconfiguration (HandlerPass ignores untargeted tags
    on a service that also carries a targeted one).
- `build(ContainerBuilder)`: `addCompilerPass(new HandlerPass())`.

### `HandlerPass`

Runs before `PassConfig::TYPE_BEFORE_REMOVING` (so `ServiceLocator` references keep the
services alive). Reads parameter `queuey.workers`, collects tags:

- `queuey.job_handler` / `queuey.dead_letter_hook`, attribute `worker` (default `default`).
- Appends each tagged service id to the matching worker's `handlers` / `dead_letter_hooks`
  (after the ones listed in config, de-duplicated, order stable).
- Unknown worker name in a tag → `InvalidQueueyConfiguration` at compile time, naming the
  service and the known workers. A service tagged without `worker` when no `default` worker
  exists → same error telling the user to add `#[AsJobHandler(worker: '…')]`.
- Rewrites `queuey.workers` with the merged lists and builds one `ServiceLocator` (over every
  referenced handler and hook id) injected into `WorkerFactory` as `$services`. Handlers are
  therefore instantiated only when a worker is built, never at kernel boot.

Attributes (`Queuey\Symfony\Attribute\`, `#[Attribute(TARGET_CLASS | IS_REPEATABLE)]`, final):
`AsJobHandler(string $worker = 'default')`, `AsDeadLetterHook(string $worker = 'default')`.

### `config/services.php`

All services private, autowired where sensible, explicit where it matters — with one exception:
the `queuey` alias is **public**. `Queuey` is the bundle's documented entry point, and in an
application that only runs `queuey:*` commands nothing injects it, so a wholly private definition
would be pruned and `$container->get('queuey')` (or a test fetching `Queuey::class`) would fail.
The alias is public; the definition behind it stays private, which is enough to keep it alive. The
bundle's own fixture kernel deliberately adds **no** public aliases of its own, so what its tests
can reach is exactly what an application's tests can reach.

| service | notes |
|---|---|
| `Queuey\Symfony\BackendFactory` | args: `%queuey.backend%`, `%queuey.rabbitmq%`, `Clock`, logger |
| `Queuey\Backend` | factory `[BackendFactory, 'make']`, `lazy: true` → interface proxy; the broker socket opens on the first backend call, so an eager service injecting `Queuey` never connects at construction. `backend: memory` sets `lazy: false` (the fake needs the real `MemoryBackend`) |
| `Queuey\Symfony\WorkerRegistry` | arg `%queuey.workers%` |
| `Queuey\Symfony\WorkerFactory` | args: argument 0, the handler/hook `ServiceLocator` (set by `HandlerPass` **by index**: the pass runs after `ResolveNamedArgumentsPass`, so `$services` would never be mapped), `WorkerRegistry`, dispatcher, `%queuey.events%`, logger, `Clock`. `workers(): WorkerRegistry` exposes the registry |
| `Queuey\Symfony\Queuey` | args: `Backend`, `WorkerRegistry`, `WorkerFactory`, `%queuey.declare_on_publish%`, logger, `Clock`; **public** alias `queuey` |
| `Queuey\Symfony\Command\WorkCommand`, `DeclareCommand`, `QueuesCommand` | tag `console.command` (via `#[AsCommand]`) |

Logger: `service('logger')->nullOnInvalid()` everywhere (fallback `NullLogger`), and tag
`monolog.logger` with `channel: queuey` on every service that logs, so apps with MonologBundle
get a `queuey` channel for free and apps without still work. Event dispatcher:
`service('event_dispatcher')->nullOnInvalid()`; `null` behaves like `events: false`.

Not registered: a shutdown/`kernel.terminate` hook. The `Backend` service stays open for the
process lifetime; on CLI exit PHP closes the socket. Under a long-running runtime (FrankenPHP
worker mode, Swoole, RoadRunner) the service is reused across requests — one AMQP connection
per worker process, which is what we want. Document.

## `BackendFactory`

`final class BackendFactory { public function __construct(string $backend, array $rabbitmq, Clock $clock, ?LoggerInterface $logger) {} public function make(): Backend; public function options(): RabbitMqOptions; }`

- `memory` → `new MemoryBackend($clock, sleeper)`, where the sleeper is a no-op **only on a
  `FrozenClock`** and core's own `usleep` otherwise. Verified in `MemoryBackend::wait()`: an
  idle call sleeps once and returns as soon as it sees the clock did not move, so on a frozen
  clock a no-op makes that immediate; on a *system* clock the clock always moves, so a no-op
  would turn the worker loop's 1s idle `wait()` into a busy spin.
- `rabbitmq` with a `FrozenClock` → `InvalidQueueyConfiguration` naming `queuey.clock`, thrown
  at first use (this is the "`clock: frozen` only with `backend: memory`" semantic rule; it
  lives here so it never fires at compile time).
- `$logger` is nullable so `config/services.php` can pass `service('logger')->nullOnInvalid()`;
  `null` becomes a `NullLogger`. Same for `Queuey` and `WorkerFactory`.
- `rabbitmq` → `RabbitMqBackend::connect($url, $this->options(), $logger)`. `options()` maps
  the array onto `RabbitMqOptions` constructor args (`Duration::parse()` for the duration
  strings; `reconnect.enabled === false` → `false`, else
  `new BackoffPolicy($maxAttempts, Backoff::exponential(base, factor, max, jitter))`).
- Anything else → `InvalidQueueyConfiguration` naming `queuey.backend`.
- `options()` is public so tests assert the mapping without a broker.

`ClockFactory::frozen(): FrozenClock` = `new FrozenClock((new SystemClock())->nowMs())`.

## `Queuey` (the manager, autowire by type)

```php
final class Queuey
{
    public function __construct(Backend $backend, WorkerRegistry $workers, WorkerFactory $factory, bool $declareOnPublish, ?LoggerInterface $logger, Clock $clock) {}

    public function backend(): Backend;
    /** @param class-string<QueueSet> $queueSet */
    public function producer(string $queueSet): Producer;            // cached per class; declare per config
    public function enqueue(Job $job, ?EnqueueOptions $options = null): string;   // queue set from the job's #[OnQueue]
    public function enqueueAfter(Job $job, Duration|string $delay): string;
    public function defer(Job $job, Duration|string $delay): string;
    public function worker(string $name = 'default'): Worker;        // built fresh each call via WorkerFactory
    public function definition(string $name = 'default'): WorkerDefinition;
    public function workers(): WorkerRegistry;
    /** Forget cached producers. */
    public function flush(): void;
}
```

- Queue-set-from-job: `Queuey\Internal\JobMetadata::for($job::class)->queue::class`. It is
  `@internal` to core but this is the same project; one private `queueSetOf(Job $job)` with a
  one-line comment so it is trivial to swap if core exposes a public accessor.
- `Duration|string` accepts core's grammar via `Duration::parse()`.
- Producers cached per queue-set class; `flush()` empties the cache.

## Worker definitions

```php
final class WorkerDefinition
{
    /** @param class-string<QueueSet> $queueSet @param list<string> $only @param list<string> $handlers service ids @param list<string> $deadLetterHooks service ids @param array<string, RetryPolicy> $queueRetryOverrides by case name @param array<string, RetryPolicy> $jobRetryOverrides by job class or wire name */
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

    /** @param array<string, mixed> $config the processed `queuey.workers.<name>` array */
    public static function fromConfig(string $name, array $config): self;   // semantic validation; InvalidQueueyConfiguration with key path
    public function withHandlers(string ...$serviceIds): self;         // appends
    public function withDeadLetterHooks(string ...$serviceIds): self;  // appends
    public function only(string ...$cases): self;
    public function withJobTimeout(Duration|string|null $t): self;
    public function withMaxJobs(?int $n): self;
    public function withQueueRetry(string $case, RetryPolicy $p): self;
    public function withJobRetry(string $jobClass, RetryPolicy $p): self;
    /** @return list<QueueSet> the cases selected by `only` (all when empty); InvalidQueueyConfiguration for an unknown case name */
    public function selectedQueues(): array;
}

final class WorkerRegistry
{
    /** @param array<string, array<string, mixed>> $config `%queuey.workers%` after HandlerPass */
    public function __construct(array $config) {}
    public function define(WorkerDefinition $definition): void;    // add or replace (tests, programmatic use)
    public function get(string $name): WorkerDefinition;           // UnknownWorker if missing; built lazily from config, cached
    public function has(string $name): bool;
    /** @return array<string, WorkerDefinition> */ public function all(): array;
    /** @return list<string> */ public function names(): array;
}
```

Programmatic registration is the Symfony way: tag a service or use `#[AsJobHandler]`. There is
no runtime `extend()`; `define()` exists for tests and for apps that build definitions in a
compiler pass of their own.

## `WorkerFactory`

`make(WorkerDefinition $def, Backend $backend, ?callable $configure = null, bool $forceEvents = false): Worker`:
`Worker::builder($def->queueSet, $backend)`; for each handler service id `$services->get()` →
must be a `JobHandler` (else `NotAHandler`), wrapped in `EventDispatchingHandler` when events
are on (`%queuey.events%` and a dispatcher present, or `$forceEvents`), `->handler()`. Queue
overrides via `retryOverride($case, $policy)`, job overrides via `jobRetryOverride()`.
Dead-letter hooks: resolve each (must be `DeadLetterHook`, else `NotAHandler`), plus an
`EventDispatchingDeadLetterHook` when events are on, combined in `CompositeDeadLetterHook`
(calls all, logs and swallows per-hook exceptions so one bad hook cannot stop the job being
dead-lettered — core's `Worker` also dead-letters after a throwing hook, verified at
`Worker.php:501-513`). `->queues(...$def->selectedQueues())` when `only` is non-empty,
`->jobTimeout`, `->maxJobs`, `->logger`, `->clock`. `$configure` receives the builder before
`build()` for CLI overrides.

## Handler decorator — `EventDispatchingHandler`

Implements `JobHandler` **and** `ProvidesRetryPolicy`. `jobClass()` delegates.
`retryPolicyFor()` delegates when the inner handler implements `ProvidesRetryPolicy`, else
`null` — core treats `null` as "no opinion" (`WorkerBuilder.php:106-108`; note it in a
comment). `handle()`:

```
dispatch(JobProcessing($job, $ctx))
$start = clock
try { inner->handle($job, $ctx); dispatch(JobProcessed($job, $ctx, runtime)); }
catch (JobDeferred $e) { dispatch(JobDeferring($job, $ctx, $e)); throw $e; }
catch (Throwable $e) { dispatch(JobFailed($job, $ctx, $e, willRetry: !($e instanceof FatalFailure) && !$ctx->isLastAttempt())); throw $e; }
```

Events never change the outcome: each dispatch is wrapped in its own try/catch, listener
exceptions are logged at `error`, the *original* handler exception is rethrown (nothing on
success).

Events (`Queuey\Symfony\Event\`, final, extend `Symfony\Contracts\EventDispatcher\Event`,
readonly public properties, dispatched by class name):
`JobProcessing(Job $job, JobContext $context)`, `JobProcessed(Job, JobContext, Duration $runtime)`,
`JobFailed(Job, JobContext, Throwable $exception, bool $willRetry)`,
`JobDeferring(Job, JobContext, JobDeferred $deferral)`, `JobDeadLettered(DeadLetter $deadLetter)`,
`WorkerStarting(string $worker, list<string> $queues)`,
`WorkerStopped(string $worker, int $processed, int $settleFailures, ?Throwable $cause)`.

## Console commands

`#[AsCommand]`, Symfony option style, all options optional:

```
queuey:work [<worker>]                default: default
    --queue=QUEUE        (multiple)   Only these queue cases (enum case names); narrows only
    --max-jobs=N                      Stop after this many jobs
    --job-timeout=DURATION            Per-job timeout, e.g. 30s (needs ext-pcntl)
    --for=DURATION                    Stop after this much time, e.g. 1h
    --once                            Wait for one job, process it, exit (= --max-jobs=1); blocks until a job arrives, bound with --for
    --quiet-jobs                      Do not print a line per job
queuey:declare [<worker>...]          default: every configured worker
    --set=CLASS          (multiple)   Declare these QueueSet classes directly
queuey:queues [<worker>]              table: worker, queue set, case, broker queue, prefetch, durable, ttl, max priority, retry, handlers
```

`queuey:work` behaviour:
- Resolves the definition, applies CLI overrides through `WorkerFactory::make(..., configure:)`.
- `--queue` **narrows, never widens**: it is resolved against `WorkerDefinition::selectedQueues()`,
  not against the whole enum, so `queuey:work mail --queue=Images` on a worker configured
  `only: [Emails]` is an `InvalidQueueyConfiguration` (exit 2) naming that worker's allowed cases
  instead of silently undoing the restriction.
- The dispatcher is type-hinted `?Symfony\Contracts\EventDispatcher\EventDispatcherInterface`,
  because `event_dispatcher` is injected with `nullOnInvalid()` and an application may bind that id
  to any implementation of the contract. The per-job listeners are added (and removed) only when it
  is also a `Symfony\Component\EventDispatcher\EventDispatcherInterface`; otherwise the run is
  unaffected and there is simply no per-job output.
- Header (worker, backend class, queues, handler count) unless `-q`.
- Dispatches `WorkerStarting`; runs `run()` / `runFor()`; dispatches `WorkerStopped` (also
  on exception, with `$cause`).
- Per-job output (unless `--quiet-jobs`) from listeners on `JobProcessed` / `JobFailed` /
  `JobDeadLettered` added to the dispatcher in `execute()` and removed in `finally`. If
  `events: false`, the command passes `forceEvents: true` because the operator asked for the
  output. Format: `  2026-09-22 10:00:00 App\Job\SendMail ........ 12ms DONE` /
  `FAIL (retry in 2s)` / `DEAD`.
- Signals: core `Worker` installs SIGTERM/SIGINT handlers when pcntl is loaded. Do **not**
  install our own and do not use Symfony's `SignalableCommandInterface`. `--for` → `runFor()`.
- Exit codes: `Command::SUCCESS` (0) on clean shutdown (signal, `--max-jobs`, `--for`),
  `Command::FAILURE` (1) on `ConsumerStopped` / `BackendException` (broker gone for good),
  `Command::INVALID` (2) on configuration errors (`InvalidConfiguration`, `UnknownWorker`)
  with the message on stderr.
- **Never** swallow `ConsumerStopped`: a supervisor needs the non-zero exit to restart.
- Not implemented on purpose (README "Not Messenger: differences from `messenger:consume`"):
  `--limit`/`--memory-limit`/`--time-limit` spellings (ours are `--max-jobs`, `--for`),
  `--sleep`, `--bus`, `--failure-limit`, retry strategy options (retry is policy-driven and
  must match Rust), `messenger:failed:*` (dead letters live on `q.dead`).

`queuey:queues` resolves each worker's handler *service ids* through
`WorkerFactory::service()` — added for this, since the handler/hook `ServiceLocator` is
private to the factory — and reads `#[OnQueue]` off the handler's `jobClass()` to decide
which case it serves. A handler the container cannot build, or one that serves a queue this
worker does not consume, becomes a warning beside the table rather than a crash — including the
subtle case: the handler's queue *is* in the worker's set, but the worker's `only` excluded it, so
the row loop never printed it. That warning is emitted after the rows, one per handler whose case
is not in `selectedQueues()`.

`queuey:declare` exits `0` declared, `1` the broker refused or could not be reached (resolving the
closure throws `BackendException`/`ConsumerStopped`; the message goes to stderr like
`queuey:work`'s), `2` bad input.

`queuey:work` and `queuey:declare` take the `Backend` as a `service_closure()`, not as the
service: resolving it is what opens the AMQP connection, and a typo'd worker name or a bad
`--set` has to be reported before anything dials the broker. That also keeps the tag-driven
lazy command loader honest — `bin/console list` never connects.

## Testing support

Configure the `test` environment to use the in-process backend and a frozen clock:

```yaml
# config/packages/queuey.yaml
when@test:
  queuey:
    backend: memory
    clock: frozen
```

Then in a `KernelTestCase` / `WebTestCase`:

```php
use Queuey\Symfony\Test\QueueyAssertionsTrait;

final class SignupTest extends WebTestCase
{
    use QueueyAssertionsTrait;   // needs static::getContainer()

    public function testItQueuesTheWelcomeMail(): void
    {
        // ... act ...
        $this->assertJobEnqueued(SendWelcomeMail::class, fn (SendWelcomeMail $job) => $job->to === 'a@b.c');
        $this->queuey()->workAll();            // run handlers with real DI, release every hold
        $this->assertNothingEnqueued();
    }
}
```

```php
final class QueueyFake
{
    // `Clock`, not `FrozenClock`: the service is registered whenever `backend: memory`, and
    // the constructor is where `clock: frozen` is insisted on — a `FrozenClock` type hint
    // would make that a TypeError instead of an InvalidQueueyConfiguration.
    public function __construct(Queuey $queuey, WorkerFactory $factory, WorkerRegistry $workers, MemoryBackend $backend, Clock $clock) {}
    public function backend(): MemoryBackend;
    public function clock(): FrozenClock;
    public function worker(string $worker = 'default'): Worker;  // built on the fake backend
    public function travel(Duration|string $by): void;          // advance the clock; holds release on next work()

    // Assertions — `$callback` receives the decoded Job and the Envelope, returns bool.
    public function assertEnqueued(string $jobClass, ?callable $callback = null): void;      // pending OR held
    public function assertEnqueuedTimes(string $jobClass, int $times): void;
    public function assertNotEnqueued(string $jobClass, ?callable $callback = null): void;
    public function assertNothingEnqueued(): void;
    public function assertDelayed(string $jobClass, ?callable $callback = null): void;       // held (enqueueAfter / defer / retry)
    public function assertDeadLettered(string $jobClass, ?callable $callback = null): void;  // callback gets (Job|null, Envelope, string $reason)
    public function assertNothingDeadLettered(): void;
    /** @return list<Job> */ public function enqueued(string $jobClass): array;
    /** @return list<Job> */ public function delayed(string $jobClass): array;
    /** @return list<array{envelope: Envelope, reason: string}> */ public function deadLettered(string $jobClass): array;

    /** Run the named worker's handlers over what is pending now; returns jobs processed. */
    public function work(string $worker = 'default', ?int $maxJobs = null): int;
    /** Release everything held (as if all delays elapsed) then work(); repeat until quiet. */
    public function workAll(string $worker = 'default'): int;
}
```

- Registered as a service only when `backend: memory`; constructor throws
  `InvalidQueueyConfiguration` for `queuey.clock` when the `Clock` service is not a
  `FrozenClock`, and for `queuey.backend` when the manager publishes into another backend. `QueueyAssertionsTrait::queuey(): QueueyFake` fetches it from
  `static::getContainer()`, and the trait mirrors every `assert*` method as
  `assertJob*` / `assertNothing*` delegating to the fake. `KernelTestCase` reboots the kernel
  per test, so every test starts with an empty backend.
- Job-class matching: compare `Envelope::$jobType` to `JobMetadata::for($jobClass)->name` (wire
  name, respects `#[OnQueue(name:)]`). Decode with `$envelope->decode($jobClass)`.
- Queues scanned for `enqueued()` etc.: every queue of every configured worker's queue set,
  plus any queue set a producer was built for (`Queuey` exposes the cached producers' queue
  sets to the fake; or scan `MemoryBackend::declared()` — pick the simpler that works).
- `work()` builds the worker via `WorkerFactory` on the fake backend, runs
  `runUntil(fn () => nothing pending on its queues)` with `maxJobs` if given. Frozen clock +
  no-op sleeper: an idle `wait()` returns immediately. Verify no spin (`Worker::run()` docblock).
- `workAll()` = `travel()` far enough that every `held()` entry is due, then `work()`; loop
  until pending and held are empty or nothing progressed (a retry re-holds; cap at 1000
  iterations and throw a clear `LogicException`).
- Assertions use `PHPUnit\Framework\Assert::assertTrue(...)` with messages listing what *was*
  enqueued, so they fail like any other PHPUnit/Pest assertion. The message is built **lazily**
  (a closure the helper only invokes on failure) and the backend is scanned **once** per
  assertion: summarising costs a walk over every queue, and the passing case should not pay it.

**Deviations (phase 2, as built):**

- Queues scanned = `MemoryBackend::declared()` **plus** every queue of every configured worker's
  queue set, de-duplicated. The union is barely more code than either half and keeps working
  before anything was published and with `declare_on_publish: false`.
- `workAll()` cannot ask the memory backend when a hold falls due (`held()` returns envelopes,
  not deadlines), so a round that sees anything held travels a **fixed one hour** rather than
  "far enough". With the 1000-round cap that covers ~6 weeks of virtual time.
- `workAll()` also stops early when a round processed nothing, nothing is held and the pending
  count did not move: a job on a queue the named worker does not consume can never progress, and
  spinning to the cap would only turn a wrong worker name into a slow `LogicException`.
- Every `assert*` taking a `$callback` is generic over the job class (`@template J of Job`), so a
  closure may narrow its parameter to the concrete job and still pass PHPStan at level max.
- The fixture kernel registers **no public aliases** for the bundle's services, so
  `static::getContainer()` there sees exactly what an application's test suite would see and the
  suite is itself the check that nothing documented gets pruned.
- The fixture kernel overrides the `logger` service with a `NullLogger`. FrameworkBundle's
  stand-in logger writes `error` to the process output, a worker that gives up on a job logs at
  exactly that level, and `beStrictAboutOutputDuringTests` + `failOnRisky` would fail the test.

## Exceptions (`Queuey\Symfony\Exception\`)

- `InvalidQueueyConfiguration extends Queuey\Exception\InvalidConfiguration` with
  `::forKey(string $key, string $problem)`.
- `UnknownWorker extends InvalidQueueyConfiguration` with `::named(string $name, array $known)`.
- `NotAHandler extends InvalidQueueyConfiguration` (`::service(string $id, string $expected, string $actual)`).

## Tests (Pest 4 on `KernelTestCase`)

- `tests/Fixtures/App/TestKernel.php` (`MicroKernelTrait`): FrameworkBundle + QueueyBundle,
  `framework: { test: true, secret: test, http_method_override: false, handle_all_throwables: true, php_errors: { log: true } }`,
  `queuey: { backend: memory, clock: frozen, workers: { default: {...}, mail: {...} } }`,
  fixture services autowired from `tests/Fixtures/`. Env `integration` (`APP_ENV=integration`)
  swaps in `backend: rabbitmq`, `url: '%env(AMQP_URL)%'`.
- Fixtures: `AppQueues` enum (prefix `queuey_symfony_test`, cases `Emails`, `Images`,
  `Reports`), jobs, handlers: one with a constructor dependency (a `Recorder` service) to
  prove DI, one `ProvidesRetryPolicy`, one throwing `RetryableFailure` until attempt N, one
  that defers once then succeeds, one `#[AsJobHandler(worker: 'mail')]`, one dead-letter hook
  service.
- `tests/TestCase.php extends KernelTestCase` with `use QueueyAssertionsTrait`;
  `tests/Pest.php`: `pest()->extend(TestCase::class)->in('Feature')` and
  `pest()->extend(IntegrationTestCase::class)->in('Integration')` — the broker-backed folder
  needs a case that boots the kernel in the `integration` environment and cleans the fixture
  queues off the broker, and Pest allows one case per folder; Unit tests extend Pest's default
  case. Closures reaching for `$this` helpers open with
  `/** @var TestCase $this */` for PHPStan (same trick as core).
- Unit: `BackendFactory::options()` (every key, both reconnect shapes, invalid → key path),
  `WorkerDefinition::fromConfig` (retry_overrides → `RetryPolicy`, unknown case names,
  non-enum class), `WorkerRegistry` (`UnknownWorker` lists known), `EventDispatchingHandler`
  (events, exceptions rethrown unchanged, listener exception never changes outcome,
  `retryPolicyFor` delegation), `CompositeDeadLetterHook`, `HandlerPass` on a bare
  `ContainerBuilder` (config list + tag merge, attribute targeting, unknown worker error,
  untargeted with no default worker error), the config tree (`Processor` on the bundle's
  `configure()`: defaults, invalid `backend` enum).
- Feature (booted kernel): services resolve, `Queuey` autowires, `enqueue()` lands on the right
  memory queue, fake assertions positive + negative (message content), `work()` runs handlers
  with DI, `workAll()` processes a retry and a deferral end to end, `queuey:work --once` /
  `--max-jobs` via `CommandTester` (pre-publish jobs, assert output lines + exit code),
  `queuey:queues` table, `queuey:declare` (`declared()` lists the queues), config errors →
  exit code 2 with message, `clock: frozen` + `rabbitmq` refused.
- Integration (`AMQP_URL`, skip otherwise, same pattern as core's `RabbitMqTestCase`):
  `queuey:declare`, `Queuey::enqueue()`, `queuey:work --once` against RabbitMQ; one
  retry-then-succeed scenario; cleanup deletes the fixture queues.
- CI: `.github/workflows/ci.yml` — matrix PHP 8.3/8.4/8.5 × Symfony 6.4/7.x/8.x (no PHP 8.3 ×
  Symfony 8), RabbitMQ service for the integration step. No lock file is committed: every leg
  pins its Symfony components with `composer require --no-update` and then runs
  `composer update` (no Flex, no `SYMFONY_REQUIRE`). The bundle's own test suite on 6.4 runs
  with `symfony/console` 7.x because Pest 4 requires it — which a blanket
  `SYMFONY_REQUIRE=6.4.*` makes unresolvable — so the 6.4 leg pins the other components only
  and leaves the console floating.
- Release: `.github/workflows/release.yml` on `vX.Y.Z` tags, after `queuey/queuey` is on
  Packagist; see the README's "Releasing" section.

## README outline

Install (path repo note, `config/bundles.php`) → `config/packages/queuey.yaml` → define a
queue set + job + handler (link core README; minimum here) → handlers via autoconfiguration /
`#[AsJobHandler(worker:)]` / explicit service ids → publish from a controller (`Queuey`
autowired) → run `bin/console queuey:work` under Supervisor / systemd (sample stanza with
`--max-jobs=1000`, `stopwaitsecs` ≥ job timeout) → events → testing (`when@test`, trait,
`QueueyFake`) → interop notes (set `name:` on jobs; options must match the Rust side; Rust
declares or `queuey:declare`; never define `AMQP_STRICT_FLD_TYPES`) → "Not Messenger:
differences from `messenger:consume`" table → long-running runtimes note → config reference.

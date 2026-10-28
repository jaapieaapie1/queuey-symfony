# Changelog

All notable changes to `queuey/symfony` are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project
adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0] - 2026-10-28

First release of the Symfony bundle for [`queuey/queuey`](https://github.com/jaapieaapie1/queuey-php)
(`1.0.0-dev`). It is not a Symfony Messenger transport and never will be: Messenger's serialization
and retry semantics cannot match the queuey wire contract the Rust workers rely on.

### Added

- **`QueueyBundle`** (`AbstractBundle`): the `queuey` configuration tree, the container wiring and
  the `HandlerPass` compiler pass. Shape errors are caught by the Config component at compile time;
  semantic errors surface at first use as `InvalidQueueyConfiguration` naming the exact key, so a
  bad `QUEUEY_AMQP_URL` cannot break `cache:clear`.
- **`Queuey`** manager (alias `queuey`): `enqueue()`, `enqueueAfter()`, `defer()`, `producer()`,
  `worker()`, `definition()`, `workers()`, `backend()`, `flush()`. One `Producer` cached per queue
  set on top of the process's single `Backend`.
- **`BackendFactory`** / **`ClockFactory`**: the `rabbitmq` and `memory` backends from
  configuration, the `system` and `frozen` clocks, and a no-op sleeper on a frozen clock only.
- **Worker definitions**: `WorkerDefinition`, `WorkerRegistry`, `WorkerFactory` — named workers
  with `only`, `job_timeout`, `max_jobs` and queue/job retry overrides.
- **Handler registration** three ways: the `JobHandler` / `DeadLetterHook` interfaces
  (autoconfigured onto the `default` worker), the repeatable `#[AsJobHandler(worker:)]` and
  `#[AsDeadLetterHook(worker:)]` attributes, and explicit service ids in the config. Handlers are
  resolved lazily from a service locator, so they cost nothing at kernel boot.
- **Events**: `JobProcessing`, `JobProcessed`, `JobFailed`, `JobDeferring`, `JobDeadLettered`,
  `WorkerStarting`, `WorkerStopped`, dispatched through `EventDispatchingHandler` /
  `EventDispatchingDeadLetterHook`. A throwing listener is logged and never changes a job's
  outcome. `events: false` removes the decorators entirely.
- **`CompositeDeadLetterHook`**: several hooks behind core's single-hook slot, each one's
  exceptions logged and swallowed so a bad hook cannot stop a job being dead-lettered.
- **Console commands**: `queuey:work` (with `--queue`, `--max-jobs`, `--job-timeout`, `--for`,
  `--once`, `--quiet-jobs`), `queuey:declare` (with `--set`) and `queuey:queues`. Exit codes: `0`
  clean shutdown, `1` broker gone (`ConsumerStopped` is never swallowed), `2` configuration error.
- **A lazy `Queuey\Backend` service**: the container hands out a proxy implementing the interface
  and builds the real backend on the first call through it. Injecting `Queuey` into a service
  Symfony constructs eagerly — a `#[AsCommand]` without a `description:`, a Twig extension, an
  event subscriber — no longer opens an AMQP socket, so `bin/console list` and `cache:clear` work
  with the broker down. With `backend: memory` the service stays eager: both concrete backends are
  `final`, so the proxy can only implement the interface, and the `MemoryBackend` alias the test
  fake autowires has to keep its type.
- **`queuey:queues` warns about a consumed queue with no handler** in that worker: the deliveries
  are not skipped, they are dead-lettered with `no handler for job type ...`, which is what two
  workers sharing one queue produce when only one of them has the handler.
- **Testing support**: `QueueyFake` (assertions over what was published, `travel()`, `work()`,
  `workAll()`) and `QueueyAssertionsTrait` for `KernelTestCase` / `WebTestCase`, registered
  whenever `backend: memory`.
- **Logging**: every logging service is tagged `monolog.logger` with `channel: queuey`, and falls
  back to a `NullLogger` when the application has no `logger` service.

### Notes

- Symfony 8.x is supported alongside 6.4 and 7.x: every `symfony/*` constraint is
  `^6.4 || ^7.0 || ^8.0`. No code change was needed; the bundle already used `AbstractBundle`,
  `#[AsCommand]`, typed `configure()`/`execute()` and the contracts event dispatcher, and the lazy
  `Queuey\Backend` proxy still implements `LazyObjectInterface` under Symfony 8. Symfony 8 needs
  PHP 8.4 or newer; the suite also runs on PHP 8.5.

- No `kernel.terminate` or shutdown hook is registered: under FrankenPHP worker mode, Swoole or
  RoadRunner the `Backend` service is reused for the life of the process, which is one AMQP
  connection per worker process.
- Symfony 6.4, 7.x and 8.x are supported. The bundle's own test suite on 6.4 runs with
  `symfony/console` 7.x because Pest 4 requires it, so the 6.4 CI leg pins the other Symfony
  components only. Applications on 6.4 are unaffected: nothing in the bundle needs console 7.

[Unreleased]: https://github.com/jaapieaapie1/queuey-symfony/commits/main

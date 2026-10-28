# queuey/symfony

Symfony bundle for [`queuey/queuey`](https://github.com/jaapieaapie1/queuey-php), the pure-PHP,
Rust-wire-compatible job queue.

**This is not a Symfony Messenger transport.** No bus, no envelopes, no stamps, no
`messenger:consume`, no `#[AsMessageHandler]`. Messenger's serialization and retry semantics
cannot match the queuey wire contract that Rust workers rely on, so everything here sits directly
on core's `Producer`, `Worker` and `Backend`. If you want a Messenger transport, this is the wrong
package; if you want PHP and Rust workers eating from the same RabbitMQ queues, read on.

What the bundle adds, and nothing more:

1. **Container wiring** — one configured `Backend`, a `Producer` per queue set, handlers and
   dead-letter hooks built by the container with constructor injection.
2. **Configuration** — `config/packages/queuey.yaml` under the `queuey` root.
3. **Console commands** — `queuey:work`, `queuey:declare`, `queuey:queues`.
4. **Events** — a Symfony event around each job and each dead letter.
5. **Testing** — an in-process backend plus a frozen clock for the `test` environment, a
   `QueueyFake` with assertions, and a trait for `KernelTestCase` / `WebTestCase`.

Wire compatibility is entirely the core library's job. This bundle never touches envelopes, queue
arguments, suffixes, TTL rounding or dead-letter reason strings.

---

## Requirements

- PHP 8.3 or newer (PHP 8.4 or newer with Symfony 8; tested up to PHP 8.5)
- Symfony 6.4, 7.x or 8.x
- RabbitMQ 3.8 or newer (for the `rabbitmq` backend)
- `ext-pcntl` for graceful `SIGTERM` shutdown and for `job_timeout` (optional, strongly
  recommended in production)

Symfony 6.4, 7.x and 8.x are all supported. The bundle's own test suite on 6.4 runs with
`symfony/console` 7.x because Pest 4 requires it, so the 6.4 CI leg pins the other components only.

## Install

```bash
composer require queuey/symfony
```

Register the bundle by hand. There is no Flex recipe — `"type": "symfony-bundle"` alone does not
make Flex do anything, so nothing is added for you:

```php
// config/bundles.php
return [
    // ...
    Queuey\Symfony\QueueyBundle::class => ['all' => true],
];
```

Create the config file by hand too (see below).

## Configure

```yaml
# config/packages/queuey.yaml
queuey:
  backend: rabbitmq
  rabbitmq:
    url: '%env(default:queuey.default_amqp_url:QUEUEY_AMQP_URL)%'

  workers:
    default:
      queues: App\Queue\AppQueues
      only: [Images]
    mail:
      queues: App\Queue\AppQueues
      only: [Emails]
      max_jobs: 1000

when@test:
  queuey:
    backend: memory
    clock: frozen
```

Every key is optional except `workers.<name>.queues`. The
[full reference](#configuration-reference) is at the bottom.

> **Split the queues between workers, or give every worker every handler.** A worker consumes a
> queue, not a job type: whichever process the broker hands a delivery to is the one that has to
> handle it. Two workers on the same queue where only one has the handler is not a fallback — half
> the deliveries land in the process that does not know the job type, and that process dead-letters
> them with `no handler for job type ...`. This sample gives each worker its own `only`, which is
> why moving `SendWelcomeMailHandler` to the `mail` worker below is safe. If you do want workers to
> share a queue — for throughput — register every handler for that queue on all of them.
> `queuey:queues` prints a warning for each queue a worker consumes and has no handler for.

Two layers of validation, both naming the exact key:

- **Shape** at container compile time, by the Config component (enum values, ints, floats, lists).
- **Semantics** at first use, as `Queuey\Symfony\Exception\InvalidQueueyConfiguration` — the class
  is a `QueueSet` enum, `only` names real cases, duration strings parse, `clock: frozen` only with
  `backend: memory`. Never at boot and never while compiling, so a bad `QUEUEY_AMQP_URL` or a typo
  in a case name cannot break `cache:clear`.

**When the socket opens.** The `Queuey\Backend` service is declared `lazy`, so the container hands
out a proxy implementing the interface and calls the factory — which is where the URL's `%env()%`
placeholders resolve and where RabbitMQ is dialed — on the **first method call through it**.
Injecting `Queuey` therefore costs nothing, even in a service Symfony builds eagerly: a
`#[AsCommand]` without a `description:`, a Twig extension, an event subscriber. `bin/console list`,
`cache:clear` and `debug:container` stay usable with the broker down. What does connect is the
first call that needs the broker: `enqueue()`, `enqueueAfter()`, `defer()`, `backend()->declare()`,
or a worker starting — and an unreachable broker surfaces there, as a `BackendException` (exit `1`
from the `queuey:*` commands).

## A queue set, a job and a handler

The model is core's; the [core README](https://github.com/jaapieaapie1/queuey-php#quickstart) is
the place to learn it properly. The minimum is three classes:

```php
// src/Queue/AppQueues.php
namespace App\Queue;

use Queuey\Attributes\Queue;
use Queuey\Attributes\Queues;
use Queuey\Attributes\Retry;
use Queuey\QueueSet;
use Queuey\QueueSetFromAttributes;

#[Queues(prefix: 'myapp')]
enum AppQueues implements QueueSet
{
    use QueueSetFromAttributes;

    #[Queue(prefetch: 10)]
    case Emails;                                     // -> myapp.emails

    #[Queue(retry: new Retry(maxAttempts: 3, backoff: 'exponential', base: '1s', max: '2m'))]
    case Images;                                     // -> myapp.images
}
```

```php
// src/Job/SendWelcomeMail.php
namespace App\Job;

use App\Queue\AppQueues;
use Queuey\Attributes\OnQueue;
use Queuey\Job;
use Queuey\PayloadFromProperties;

#[OnQueue(AppQueues::Emails, name: 'myapp::jobs::SendWelcomeMail')]
final class SendWelcomeMail implements Job
{
    use PayloadFromProperties;

    public function __construct(public readonly string $to) {}
}
```

```php
// src/Handler/SendWelcomeMailHandler.php
namespace App\Handler;

use App\Job\SendWelcomeMail;
use Queuey\Job;
use Queuey\JobContext;
use Queuey\JobHandler;
use Symfony\Component\Mailer\MailerInterface;

/** @implements JobHandler<SendWelcomeMail> */
final class SendWelcomeMailHandler implements JobHandler
{
    // Constructor injection: the container builds the handler, not the bundle.
    public function __construct(private readonly MailerInterface $mailer) {}

    public function jobClass(): string
    {
        return SendWelcomeMail::class;
    }

    public function handle(Job $job, JobContext $ctx): void
    {
        \assert($job instanceof SendWelcomeMail);

        $this->mailer->send(/* ... */);
    }
}
```

A dead-letter hook is the same shape — one method, constructor injection, registered by interface
or by attribute — and is called for every job its worker gives up on:

```php
// src/Hook/NotifySentry.php
#[AsDeadLetterHook]                                  // or: #[AsDeadLetterHook(worker: 'mail')]
final class NotifySentry implements DeadLetterHook
{
    public function onDeadLetter(DeadLetter $dead): void
    {
        $this->sentry->captureMessage($dead->envelope->jobType . ': ' . $dead->reason);
    }
}
```

A throwing hook is logged and swallowed: it is a notification, not a veto, and it must tolerate
being called twice for one job (the delivery is settled after it runs).

Handlers are built lazily, through a service locator, only when a worker is actually constructed —
never at kernel boot, so a request that publishes a job pays nothing for the handlers it will not
run.

## Registering a handler — three ways

**1. Implement the interface.** With the default `services.yaml` autoconfiguration, every service
implementing `Queuey\JobHandler` is registered on the worker named `default`, and every
`Queuey\DeadLetterHook` likewise:

```php
final class SendWelcomeMailHandler implements JobHandler { /* ... */ }
```

**2. Target another worker with the attribute.** `#[AsJobHandler]` is repeatable, so one handler
can serve several workers. A handler carrying the attribute does **not** also land on `default`:

```php
use Queuey\Symfony\Attribute\AsJobHandler;

#[AsJobHandler(worker: 'mail')]
#[AsJobHandler(worker: 'mail_overflow')]
final class SendWelcomeMailHandler implements JobHandler { /* ... */ }
```

`#[AsDeadLetterHook(worker: '...')]` does the same for a `DeadLetterHook`.

**3. Name the service id in the config.** For a service you do not want autoconfigured, or one
registered under an id that is not its class name:

```yaml
queuey:
  workers:
    default:
      queues: App\Queue\AppQueues
      handlers:
        - App\Handler\SendWelcomeMailHandler
        - app.handler.legacy_import
      dead_letter_hooks:
        - App\Hook\NotifySentry
```

Config ids come first, tagged services after, de-duplicated, order stable. A tag naming a worker
that does not exist is a container compile error that lists the workers you do have.

## Publishing

Autowire `Queuey\Symfony\Queuey` (or the `queuey` alias) anywhere — controller, command, message
handler, doctrine listener:

```php
namespace App\Controller;

use App\Job\SendWelcomeMail;
use Queuey\Symfony\Queuey;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final class SignupController
{
    public function __construct(private readonly Queuey $queuey) {}

    #[Route('/signup', methods: ['POST'])]
    public function __invoke(): JsonResponse
    {
        $jobId = $this->queuey->enqueue(new SendWelcomeMail('ada@example.com'));

        // Later, or with a delay:
        $this->queuey->enqueueAfter(new SendWelcomeMail('bob@example.com'), '10m');

        return new JsonResponse(['job_id' => $jobId]);
    }
}
```

| method | what it does |
|---|---|
| `enqueue(Job $job, ?EnqueueOptions $options = null): string` | publish now; the queue comes from the job's `#[OnQueue]`. Returns the job id |
| `enqueueAfter(Job $job, Duration\|string $delay): string` | become visible after `$delay`, **behind** the backlog |
| `defer(Job $job, Duration\|string $delay): string` | hold for `$delay`, then release at the **front** of the queue |
| `producer(string $queueSet): Producer` | the cached `Producer` for a queue set, for anything the three above do not cover |
| `worker(string $name = 'default'): Worker` | build a worker; fresh every call |
| `definition(string $name = 'default')`, `workers()` | the configured `WorkerDefinition` / the registry |
| `backend(): Backend` | the one backend of this process |
| `flush(): void` | forget the cached producers |

A handler may throw `Queuey\Exception\JobDeferred` to put its own job back on hold (rate limits,
`Retry-After`) without burning an attempt, and `Queuey\Exception\FatalFailure` to dead-letter
immediately without retrying. Everything else that escapes a handler is a retryable failure judged
by the effective retry policy.

## Running a worker

```
queuey:work [<worker>]                default: default
    --queue=QUEUE        (multiple)   Only these queue cases (enum case names); narrows only
    --max-jobs=N                      Stop after this many jobs
    --job-timeout=DURATION            Per-job timeout, e.g. 30s (needs ext-pcntl)
    --for=DURATION                    Stop after this much time, e.g. 1h
    --once                            Wait for one job, process it, exit (= --max-jobs=1)
    --quiet-jobs                      Do not print a line per job

queuey:declare [<worker>...]          default: every configured worker
    --set=CLASS          (multiple)   Declare these QueueSet classes directly

queuey:queues [<worker>]              table: worker, queue set, case, broker queue, prefetch,
                                      durable, ttl, max priority, retry, handlers
```

`--once` **blocks**: it is `--max-jobs=1`, and a worker with nothing to do waits for a delivery
rather than exiting. On an idle queue it sits there. Bound the wait with `--for`
(`queuey:work --once --for=30s`) whenever something other than a person is watching — a cron entry,
a health check, a test.

`--queue` **narrows, it never widens**: it is resolved against the cases the worker's `only`
already selected, so `queuey:work mail --queue=Images` on a worker configured `only: [Emails]`
exits `2` naming the cases that worker is allowed to consume rather than quietly draining a queue
the deployment excluded.

`queuey:work` prints a header (worker, backend class, queues, handler count) unless `-q`, then one
line per job:

```
  2026-09-22 10:00:00 myapp::jobs::SendWelcomeMail ..... 12ms DONE
  2026-09-22 10:00:01 myapp::jobs::ResizeImage ......... 91ms FAIL (retry in 2s)
  2026-09-22 10:00:02 myapp::jobs::ResizeImage ........ 104ms DEAD max attempts (3) exhausted after attempt 3: convert: no decode delegate
```

The identifier is the **wire `job_type`** (`#[OnQueue(name:)]` and all), not the PHP class: it is
what every one of these events carries — a delivery dead-lettered before its payload decoded has
no class at all — and it is what the broker's management UI shows. The `DEAD` line repeats core's
own dead-letter reason verbatim, the same string that lands on `myapp.emails.dead`.

Those lines come from listeners the command adds for the duration of the run, so `events: false`
still gets you the output (the command turns the decorators on because the operator asked for it).
`--quiet-jobs` drops them.

Signals are core's: with `ext-pcntl` loaded the `Worker` installs its own `SIGTERM`/`SIGINT`
handlers and finishes the job in hand before exiting. The command deliberately does not use
`SignalableCommandInterface` and does not install handlers of its own.

### Supervisor

```ini
[program:queuey-default]
command=php /srv/app/bin/console queuey:work default --max-jobs=1000 --job-timeout=60s
directory=/srv/app
user=www-data
numprocs=4
process_name=%(program_name)s_%(process_num)02d
autostart=true
autorestart=true
startsecs=5
; Must be >= the job timeout, or Supervisor SIGKILLs a job that was about to finish.
stopwaitsecs=90
stopsignal=TERM
stopasgroup=true
killasgroup=true
redirect_stderr=true
stdout_logfile=/var/log/queuey-default.log
```

`--max-jobs=1000` makes each process exit cleanly after a thousand jobs and Supervisor start a
fresh one: the cheapest cure there is for a slow leak in third-party code. A clean exit is code 0,
which `autorestart=true` restarts anyway.

### systemd

```ini
# /etc/systemd/system/queuey@.service
[Unit]
Description=queuey worker (%i)
After=network.target

[Service]
Type=simple
User=www-data
WorkingDirectory=/srv/app
ExecStart=/usr/bin/php /srv/app/bin/console queuey:work default --max-jobs=1000 --job-timeout=60s
Restart=always
RestartSec=2
KillSignal=SIGTERM
# Must be >= the job timeout, or systemd SIGKILLs a job that was about to finish.
TimeoutStopSec=90

[Install]
WantedBy=multi-user.target
```

```bash
systemctl enable --now queuey@1 queuey@2 queuey@3 queuey@4
```

Both stanzas keep the stop grace period comfortably above `--job-timeout`. If you do not set a job
timeout, size it on your slowest job instead.

### Exit codes

| code | constant | when |
|---|---|---|
| `0` | `Command::SUCCESS` | clean shutdown: a signal, `--max-jobs`, `--for`, `--once` |
| `1` | `Command::FAILURE` | `ConsumerStopped` / `BackendException` — the broker is gone for good |
| `2` | `Command::INVALID` | configuration error (`InvalidConfiguration`, `UnknownWorker`, an unparseable duration), message on stderr |

`ConsumerStopped` is never swallowed: a supervisor needs the non-zero exit to restart the process.

## Events

Set `queuey.events: false` to switch the **per-job and dead-letter decorators** off (zero per-job
overhead): `JobProcessing`, `JobProcessed`, `JobFailed`, `JobDeferring` and `JobDeadLettered` stop
being dispatched. `WorkerStarting` and `WorkerStopped` are not decorator events — `queuey:work`
dispatches them itself, around the run, whatever `events` says. (`--quiet-jobs` aside, the command
also turns the decorators back on for its own per-job output, because the operator asked for it.)

With them on, listeners are ordinary Symfony listeners/subscribers dispatched by class name. A
listener that throws is logged at `error` and changes nothing: the job's own outcome always wins.

| event | properties | when |
|---|---|---|
| `Queuey\Symfony\Event\JobProcessing` | `Job $job`, `JobContext $context` | before the handler runs |
| `Queuey\Symfony\Event\JobProcessed` | `Job $job`, `JobContext $context`, `Duration $runtime` | the handler returned without throwing |
| `Queuey\Symfony\Event\JobFailed` | `Job $job`, `JobContext $context`, `Throwable $exception`, `bool $willRetry` | the handler threw; `$willRetry` is false for a `FatalFailure` or the last attempt |
| `Queuey\Symfony\Event\JobDeferring` | `Job $job`, `JobContext $context`, `JobDeferred $deferral` | the handler asked to be put back on hold |
| `Queuey\Symfony\Event\JobDeadLettered` | `DeadLetter $deadLetter` | the job was given up on (no handler, decode error, fatal, or attempts exhausted) |
| `Queuey\Symfony\Event\WorkerStarting` | `string $worker`, `list<string> $queues` | `queuey:work` is about to consume |
| `Queuey\Symfony\Event\WorkerStopped` | `string $worker`, `int $processed`, `int $settleFailures`, `?Throwable $cause` | the run ended, cleanly or not |

```php
use Queuey\Symfony\Event\JobFailed;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

final class JobMetrics
{
    #[AsEventListener]
    public function onFailure(JobFailed $event): void
    {
        $this->statsd->increment('jobs.failed', ['job' => $event->context->jobType]);
    }
}
```

Dead-letter *hooks* (`Queuey\DeadLetterHook`) are the other half of the story: they run inside the
worker's dead-letter path, before the message is written to `<queue>.dead`, and several hooks are
combined so one throwing hook cannot stop the job being dead-lettered.

## Testing

Point the `test` environment at the in-process backend and a frozen clock:

```yaml
# config/packages/queuey.yaml
when@test:
  queuey:
    backend: memory
    clock: frozen
```

The `memory` backend lives inside one PHP process: a producer in a request and a worker in
`bin/console` never meet. That is exactly what you want in a test and useless anywhere else.

```php
use App\Job\SendWelcomeMail;
use Queuey\Symfony\Test\QueueyAssertionsTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class SignupTest extends WebTestCase
{
    use QueueyAssertionsTrait;

    public function testItQueuesTheWelcomeMail(): void
    {
        $client = static::createClient();
        $client->request('POST', '/signup');

        $this->assertJobEnqueued(SendWelcomeMail::class, fn (SendWelcomeMail $job) => $job->to === 'ada@example.com');

        // One worker per call. `SendWelcomeMail` is on `AppQueues::Emails`, which
        // the config sample further up gives to the `mail` worker (`only: [Emails]`),
        // so that is the worker to drain.
        $this->queuey()->workAll('mail');   // run the real handlers, with real DI

        $this->assertNothingEnqueued();
        $this->assertNothingDeadLettered();
    }
}
```

The trait works in `KernelTestCase` and `WebTestCase` — everything goes through
`static::getContainer()`, and `$this->queuey()` is the documented way in: it fetches the public
service `queuey.test.fake` (aliased, also publicly, to `Queuey\Symfony\Test\QueueyFake`). Reach for
those ids directly only where the trait cannot be used. The kernel is rebooted per test, so every
test starts with an empty backend and a clock frozen at that moment.

**`work()` and `workAll()` drain one named worker**, the one you name, defaulting to `default`.
They are the real `Worker`, so they consume the queues *that* worker consumes and run *its*
handlers. With more than one worker configured, a test that asserts a queue is empty has to drain
each of them — `$this->queuey()->workAll()` **and** `$this->queuey()->workAll('mail')` — or call
the one that owns the queue under test. Draining a worker that has no handler for a job sitting on
a queue it consumes dead-letters that job, exactly as in production, and
`assertNothingDeadLettered()` is what catches it.

Trait methods: `queuey()`, `assertJobEnqueued()`, `assertJobEnqueuedTimes()`,
`assertJobNotEnqueued()`, `assertNothingEnqueued()`, `assertJobDelayed()`,
`assertJobDeadLettered()`, `assertNothingDeadLettered()`. Each one delegates to the fake, and the
failure message lists what *was* enqueued, with counts.

`$this->queuey()` is a `Queuey\Symfony\Test\QueueyFake`, registered as a **public** service
whenever `backend: memory` *and* PHPUnit is installed (it asserts through
`PHPUnit\Framework\Assert`, so a `dev` container on the memory backend without a test framework
gets no fake rather than a class-not-found). Public because nothing in an application injects it,
and a private service nothing references is pruned out of the compiled container:

| method | what it does |
|---|---|
| `backend(): MemoryBackend` | the in-process backend, for `pending()` / `held()` / `dead()` / counters |
| `clock(): FrozenClock` | the clock every producer and worker shares |
| `worker(string $name = 'default'): Worker` | the named worker, built on the fake backend |
| `travel(Duration\|string $by): void` | move the clock; holds are released by the next `work()` |
| `assertEnqueued($jobClass, ?callable $cb)` | published and not yet consumed — waiting **or** held |
| `assertEnqueuedTimes($jobClass, int $times)` | exactly this many |
| `assertNotEnqueued($jobClass, ?callable $cb)` | none, or none matching |
| `assertNothingEnqueued()` | nothing on any queue |
| `assertDelayed($jobClass, ?callable $cb)` | held: `enqueueAfter()`, `defer()`, or a retry whose backoff has not elapsed |
| `assertDeadLettered($jobClass, ?callable $cb)` | given up on; the callback gets `(Job\|null $job, Envelope $envelope, string $reason)` |
| `assertNothingDeadLettered()` | nothing was given up on |
| `enqueued($jobClass): list<Job>` / `delayed($jobClass): list<Job>` | the decoded jobs |
| `deadLettered($jobClass): list<array{envelope: Envelope, reason: string}>` | the dead letters with their reasons |
| `work(string $worker = 'default', ?int $maxJobs = null): int` | run that worker's handlers over what is pending now; returns jobs processed |
| `workAll(string $worker = 'default'): int` | release every hold as if its delay had elapsed, work, repeat until quiet |

The `assert*` callbacks receive the **decoded job** and the envelope, and jobs are matched by their
wire name, so `#[OnQueue(name: ...)]` is respected. `work()` and `workAll()` run the real `Worker`
over the real handlers the container built — retries, deferrals, dead letters and your dead-letter
hooks all behave as they would in production, only faster.

`QueueyFake` refuses to be built on anything but a `FrozenClock`, and refuses if the manager
publishes into a different backend than the one it reads; both throw `InvalidQueueyConfiguration`
naming the key that is wrong.

## Interoperability with Rust queuey

If PHP and Rust workers share queues, a handful of things must line up. Getting them wrong shows
up as `PRECONDITION_FAILED` on a declare, or as messages nobody consumes.

- **Name your jobs explicitly.** Rust's default `job_type` is `module_path!()::Type`
  (`myapp::jobs::SendEmail`); PHP's is the FQCN (`App\Job\SendEmail`). They will never coincide by
  accident, so set the name on at least one side and preferably both:

  ```php
  #[OnQueue(AppQueues::Emails, name: 'myapp::jobs::SendEmail')]
  ```
  ```rust
  #[job(queue = AppQueues::Emails, name = "myapp::jobs::SendEmail")]
  ```

  `name:` is also how a job whose wire name is a PHP reserved word (`Echo`, `List`, `Match`) gets a
  legal class name.

- **Both sides must agree on the topology.** Queue names, the prefix, `max_priority`, `durable`,
  `message_ttl` and both suffixes (`dead_suffix`, `deferred_suffix`), plus `retry_granularity` and
  `deferred_granularity`, have to match. Different values mean a mismatched declare
  (`PRECONDITION_FAILED`) or hold queues that strand messages.

- **Let one side declare.** If the Rust application declares the topology, set
  `declare_on_publish: false` here and either leave declaring to Rust or run
  `bin/console queuey:declare` yourself as a deploy step. `queuey:queues` prints exactly what this
  side thinks the topology is, which is the fastest way to compare the two.

- **Never define `AMQP_STRICT_FLD_TYPES`.** php-amqplib picks between two AMQP field-type tables
  with that global constant. Its default (the RabbitMQ table) is what core's queue arguments and
  headers are written against; defining it switches to the strict 0-9-1 table, where 64-bit
  integers go out as `L` instead of `l` and 16-bit ones as `U` instead of `s`. The hold-queue
  arguments a PHP process then declares stop matching the Rust ones, and the broker answers
  `PRECONDITION_FAILED` on whichever declare comes second. It is process-global, so one stray
  `define()` anywhere in the application is enough.

Core's own README has the full interop chapter, including payload shapes and the `u64` precision
trap.

## Not Messenger: differences from `messenger:consume`

| Messenger | queuey | why |
|---|---|---|
| `messenger:consume <transport>` | `queuey:work <worker>` | a worker is a named set of queues plus its handlers, not a transport |
| `--limit=N` | `--max-jobs=N` | same idea, core's spelling |
| `--time-limit=N` (seconds) | `--for=DURATION` (`90s`, `1h`) | core's duration grammar everywhere |
| `--memory-limit=128M` | *(not implemented)* | use `--max-jobs` and let the supervisor recycle the process |
| `--sleep=N` | *(not implemented)* | the backend blocks on the broker; there is no poll loop to slow down |
| `--bus=...` | *(no bus)* | jobs go straight to a handler |
| `--failure-limit=N` | *(not implemented)* | failure handling is the retry policy's job |
| retry strategy options / `framework.messenger.transports.*.retry_strategy` | `#[Retry]` on the job or queue, `retry_overrides` in config | retry timing is on the wire and must match the Rust side |
| `messenger:failed:show` / `:retry` / `:remove` | dead letters live on `<queue>.dead` | inspect and replay them with your broker's tools, or a `DeadLetterHook` |
| `#[AsMessageHandler]` | `JobHandler` interface, `#[AsJobHandler(worker:)]` | a handler declares its job class, and one worker owns it |
| stamps / `Envelope` middleware | `EnqueueOptions`, `#[OnQueue]`, events | the envelope's shape is fixed by the interop spec |
| `messenger:stats` | `queuey:queues` | topology and handler counts, from this side's point of view |

## Long-running runtimes

Under FrankenPHP worker mode, Swoole, or RoadRunner the container survives between requests, so the
`Backend` service does too: **one AMQP connection per worker process**, which is what you want.
Nothing needs to be reset between requests.

The bundle deliberately registers **no** `kernel.terminate` or shutdown hook. Closing the backend
after every request would tear down and rebuild the connection constantly; on CLI exit PHP closes
the socket anyway. If you really do want to close it (a long-lived CLI process that is finished
with the queue), call `$queuey->backend()->close()` yourself.

One caveat: the connection is shared by everything in the process. Do not hand a `Producer` from
one request to a coroutine belonging to another, and do not run a `Worker` inside a web worker
process — workers belong in `bin/console queuey:work`.

## Configuration reference

```yaml
queuey:
  backend: rabbitmq
  clock: system
  declare_on_publish: true
  events: true
  rabbitmq: { ... }
  workers: { ... }
```

### Root

| key | default | meaning |
|---|---|---|
| `backend` | `rabbitmq` | `rabbitmq`, or `memory` for tests and local experiments (one PHP process only) |
| `clock` | `system` | `system`, or `frozen` for tests. `frozen` with `backend: rabbitmq` is refused at first use, naming `queuey.clock` |
| `declare_on_publish` | `true` | whether a producer declares its queue set's topology on first use. `false` → `Producer::undeclared()`: the queues must already exist |
| `events` | `true` | dispatch the `Queuey\Symfony\Event\*` events. `false` is zero per-job overhead |

### `queuey.rabbitmq`

| key | default | meaning |
|---|---|---|
| `url` | `%env(default:queuey.default_amqp_url:QUEUEY_AMQP_URL)%` | AMQP URL. Query parameters (`heartbeat`, `connection_timeout`, `channel_max`) are honoured by core. The fallback parameter `queuey.default_amqp_url` is `amqp://guest:guest@localhost:5672/%%2f` — `%%` because `%` is special in DI parameters |
| `connection_name` | `null` | shown in the RabbitMQ management UI; `null` keeps core's default |
| `heartbeat` | `30` | seconds; `0` disables |
| `connection_timeout` | `10.0` | seconds |
| `read_write_timeout` | `60.0` | seconds; keep it at or above `2 * heartbeat` |
| `publish_confirm_timeout` | `30.0` | seconds to wait for a publisher confirm |
| `retry_granularity` | `1s` | rounding of retry hold TTLs. **Must match the Rust side** |
| `deferred_granularity` | `1s` | rounding of deferral hold TTLs. **Must match the Rust side** |
| `dead_suffix` | `.dead` | suffix of the dead-letter queue. **Must match the Rust side** |
| `deferred_suffix` | `.deferred` | suffix of the deferral hold queue. **Must match the Rust side** |
| `declare_dead_letter_queues` | `true` | declare `<queue><dead_suffix>` alongside each queue |
| `reconnect.enabled` | `true` | reconnect after a dropped connection |
| `reconnect.max_attempts` | `null` | `null` = unlimited |
| `reconnect.base` | `500ms` | first backoff |
| `reconnect.factor` | `2.0` | exponential factor |
| `reconnect.max` | `30s` | backoff ceiling |
| `reconnect.jitter` | `true` | randomise the backoff |

Durations use core's grammar: `500ms`, `1s`, `2m`, `1h`.

### `queuey.workers.<name>`

| key | default | meaning |
|---|---|---|
| `queues` | *required* | the `QueueSet` enum FQCN this worker consumes |
| `only` | `[]` | enum **case names** to consume; empty means every case |
| `handlers` | `[]` | service ids of `JobHandler` services; tags and attributes add more |
| `dead_letter_hooks` | `[]` | service ids of `DeadLetterHook` services; tags and attributes add more |
| `job_timeout` | `null` | duration string; needs `ext-pcntl` |
| `max_jobs` | `null` | exit cleanly after this many jobs (the supervisor restarts) |
| `retry_overrides.queues.<Case>` | `{}` | a retry policy for a whole queue, keyed by enum case name |
| `retry_overrides.jobs.<JobClass>` | `{}` | a retry policy for one job class |

A retry override takes core's `#[Retry]` keys in `snake_case`:

```yaml
retry_overrides:
  queues:
    Images: { max_attempts: 5, backoff: exponential, base: 1s, factor: 2.0, max: 5m, jitter: true }
  jobs:
    App\Job\SendWelcomeMail: { max_attempts: 3, backoff: fixed, delay: 2s }
    App\Job\Irreplaceable:   { max_attempts: 1, backoff: none }
```

Precedence, highest first (INTEROP-SPEC §5): a handler implementing `ProvidesRetryPolicy` →
`retry_overrides.jobs` → `retry_overrides.queues` → the job's own `#[OnQueue(retry:)]` → the
queue's `#[Queue(retry:)]` → `RetryPolicy::none()` (a single attempt).

### Logging

Every service that logs takes `service('logger')->nullOnInvalid()` and is tagged
`monolog.logger` with `channel: queuey`. With MonologBundle installed you get a `queuey` channel
for free:

```yaml
monolog:
  channels: ['queuey']
  handlers:
    queuey:
      type: stream
      path: '%kernel.logs_dir%/queuey.log'
      level: info
      channels: ['queuey']
```

Without a `logger` service, the bundle falls back to a `NullLogger` and everything still works.

## Releasing

Publish `queuey/queuey` first: this bundle requires `queuey/queuey ^1.0`, and the
release workflow refuses to publish while that is not installable from Packagist.

Releases are published by pushing a tag; `.github/workflows/release.yml` does the rest.

1. Move the `[Unreleased]` entries in `CHANGELOG.md` under a new heading
   `## [X.Y.Z] - YYYY-MM-DD` and commit that on `main`.
2. Tag the commit and push the tag:

   ```sh
   git tag vX.Y.Z
   git push origin vX.Y.Z
   ```

The workflow rejects a tag that is not `vX.Y.Z` or does not match the newest
`CHANGELOG.md` release, checks that `queuey/queuey` 1.x is on Packagist, runs the
full CI, creates a GitHub release with that `CHANGELOG.md` section as its notes and
asks Packagist to update `queuey/symfony`, registering it first if it is not there
yet. Those Packagist calls use the optional repository secrets `PACKAGIST_USERNAME`
and `PACKAGIST_TOKEN` and are skipped without them, which is fine when the package
was submitted on packagist.org with its GitHub integration: Packagist then picks up
new tags by itself. With the secrets, the first release needs the main API token,
which registers the package, and the safe token is enough after that.

## License

MIT. See [LICENSE](LICENSE).

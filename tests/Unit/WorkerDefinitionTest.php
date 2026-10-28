<?php

declare(strict_types=1);

use Queuey\BackoffKind;
use Queuey\Symfony\Exception\InvalidQueueyConfiguration;
use Queuey\Symfony\Tests\Fixtures\AppQueues;
use Queuey\Symfony\Tests\Fixtures\Jobs\SendMail;
use Queuey\Symfony\WorkerDefinition;

/**
 * @param array<string, mixed> $overrides
 *
 * @return array<string, mixed>
 */
function workerConfig(array $overrides = []): array
{
    return ['queues' => AppQueues::class] + $overrides;
}

it('reads a whole worker entry', function (): void {
    $definition = WorkerDefinition::fromConfig('default', workerConfig([
        'only' => ['Emails', 'Reports'],
        'handlers' => ['app.handler.one', 'app.handler.two'],
        'dead_letter_hooks' => ['app.hook'],
        'job_timeout' => '30s',
        'max_jobs' => 100,
    ]));

    expect($definition->name)->toBe('default')
        ->and($definition->queueSet)->toBe(AppQueues::class)
        ->and($definition->only)->toBe(['Emails', 'Reports'])
        ->and($definition->handlers)->toBe(['app.handler.one', 'app.handler.two'])
        ->and($definition->deadLetterHooks)->toBe(['app.hook'])
        ->and($definition->jobTimeout?->toMs())->toBe(30_000)
        ->and($definition->maxJobs)->toBe(100);
});

it('defaults everything but the queue set', function (): void {
    $definition = WorkerDefinition::fromConfig('default', workerConfig());

    expect($definition->only)->toBe([])
        ->and($definition->handlers)->toBe([])
        ->and($definition->deadLetterHooks)->toBe([])
        ->and($definition->jobTimeout)->toBeNull()
        ->and($definition->maxJobs)->toBeNull()
        ->and($definition->queueRetryOverrides)->toBe([])
        ->and($definition->jobRetryOverrides)->toBe([]);
});

it('turns retry overrides into policies', function (): void {
    $definition = WorkerDefinition::fromConfig('default', workerConfig([
        'retry_overrides' => [
            'queues' => [
                'Emails' => ['max_attempts' => 5, 'backoff' => 'exponential', 'base' => '1s', 'factor' => 2.0, 'max' => '5m', 'jitter' => true],
                'Reports' => ['max_attempts' => 3, 'backoff' => 'fixed', 'delay' => '2s'],
            ],
            'jobs' => [
                SendMail::class => ['max_attempts' => 1, 'backoff' => 'none'],
            ],
        ],
    ]));

    $emails = $definition->queueRetryOverrides['Emails'];
    expect($emails->maxAttempts)->toBe(5)
        ->and($emails->backoff->kind())->toBe(BackoffKind::Exponential)
        ->and($emails->backoff->base()->toMs())->toBe(1000)
        ->and($emails->backoff->max()->toMs())->toBe(300_000);

    $reports = $definition->queueRetryOverrides['Reports'];
    expect($reports->maxAttempts)->toBe(3)
        ->and($reports->backoff->kind())->toBe(BackoffKind::Fixed)
        ->and($reports->backoff->delay()->toMs())->toBe(2000);

    $job = $definition->jobRetryOverrides[SendMail::class];
    expect($job->maxAttempts)->toBe(1)
        ->and($job->backoff->kind())->toBe(BackoffKind::None);
});

it('names the override key that core rejected', function (): void {
    expect(fn() => WorkerDefinition::fromConfig('default', workerConfig([
        'retry_overrides' => ['queues' => ['Emails' => ['backoff' => 'fixed']]],
    ])))->toThrow(
        InvalidQueueyConfiguration::class,
        'queuey.workers.default.retry_overrides.queues.Emails',
    );
});

it('rejects an unknown override key', function (): void {
    expect(fn() => WorkerDefinition::fromConfig('default', workerConfig([
        'retry_overrides' => ['jobs' => [SendMail::class => ['attempts' => 3]]],
    ])))->toThrow(InvalidQueueyConfiguration::class, 'unknown key `attempts`');
});

it('refuses a class that is not a queue set', function (): void {
    expect(fn() => WorkerDefinition::fromConfig('default', ['queues' => SendMail::class]))
        ->toThrow(InvalidQueueyConfiguration::class, 'queuey.workers.default.queues: ' . SendMail::class . ' is not a');

    expect(fn() => WorkerDefinition::fromConfig('default', []))
        ->toThrow(InvalidQueueyConfiguration::class, 'a worker must name the QueueSet enum it consumes');
});

it('refuses a malformed job timeout and max jobs', function (): void {
    expect(fn() => WorkerDefinition::fromConfig('default', workerConfig(['job_timeout' => 'whenever'])))
        ->toThrow(InvalidQueueyConfiguration::class, 'queuey.workers.default.job_timeout');

    expect(fn() => WorkerDefinition::fromConfig('default', workerConfig(['max_jobs' => 0])))
        ->toThrow(InvalidQueueyConfiguration::class, 'queuey.workers.default.max_jobs');
});

it('selects queues by case name', function (): void {
    $definition = WorkerDefinition::fromConfig('default', workerConfig(['only' => ['Reports', 'Emails']]));

    expect($definition->selectedQueues())->toBe([AppQueues::Reports, AppQueues::Emails]);
    expect(WorkerDefinition::fromConfig('default', workerConfig())->selectedQueues())->toBe(AppQueues::all());
});

it('names queuey.workers.<name>.only for an unknown case', function (): void {
    $definition = WorkerDefinition::fromConfig('default', workerConfig(['only' => ['Nope']]));

    expect(fn() => $definition->selectedQueues())
        ->toThrow(InvalidQueueyConfiguration::class, 'queuey.workers.default.only: ' . AppQueues::class . ' has no case `Nope`');
});

it('appends through the withers without touching the original', function (): void {
    $definition = WorkerDefinition::fromConfig('default', workerConfig(['handlers' => ['a']]));
    $extended = $definition
        ->withHandlers('b', 'c')
        ->withDeadLetterHooks('hook')
        ->only('Emails')
        ->withJobTimeout('10s')
        ->withMaxJobs(3);

    expect($extended->handlers)->toBe(['a', 'b', 'c'])
        ->and($extended->deadLetterHooks)->toBe(['hook'])
        ->and($extended->only)->toBe(['Emails'])
        ->and($extended->jobTimeout?->toMs())->toBe(10_000)
        ->and($extended->maxJobs)->toBe(3)
        ->and($definition->handlers)->toBe(['a'])
        ->and($definition->maxJobs)->toBeNull();

    expect($extended->withJobTimeout(null)->jobTimeout)->toBeNull()
        ->and($extended->withMaxJobs(null)->maxJobs)->toBeNull();
});

<?php

declare(strict_types=1);

use Queuey\Envelope;
use Queuey\Memory\MemoryBackend;
use Queuey\QueueConfig;
use Queuey\Symfony\Event\JobDeadLettered;
use Queuey\Symfony\Event\JobDeferring;
use Queuey\Symfony\Event\JobFailed;
use Queuey\Symfony\Event\JobProcessed;
use Queuey\Symfony\Event\JobProcessing;
use Queuey\Symfony\Queuey;
use Queuey\Symfony\Tests\Fixtures\AppQueues;
use Queuey\Symfony\Tests\Fixtures\Jobs\DeferOnce;
use Queuey\Symfony\Tests\Fixtures\Jobs\Flaky;
use Queuey\Symfony\Tests\Fixtures\Jobs\SendMail;
use Queuey\Symfony\Tests\TestCase;
use Queuey\Worker;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Collect every queuey event, in dispatch order.
 *
 * @param list<object> $seen
 */
function listenForQueueyEvents(EventDispatcherInterface $dispatcher, array &$seen): void
{
    foreach ([JobProcessing::class, JobProcessed::class, JobFailed::class, JobDeferring::class, JobDeadLettered::class] as $event) {
        $dispatcher->addListener($event, static function (object $e) use (&$seen): void {
            $seen[] = $e;
        });
    }
}

function runOneRound(Worker $worker, MemoryBackend $backend): void
{
    $queues = array_map(static fn(QueueConfig $config): string => $config->name, $worker->queues());
    $round = 0;

    $worker->runUntil(static function () use ($backend, $queues, &$round): bool {
        if ($round++ === 0) {
            return false;
        }

        foreach ($queues as $queue) {
            if ($backend->pending($queue) !== []) {
                return false;
            }
        }

        return true;
    });
}

it('announces a successful job on the event dispatcher', function (): void {
    /** @var TestCase $this */
    $seen = [];
    listenForQueueyEvents($this->service(EventDispatcherInterface::class), $seen);

    $queuey = $this->service(Queuey::class);
    $queuey->enqueue(new SendMail('ada@example.com'));
    runOneRound($queuey->worker(), $this->service(MemoryBackend::class));

    expect($seen)->toHaveCount(2)
        ->and($seen[0])->toBeInstanceOf(JobProcessing::class)
        ->and($seen[1])->toBeInstanceOf(JobProcessed::class);

    $processing = $seen[0];
    \assert($processing instanceof JobProcessing);
    expect($processing->job)->toBeInstanceOf(SendMail::class)
        ->and($processing->context->queue)->toBe('queuey_symfony_test.emails')
        ->and($processing->context->attempt)->toBe(1);
});

it('announces a failure with the retry it expects', function (): void {
    /** @var TestCase $this */
    $seen = [];
    listenForQueueyEvents($this->service(EventDispatcherInterface::class), $seen);

    $queuey = $this->service(Queuey::class);
    $queuey->enqueue(new Flaky('deploy'));
    runOneRound($queuey->worker(), $this->service(MemoryBackend::class));

    expect($seen[1])->toBeInstanceOf(JobFailed::class);
    $failed = $seen[1];
    \assert($failed instanceof JobFailed);
    expect($failed->willRetry)->toBeTrue()
        ->and($failed->exception->getMessage())->toContain('not yet');
});

it('announces a deferral', function (): void {
    /** @var TestCase $this */
    $seen = [];
    listenForQueueyEvents($this->service(EventDispatcherInterface::class), $seen);

    $queuey = $this->service(Queuey::class);
    $queuey->enqueue(new DeferOnce('upstream'));
    runOneRound($queuey->worker(), $this->service(MemoryBackend::class));

    expect($seen[1])->toBeInstanceOf(JobDeferring::class);
    $deferring = $seen[1];
    \assert($deferring instanceof JobDeferring);
    expect($deferring->deferral->delay->toMs())->toBe(5_000);
});

it('announces a dead letter from the hook the factory added', function (): void {
    /** @var TestCase $this */
    $seen = [];
    listenForQueueyEvents($this->service(EventDispatcherInterface::class), $seen);

    $queuey = $this->service(Queuey::class);
    $backend = $this->service(MemoryBackend::class);
    $queuey->producer(AppQueues::class);
    $backend->publish(Envelope::raw('queuey_symfony_test.unknown', AppQueues::Emails->queueName(), (object) []));

    runOneRound($queuey->worker(), $backend);

    expect($seen)->toHaveCount(1)
        ->and($seen[0])->toBeInstanceOf(JobDeadLettered::class);

    $dead = $seen[0];
    \assert($dead instanceof JobDeadLettered);
    expect($dead->deadLetter->envelope->jobType)->toBe('queuey_symfony_test.unknown')
        ->and($dead->deadLetter->cause->name)->toBe('NoHandler');
});

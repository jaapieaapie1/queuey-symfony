<?php

declare(strict_types=1);

use Queuey\Duration;
use Queuey\EnqueueOptions;
use Queuey\Memory\MemoryBackend;
use Queuey\Symfony\Queuey;
use Queuey\Symfony\Tests\Fixtures\AppQueues;
use Queuey\Symfony\Tests\Fixtures\Jobs\BuildReport;
use Queuey\Symfony\Tests\Fixtures\Jobs\SendMail;
use Queuey\Symfony\Tests\TestCase;

it('publishes a job to the queue its attribute names', function (): void {
    /** @var TestCase $this */
    $queuey = $this->service(Queuey::class);
    $backend = $this->service(MemoryBackend::class);

    $id = $queuey->enqueue(new SendMail('ada@example.com'));

    $pending = $backend->pending(AppQueues::Emails->queueName());
    expect($pending)->toHaveCount(1)
        ->and($backend->pending(AppQueues::Reports->queueName()))->toBe([])
        ->and($pending[0]->jobId)->toBe($id)
        ->and($pending[0]->jobType)->toBe('queuey_symfony_test.send_mail')
        ->and($pending[0]->queue)->toBe('queuey_symfony_test.emails')
        ->and($pending[0]->attempt)->toBe(1)
        ->and($pending[0]->decode(SendMail::class))->toEqual(new SendMail('ada@example.com'));
});

it('declares the queue set on first publish', function (): void {
    /** @var TestCase $this */
    $this->service(Queuey::class)->enqueue(new BuildReport('q3'));

    expect($this->service(MemoryBackend::class)->declared())->toBe([
        'queuey_symfony_test.emails',
        'queuey_symfony_test.images',
        'queuey_symfony_test.reports',
    ]);
});

it('caches one producer per queue set and forgets it on flush', function (): void {
    /** @var TestCase $this */
    $queuey = $this->service(Queuey::class);
    $producer = $queuey->producer(AppQueues::class);

    expect($queuey->producer(AppQueues::class))->toBe($producer)
        ->and($producer->queueSet())->toBe(AppQueues::class);

    $queuey->flush();
    expect($queuey->producer(AppQueues::class))->not->toBe($producer);
});

it('holds a delayed job out of sight until the clock moves', function (): void {
    /** @var TestCase $this */
    $queuey = $this->service(Queuey::class);
    $backend = $this->service(MemoryBackend::class);

    $queuey->enqueueAfter(new SendMail('later@example.com'), '30s');
    $queuey->defer(new SendMail('front@example.com'), Duration::seconds(5));

    expect($backend->pending(AppQueues::Emails->queueName()))->toBe([])
        ->and($backend->held(AppQueues::Emails->queueName()))->toHaveCount(2);
});

it('carries a correlation id through EnqueueOptions', function (): void {
    /** @var TestCase $this */
    $queuey = $this->service(Queuey::class);

    $queuey->enqueue(new SendMail('a@b.c'), EnqueueOptions::now()->withCorrelationId('trace-1'));

    $pending = $this->service(MemoryBackend::class)->pending(AppQueues::Emails->queueName());
    expect($pending[0]->correlationId)->toBe('trace-1');
});

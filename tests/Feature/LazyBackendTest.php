<?php

declare(strict_types=1);

use Queuey\Backend;
use Queuey\Exception\BackendException;
use Queuey\Symfony\Queuey;
use Queuey\Symfony\Tests\Fixtures\AppQueues;
use Queuey\Symfony\Tests\Fixtures\EagerQueueyConsumer;
use Queuey\Symfony\Tests\Fixtures\Jobs\SendMail;
use Queuey\Symfony\Tests\TestCase;

/*
 * The `deadbroker` environment points the RabbitMQ backend at a closed port, so
 * anything that reaches the broker throws. Every service that can still be
 * built there is a service that does not connect while it is being built —
 * which is what `->lazy()` on the `Queuey\Backend` definition buys: the socket
 * opens on the first call *through* the proxy, not when it is injected.
 */

it('builds the manager without touching the broker', function (): void {
    /** @var TestCase $this */
    $this->bootedKernel('deadbroker');

    $queuey = $this->service(Queuey::class);

    expect($queuey)->toBeInstanceOf(Queuey::class);
});

it('builds an eager application service that injects the manager', function (): void {
    /** @var TestCase $this */
    $this->bootedKernel('deadbroker');

    // The smoke test's `bin/console list` crash: a plain, non-lazy service with
    // `Queuey` in its constructor. It has to come out of the container whole.
    $consumer = $this->service(EagerQueueyConsumer::class);

    expect($consumer->queuey->definition('default')->name)->toBe('default');
});

it('hands out a lazy proxy for the backend service', function (): void {
    /** @var TestCase $this */
    $this->bootedKernel('deadbroker');

    $backend = $this->container()->get(Backend::class);

    expect($backend)->toBeInstanceOf(Backend::class)
        // The proxy stands in for the real backend, which was never built.
        ->and($backend::class)->not->toBe(\Queuey\RabbitMq\RabbitMqBackend::class);
});

it('opens the socket only on the first backend call', function (): void {
    /** @var TestCase $this */
    $this->bootedKernel('deadbroker');

    $queuey = $this->service(Queuey::class);

    expect(static fn() => $queuey->backend()->declare(AppQueues::Emails->config()))
        ->toThrow(BackendException::class);
});

it('fails on enqueue, not on injection', function (): void {
    /** @var TestCase $this */
    $this->bootedKernel('deadbroker');

    $consumer = $this->service(EagerQueueyConsumer::class);

    expect(static fn() => $consumer->queuey->enqueue(new SendMail('ada@example.com')))
        ->toThrow(BackendException::class);
});

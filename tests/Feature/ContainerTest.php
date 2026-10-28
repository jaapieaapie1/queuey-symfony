<?php

declare(strict_types=1);

use Queuey\Backend;
use Queuey\Clock;
use Queuey\FrozenClock;
use Queuey\Memory\MemoryBackend;
use Queuey\Symfony\Queuey;
use Queuey\Symfony\Test\QueueyFake;
use Queuey\Symfony\Tests\TestCase;
use Queuey\Symfony\WorkerFactory;
use Queuey\Symfony\WorkerRegistry;

it('boots the kernel with the bundle registered', function (): void {
    /** @var TestCase $this */
    $kernel = $this->bootedKernel();

    expect($kernel->getBundles())->toHaveKey('QueueyBundle');
});

it('resolves the manager, autowired by type', function (): void {
    /** @var TestCase $this */
    $queuey = $this->service(Queuey::class);

    expect($queuey)->toBeInstanceOf(Queuey::class)
        ->and($queuey->workers())->toBeInstanceOf(WorkerRegistry::class)
        ->and($this->service(WorkerFactory::class))->toBeInstanceOf(WorkerFactory::class)
        ->and($this->container()->has('queuey'))->toBeTrue();
});

it('configures the memory backend and the frozen clock for the test environment', function (): void {
    /** @var TestCase $this */
    $backend = $this->service(Backend::class);

    expect($backend)->toBeInstanceOf(MemoryBackend::class)
        ->and($this->service(MemoryBackend::class))->toBe($backend)
        ->and($this->service(Backend::class))->toBe($backend)
        ->and($this->service(Clock::class))->toBeInstanceOf(FrozenClock::class)
        ->and($this->service(Queuey::class)->backend())->toBe($backend);
});

it('freezes the clock at the real current time, not the epoch', function (): void {
    /** @var TestCase $this */
    $clock = $this->service(FrozenClock::class);
    $before = $clock->nowMs();

    usleep(2_000);

    expect($clock->nowMs())->toBe($before)
        ->and($before)->toBeGreaterThan(1_700_000_000_000);
});

it('registers the test fake with the memory backend', function (): void {
    /** @var TestCase $this */
    $fake = $this->service(QueueyFake::class);

    expect($fake->backend())->toBe($this->service(MemoryBackend::class))
        ->and($fake->clock())->toBe($this->service(FrozenClock::class));
});

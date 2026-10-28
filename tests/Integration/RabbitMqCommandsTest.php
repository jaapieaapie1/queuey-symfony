<?php

declare(strict_types=1);

use Queuey\RabbitMq\RabbitMqBackend;
use Queuey\Symfony\Queuey;
use Queuey\Symfony\Tests\Fixtures\AppQueues;
use Queuey\Symfony\Tests\Fixtures\Console;
use Queuey\Symfony\Tests\Fixtures\Handlers\FlakyHandler;
use Queuey\Symfony\Tests\Fixtures\Jobs\Flaky;
use Queuey\Symfony\Tests\Fixtures\Jobs\SendMail;
use Queuey\Symfony\Tests\Fixtures\Recorder;
use Queuey\Symfony\Tests\Integration\IntegrationTestCase;
use Symfony\Component\Console\Command\Command;

/*
 * The console half of the bundle against a real broker: the topology
 * `queuey:declare` writes, a job published through the container, and
 * `queuey:work` draining it. Every run is bounded twice — by `--max-jobs` for
 * the outcome and by `--for` as a deadline — so a broker that never delivers
 * fails the test instead of hanging the suite.
 */

it('declares the fixture topology and then drains a published job', function (): void {
    /** @var IntegrationTestCase $this */
    $kernel = $this->bootedKernel();

    $declare = Console::tester($kernel, 'queuey:declare');
    expect($declare->execute([]))->toBe(Command::SUCCESS)
        // The `.dead` queues core declares beside them are counted in words,
        // not in the number: `Declared 3 queue(s)` alone undercounts by three.
        ->and($declare->getDisplay())->toContain('Declared 3 queue(s), plus a dead-letter queue (`.dead`) for each.')
        ->and($declare->getDisplay())->toContain(AppQueues::Emails->queueName());

    $this->service(Queuey::class)->enqueue(new SendMail('ada@example.com'));

    $work = Console::tester($kernel, 'queuey:work');
    $status = $work->execute(['--once' => true, '--for' => '30s']);

    expect($status)->toBe(Command::SUCCESS)
        ->and($work->getDisplay())->toContain(RabbitMqBackend::class)
        ->and($work->getDisplay())->toContain(AppQueues::Emails->queueName())
        ->and($work->getDisplay())->toContain('DONE')
        ->and($work->getDisplay())->toContain('Stopped after 1 job(s).')
        ->and($this->service(Recorder::class)->entries())->toBe(['mail:ada@example.com:attempt=1']);
});

it('retries a failing job through the hold queue until it succeeds', function (): void {
    /** @var IntegrationTestCase $this */
    $kernel = $this->bootedKernel();

    $this->service(Queuey::class)->enqueue(new Flaky('deploy'));

    // `AppQueues::Reports` allows 3 attempts with a fixed 2s backoff, so the two
    // retries go through `…reports.deferred.2000` and the whole run is ~4s.
    $work = Console::tester($kernel, 'queuey:work');
    $status = $work->execute(['--max-jobs' => (string) FlakyHandler::SUCCEEDS_ON, '--for' => '60s']);

    expect($status)->toBe(Command::SUCCESS)
        ->and($work->getDisplay())->toContain('FAIL (retry in 2s)')
        ->and($work->getDisplay())->toContain('DONE')
        ->and($this->service(Recorder::class)->entries())->toBe([
            'flaky:deploy:attempt=1',
            'flaky:deploy:attempt=2',
            'flaky:deploy:attempt=3',
        ]);
});

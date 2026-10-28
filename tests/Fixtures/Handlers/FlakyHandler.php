<?php

declare(strict_types=1);

namespace Queuey\Symfony\Tests\Fixtures\Handlers;

use Queuey\Exception\RetryableFailure;
use Queuey\Job;
use Queuey\JobContext;
use Queuey\JobHandler;
use Queuey\Symfony\Tests\Fixtures\Jobs\Flaky;
use Queuey\Symfony\Tests\Fixtures\Recorder;

/**
 * Fails until attempt {@see self::SUCCEEDS_ON}, so a test can drive a retry end
 * to end. The queue's own `#[Retry]` (3 attempts, fixed 2s) is what lets it get
 * there.
 *
 * @implements JobHandler<Flaky>
 */
final class FlakyHandler implements JobHandler
{
    public const SUCCEEDS_ON = 3;

    public function __construct(private readonly Recorder $recorder) {}

    public function jobClass(): string
    {
        return Flaky::class;
    }

    public function handle(Job $job, JobContext $ctx): void
    {
        \assert($job instanceof Flaky);

        $this->recorder->record('flaky:' . $job->label . ':attempt=' . $ctx->attempt);

        if ($ctx->attempt < self::SUCCEEDS_ON) {
            throw new RetryableFailure(\sprintf('not yet, attempt %d', $ctx->attempt));
        }
    }
}

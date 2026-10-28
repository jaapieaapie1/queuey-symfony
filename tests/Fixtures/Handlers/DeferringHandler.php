<?php

declare(strict_types=1);

namespace Queuey\Symfony\Tests\Fixtures\Handlers;

use Queuey\Duration;
use Queuey\Exception\JobDeferred;
use Queuey\Job;
use Queuey\JobContext;
use Queuey\JobHandler;
use Queuey\Symfony\Tests\Fixtures\Jobs\DeferOnce;
use Queuey\Symfony\Tests\Fixtures\Recorder;

/**
 * Defers once, then succeeds. A deferral is not a failure: the attempt counter
 * stays at 1 and the second run sees `deferrals = 1`.
 *
 * @implements JobHandler<DeferOnce>
 */
final class DeferringHandler implements JobHandler
{
    public const DELAY = '5s';

    public function __construct(private readonly Recorder $recorder) {}

    public function jobClass(): string
    {
        return DeferOnce::class;
    }

    public function handle(Job $job, JobContext $ctx): void
    {
        \assert($job instanceof DeferOnce);

        $this->recorder->record('defer:' . $job->label . ':deferrals=' . $ctx->deferrals);

        if ($ctx->deferrals === 0) {
            throw JobDeferred::for(Duration::parse(self::DELAY), 'waiting for the upstream');
        }
    }
}

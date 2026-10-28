<?php

declare(strict_types=1);

namespace Queuey\Symfony\Tests\Fixtures\Handlers;

use Queuey\Job;
use Queuey\JobContext;
use Queuey\JobHandler;
use Queuey\Symfony\Tests\Fixtures\Jobs\SendMail;
use Queuey\Symfony\Tests\Fixtures\Recorder;

/**
 * Handles {@see SendMail}, and takes a constructor dependency so a test can
 * prove the container built it.
 *
 * @implements JobHandler<SendMail>
 */
final class RecordingHandler implements JobHandler
{
    public function __construct(private readonly Recorder $recorder) {}

    public function jobClass(): string
    {
        return SendMail::class;
    }

    public function handle(Job $job, JobContext $ctx): void
    {
        \assert($job instanceof SendMail);

        $this->recorder->record('mail:' . $job->to . ':attempt=' . $ctx->attempt);
    }
}

<?php

declare(strict_types=1);

namespace Queuey\Symfony\Tests\Fixtures\Handlers;

use Queuey\Job;
use Queuey\JobContext;
use Queuey\JobHandler;
use Queuey\Symfony\Attribute\AsJobHandler;
use Queuey\Symfony\Tests\Fixtures\Jobs\Newsletter;
use Queuey\Symfony\Tests\Fixtures\Recorder;

/**
 * Targeted at the `mail` worker, so it must *not* also land on `default`.
 *
 * @implements JobHandler<Newsletter>
 */
#[AsJobHandler(worker: 'mail')]
final class MailWorkerHandler implements JobHandler
{
    public function __construct(private readonly Recorder $recorder) {}

    public function jobClass(): string
    {
        return Newsletter::class;
    }

    public function handle(Job $job, JobContext $ctx): void
    {
        \assert($job instanceof Newsletter);

        $this->recorder->record('newsletter:' . $job->subject);
    }
}

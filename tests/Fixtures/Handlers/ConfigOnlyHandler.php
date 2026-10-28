<?php

declare(strict_types=1);

namespace Queuey\Symfony\Tests\Fixtures\Handlers;

use Queuey\Job;
use Queuey\JobContext;
use Queuey\JobHandler;
use Queuey\Symfony\Tests\Fixtures\Jobs\ResizeImage;
use Queuey\Symfony\Tests\Fixtures\Recorder;

/**
 * Registered with `autoconfigure: false` in the test kernel and named in
 * `queuey.workers.default.handlers`, so the config path is exercised on its own.
 *
 * @implements JobHandler<ResizeImage>
 */
final class ConfigOnlyHandler implements JobHandler
{
    public function __construct(private readonly Recorder $recorder) {}

    public function jobClass(): string
    {
        return ResizeImage::class;
    }

    public function handle(Job $job, JobContext $ctx): void
    {
        \assert($job instanceof ResizeImage);

        $this->recorder->record('resize:' . $job->path);
    }
}

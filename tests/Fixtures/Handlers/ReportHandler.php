<?php

declare(strict_types=1);

namespace Queuey\Symfony\Tests\Fixtures\Handlers;

use Queuey\Duration;
use Queuey\Job;
use Queuey\JobContext;
use Queuey\JobHandler;
use Queuey\ProvidesRetryPolicy;
use Queuey\RetryPolicy;
use Queuey\Symfony\Tests\Fixtures\Jobs\BuildReport;
use Queuey\Symfony\Tests\Fixtures\Recorder;

/**
 * The handler with an opinion about retries: the highest-precedence source
 * there is (INTEROP-SPEC §5), and the one thing
 * {@see \Queuey\Symfony\Handler\EventDispatchingHandler} has to delegate.
 *
 * @implements JobHandler<BuildReport>
 * @implements ProvidesRetryPolicy<BuildReport>
 */
final class ReportHandler implements JobHandler, ProvidesRetryPolicy
{
    /** A report id that leaves the retry policy to the queue. */
    public const INHERIT = 'inherit';

    public function __construct(private readonly Recorder $recorder) {}

    public function jobClass(): string
    {
        return BuildReport::class;
    }

    public function retryPolicyFor(Job $job): ?RetryPolicy
    {
        \assert($job instanceof BuildReport);

        // `null` is "no opinion": the queue's own `#[Queue(retry: ...)]` applies.
        return $job->id === self::INHERIT ? null : RetryPolicy::fixed(7, Duration::seconds(9));
    }

    public function handle(Job $job, JobContext $ctx): void
    {
        \assert($job instanceof BuildReport);

        $this->recorder->record('report:' . $job->id . ':max=' . $ctx->maxAttempts);
    }
}

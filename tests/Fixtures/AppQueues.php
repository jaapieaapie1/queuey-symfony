<?php

declare(strict_types=1);

namespace Queuey\Symfony\Tests\Fixtures;

use Queuey\Attributes\Queue;
use Queuey\Attributes\Queues;
use Queuey\Attributes\Retry;
use Queuey\QueueSet;
use Queuey\QueueSetFromAttributes;

/**
 * The queue set every fixture job belongs to: `queuey_symfony_test.emails`,
 * `queuey_symfony_test.images`, `queuey_symfony_test.reports`.
 */
#[Queues(prefix: 'queuey_symfony_test')]
enum AppQueues implements QueueSet
{
    use QueueSetFromAttributes;

    #[Queue(prefetch: 4, maxPriority: 5)]
    case Emails;

    case Images;

    /** Carries a queue-level retry policy, so overrides have something to beat. */
    #[Queue(retry: new Retry(maxAttempts: 3, backoff: Retry::FIXED, delay: '2s'))]
    case Reports;
}

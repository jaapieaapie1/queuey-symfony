<?php

declare(strict_types=1);

namespace Queuey\Symfony\Tests\Fixtures\Jobs;

use Queuey\Attributes\OnQueue;
use Queuey\Job;
use Queuey\PayloadFromProperties;
use Queuey\Symfony\Tests\Fixtures\AppQueues;

#[OnQueue(AppQueues::Reports, name: 'queuey_symfony_test.build_report')]
final class BuildReport implements Job
{
    use PayloadFromProperties;

    public function __construct(public readonly string $id) {}
}

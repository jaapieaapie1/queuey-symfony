<?php

declare(strict_types=1);

namespace Queuey\Symfony\Tests\Fixtures;

use Queuey\Attributes\Queues;
use Queuey\QueueSet;
use Queuey\QueueSetFromAttributes;

/**
 * A second queue set, with no jobs and no handlers of its own.
 *
 * It exists so a test can build a worker whose set does not match the set the
 * fixture handlers publish to — the shape `queuey:queues` has to warn about
 * rather than crash on.
 */
#[Queues(prefix: 'queuey_symfony_other')]
enum OtherQueues implements QueueSet
{
    use QueueSetFromAttributes;

    case Misc;
}

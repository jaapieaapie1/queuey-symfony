<?php

declare(strict_types=1);

namespace Queuey\Symfony\Tests\Fixtures\Hooks;

use Queuey\DeadLetter;
use Queuey\DeadLetterHook;
use Queuey\Symfony\Tests\Fixtures\Recorder;

/** Autoconfigured onto the `default` worker by the `DeadLetterHook` interface. */
final class RecordingDeadLetterHook implements DeadLetterHook
{
    public function __construct(private readonly Recorder $recorder) {}

    public function onDeadLetter(DeadLetter $dead): void
    {
        $this->recorder->record('dead:' . $dead->envelope->jobType . ':' . $dead->cause->name);
    }
}

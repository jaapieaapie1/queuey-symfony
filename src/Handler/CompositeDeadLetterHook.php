<?php

declare(strict_types=1);

namespace Queuey\Symfony\Handler;

use Psr\Log\LoggerInterface;
use Queuey\DeadLetter;
use Queuey\DeadLetterHook;

/**
 * Runs several hooks for one dead letter, in registration order.
 *
 * Core's {@see \Queuey\WorkerBuilder::onDeadLetter()} takes exactly one hook, so
 * this is how a worker gets more than one. Every hook runs even when an earlier
 * one throws, and the exception is logged at `error` and swallowed: core's
 * `Worker` dead-letters the job after a throwing hook too (`Worker.php`, the
 * `deadLetter()` method — "the hook is a notification, not a veto"), and one
 * broken notifier must not cost the others theirs.
 */
final class CompositeDeadLetterHook implements DeadLetterHook
{
    /** @var list<DeadLetterHook> */
    private readonly array $hooks;

    public function __construct(
        private readonly LoggerInterface $logger,
        DeadLetterHook ...$hooks,
    ) {
        $this->hooks = array_values($hooks);
    }

    /** @return list<DeadLetterHook> */
    public function hooks(): array
    {
        return $this->hooks;
    }

    public function onDeadLetter(DeadLetter $dead): void
    {
        foreach ($this->hooks as $hook) {
            try {
                $hook->onDeadLetter($dead);
            } catch (\Throwable $e) {
                $this->logger->error('queuey dead-letter hook failed', [
                    'hook' => $hook::class,
                    'job_id' => $dead->envelope->jobId,
                    'job_type' => $dead->envelope->jobType,
                    'cause' => $dead->cause->name,
                    'error' => \sprintf('%s: %s', $e::class, $e->getMessage()),
                ]);
            }
        }
    }
}

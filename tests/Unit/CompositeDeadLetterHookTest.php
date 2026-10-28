<?php

declare(strict_types=1);

use Psr\Log\LogLevel;
use Queuey\DeadLetter;
use Queuey\DeadLetterCause;
use Queuey\Envelope;
use Queuey\FnDeadLetterHook;
use Queuey\FrozenClock;
use Queuey\Symfony\Handler\CompositeDeadLetterHook;
use Queuey\Symfony\Tests\Fixtures\Jobs\SendMail;
use Queuey\Symfony\Tests\Fixtures\RecordingLogger;

function deadLetter(): DeadLetter
{
    return new DeadLetter(
        Envelope::new(new SendMail('a@b.c'), new FrozenClock(0)),
        DeadLetterCause::Exhausted,
        'exhausted',
        3,
    );
}

it('runs every hook in registration order', function (): void {
    $calls = [];
    $composite = new CompositeDeadLetterHook(
        new RecordingLogger(),
        new FnDeadLetterHook(static function () use (&$calls): void {
            $calls[] = 'first';
        }),
        new FnDeadLetterHook(static function () use (&$calls): void {
            $calls[] = 'second';
        }),
    );

    $composite->onDeadLetter(deadLetter());

    expect($calls)->toBe(['first', 'second'])
        ->and($composite->hooks())->toHaveCount(2);
});

it('logs a throwing hook and carries on: a hook is a notification, not a veto', function (): void {
    $logger = new RecordingLogger();
    $ran = false;
    $composite = new CompositeDeadLetterHook(
        $logger,
        new FnDeadLetterHook(static function (): void {
            throw new \RuntimeException('hook exploded');
        }),
        new FnDeadLetterHook(static function () use (&$ran): void {
            $ran = true;
        }),
    );

    $composite->onDeadLetter(deadLetter());

    expect($ran)->toBeTrue()
        ->and($logger->messages(LogLevel::ERROR))->toBe(['queuey dead-letter hook failed'])
        ->and($logger->records[0]['context']['cause'])->toBe('Exhausted');
});

it('is happy with no hooks at all', function (): void {
    $composite = new CompositeDeadLetterHook(new RecordingLogger());

    $composite->onDeadLetter(deadLetter());

    expect($composite->hooks())->toBe([]);
});

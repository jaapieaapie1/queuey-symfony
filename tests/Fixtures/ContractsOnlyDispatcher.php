<?php

declare(strict_types=1);

namespace Queuey\Symfony\Tests\Fixtures;

use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * A dispatcher that implements the *contracts* interface and nothing more.
 *
 * `config/services.php` injects `event_dispatcher` with `nullOnInvalid()`, and
 * an application is free to bind that id to any implementation of the contract —
 * which has `dispatch()` and no way to register a listener at all.
 */
final class ContractsOnlyDispatcher implements EventDispatcherInterface
{
    /** @var list<object> */
    public array $dispatched = [];

    public function dispatch(object $event, ?string $eventName = null): object
    {
        $this->dispatched[] = $event;

        return $event;
    }
}

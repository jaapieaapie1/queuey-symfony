<?php

declare(strict_types=1);

namespace Queuey\Symfony\Internal;

use Queuey\Backend;
use Symfony\Component\VarExporter\LazyObjectInterface;

/**
 * Gets at the real backend behind the container's lazy proxy.
 *
 * The `Queuey\Backend` service is `lazy()`, so what anything injecting it holds
 * is a generated class implementing the interface — which is the whole point,
 * because building a service must not dial RabbitMQ. Two places nevertheless
 * want the object itself: the `queuey:work` header, which prints the backend
 * class, and `queuey:declare`, which asks a `RabbitMqBackend` about its
 * dead-letter options. Both do it at a moment where connecting is expected.
 *
 * `symfony/var-exporter` is not a declared dependency of this package because it
 * does not need to be: `symfony/dependency-injection`, which is, requires it on
 * every supported version, and that is the only component that produces these
 * proxies.
 *
 * @internal
 */
final class LazyBackend
{
    /**
     * The real backend, initializing the proxy if that has not happened yet.
     *
     * Calling this is what opens the connection, so callers do it inside
     * whatever already reports a `BackendException` readably.
     */
    public static function unwrap(Backend $backend): Backend
    {
        if (!$backend instanceof LazyObjectInterface) {
            return $backend;
        }

        $real = $backend->initializeLazyObject();
        \assert($real instanceof Backend);

        return $real;
    }
}

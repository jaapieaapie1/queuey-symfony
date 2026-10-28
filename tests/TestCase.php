<?php

declare(strict_types=1);

namespace Queuey\Symfony\Tests;

use Queuey\Symfony\Test\QueueyAssertionsTrait;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * The base case for every booted-kernel test.
 *
 * `KernelTestCase` reboots the kernel per test, so each one starts with an empty
 * memory backend and a clock frozen at that moment.
 */
abstract class TestCase extends KernelTestCase
{
    use QueueyAssertionsTrait;

    /**
     * Boot the fixture kernel and hand it back.
     *
     * `$environment` names the fixture app's environment — `deadbroker` is
     * RabbitMQ on a closed port; `null` leaves the case's own default in place
     * (`test` on the memory backend, `integration` on the broker).
     *
     * Public, like the two below: a Pest closure is bound to the test case but
     * PHPStan only knows that from the `@var TestCase $this` docblock, and it
     * refuses a protected call through it.
     */
    public function bootedKernel(?string $environment = null): KernelInterface
    {
        // Only when asked: `IntegrationTestCase::createKernel()` picks the
        // environment itself, and an explicit option would override it.
        return static::bootKernel($environment === null ? [] : ['environment' => $environment]);
    }

    /** The test container, which hands out the bundle's private services too. */
    public function container(): ContainerInterface
    {
        return static::getContainer();
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $id
     *
     * @return T
     */
    public function service(string $id): object
    {
        $service = $this->container()->get($id);
        \assert($service instanceof $id);

        return $service;
    }
}

<?php

declare(strict_types=1);

use Queuey\Symfony\Tests\Integration\IntegrationTestCase;
use Queuey\Symfony\Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case bindings
|--------------------------------------------------------------------------
|
| Feature and Integration tests run on a booted kernel, so they extend
| `TestCase` (a `KernelTestCase`) and reach the container through
| `static::getContainer()`, which hands out private services too. Unit tests
| need no kernel and stay on Pest's default case.
|
| `Integration` gets `IntegrationTestCase`, a `TestCase` that boots the same
| fixture kernel in the `integration` environment (RabbitMQ on `AMQP_URL`) and
| cleans the fixture queues off the broker around every test.
|
| Test bodies are closures, so PHPStan cannot see what `$this` is bound to.
| Every closure that reaches for a helper therefore opens with an inline
| `@var TestCase $this` docblock, which is what keeps `tests/` at level max.
|
*/

pest()->extend(TestCase::class)->in('Feature');
pest()->extend(IntegrationTestCase::class)->in('Integration');

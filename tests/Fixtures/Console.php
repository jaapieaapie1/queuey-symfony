<?php

declare(strict_types=1);

namespace Queuey\Symfony\Tests\Fixtures;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * Runs the bundle's commands the way `bin/console` does: through a real
 * `FrameworkBundle` application built on the booted test kernel, so the
 * `console.command` registration is exercised and not bypassed.
 */
final class Console
{
    public static function tester(KernelInterface $kernel, string $command): CommandTester
    {
        $application = new Application($kernel);
        $application->setAutoExit(false);
        // A command that throws should reach the test, not a rendered error page.
        $application->setCatchExceptions(false);

        return new CommandTester($application->find($command));
    }
}

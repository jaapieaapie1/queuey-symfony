<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

// `KernelTestCase` reads these; `phpunit.xml.dist` sets them too, but a run
// started straight from `pest` with another configuration still wants them.
$_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = $_SERVER['APP_ENV'] ?? 'test';
$_SERVER['APP_DEBUG'] = $_ENV['APP_DEBUG'] = $_SERVER['APP_DEBUG'] ?? '1';

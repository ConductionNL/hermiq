<?php

declare(strict_types=1);

// Define that we're running PHPUnit.
define('PHPUNIT_RUN', 1);

// Include Composer's autoloader and register the OCP namespace for standalone runs.
$autoloader = require __DIR__ . '/../vendor/autoload.php';
if (is_dir(__DIR__ . '/../vendor/nextcloud/ocp/OCP')) {
    $autoloader->addPsr4('OCP\\', __DIR__ . '/../vendor/nextcloud/ocp/OCP/');
    $autoloader->addPsr4('NCU\\', __DIR__ . '/../vendor/nextcloud/ocp/NCU/');
}

// Bootstrap Nextcloud when a full server environment is available. The include
// is wrapped in a try/catch so unit tests still run in standalone mode (e.g. a
// bare CI container without an installed Nextcloud).
if (file_exists(__DIR__ . '/../../../lib/base.php')) {
    try {
        require_once __DIR__ . '/../../../lib/base.php';
    } catch (\Throwable $e) {
        // Nextcloud not fully installed — unit tests continue with vendor stubs only.
    }
}

// Register Test\ namespace for NC test classes.
$serverTestsLib = __DIR__ . '/../../../tests/lib/';
if (is_dir($serverTestsLib)) {
    $loader = new \Composer\Autoload\ClassLoader();
    $loader->addPsr4('Test\\', $serverTestsLib);
    $loader->register(true);
}

// Load test stubs for cross-app classes that are only present when the other app
// is installed. The IMcpToolProvider stub stands in for openregister PR #1466
// (ai-chat-companion-orchestrator) so the example MCP tool provider can be
// unit-tested in standalone CI. AbstractToolHandler provides standardised auth
// helpers used by ExampleToolProvider. Both are also registered via autoload-dev
// PSR-4 in composer.json (OCA\OpenRegister\ -> tests/Stubs/) for non-bootstrapped runs.
if (class_exists(\OCA\OpenRegister\Mcp\AbstractToolHandler::class) === false) {
    require_once __DIR__ . '/Stubs/Mcp/AbstractToolHandler.php';
}

if (interface_exists(\OCA\OpenRegister\Mcp\IMcpToolProvider::class) === false) {
    require_once __DIR__ . '/Stubs/Mcp/IMcpToolProvider.php';
}

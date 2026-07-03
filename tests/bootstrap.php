<?php

declare(strict_types=1);

// Define that we're running PHPUnit.
define('PHPUNIT_RUN', 1);

// Include Composer's autoloader.
require_once __DIR__ . '/../vendor/autoload.php';

// Bootstrap Nextcloud if not already done.
if (!defined('OC_CONSOLE')) {
    if (file_exists(__DIR__ . '/../../../lib/base.php')) {
        require_once __DIR__ . '/../../../lib/base.php';
    }

    if (file_exists(__DIR__ . '/../../../tests/autoload.php')) {
        require_once __DIR__ . '/../../../tests/autoload.php';
    }

    \OC_App::loadApps();
    \OC_App::loadApp('hermiq');
    OC_Hook::clear();
}

// Load the IMcpToolProvider stub when the openregister runtime (PR #1466,
// ai-chat-companion-orchestrator) is absent. Also registered via autoload-dev
// PSR-4 in composer.json (OCA\OpenRegister\ -> tests/Stubs/).
if (interface_exists(\OCA\OpenRegister\Mcp\IMcpToolProvider::class) === false) {
    require_once __DIR__ . '/Stubs/Mcp/IMcpToolProvider.php';
}

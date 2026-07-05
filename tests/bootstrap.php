<?php

declare(strict_types=1);

// Define that we're running PHPUnit.
define('PHPUNIT_RUN', 1);

// Include Composer's autoloader.
require_once __DIR__ . '/../vendor/autoload.php';

// Bootstrap Nextcloud only when a server tree is present (e.g. running inside a
// full checkout). In CI / standalone unit runs there is no ../../../lib/base.php, so
// the OCP interfaces come from the composer stubs and unit tests mock every
// collaborator — no live server is required. Guard the OC_* calls with class_exists so
// the suite runs in either environment.
if (!defined('OC_CONSOLE') && file_exists(__DIR__ . '/../../../lib/base.php')) {
    require_once __DIR__ . '/../../../lib/base.php';

    if (file_exists(__DIR__ . '/../../../tests/autoload.php')) {
        require_once __DIR__ . '/../../../tests/autoload.php';
    }

    if (class_exists('\OC_App')) {
        \OC_App::loadApps();
        \OC_App::loadApp('hermiq');
    }

    if (class_exists('\OC_Hook')) {
        \OC_Hook::clear();
    }
}

// Load the IMcpToolProvider stub when the openregister runtime (PR #1466,
// ai-chat-companion-orchestrator) is absent. Also registered via autoload-dev
// PSR-4 in composer.json (OCA\OpenRegister\ -> tests/Stubs/).
if (interface_exists(\OCA\OpenRegister\Mcp\IMcpToolProvider::class) === false) {
    require_once __DIR__ . '/Stubs/Mcp/IMcpToolProvider.php';
}

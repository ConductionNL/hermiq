<?php

declare(strict_types=1);

// Define that we're running PHPUnit.
define('PHPUNIT_RUN', 1);

// Include Composer's autoloader.
require_once __DIR__ . '/../vendor/autoload.php';

// Register the nextcloud/ocp stubs for OCP\* — but ONLY here, in the test entry
// point, and only when no live Nextcloud already supplies them.
//
// This mapping must NEVER live in composer.json. `autoload-dev` IS baked into the
// generated autoloader by a plain `composer install`, and in the dev topology the
// app checkout IS the served app — Application.php requires vendor/autoload.php, so
// the stubs would shadow core's OCP on every request. With stubs pinned to a
// different Nextcloud major than the running server, core's `#[\Override]`
// attributes then have no matching parent method and PHP raises a COMPILE-TIME
// fatal that takes down the WHOLE instance (occ dead, 0 apps, every route 404/500).
// That is the 2026-07-12 outage. Static analysis does not need the mapping either:
// PHPStan reads the stubs via `scanDirectories`, Psalm via `<extraFiles>`.
if (interface_exists(\OCP\IUser::class) === false && is_dir(__DIR__ . '/../vendor/nextcloud/ocp/OCP') === true) {
    $ocpLoader = new \Composer\Autoload\ClassLoader();
    $ocpLoader->addPsr4('OCP\\', __DIR__ . '/../vendor/nextcloud/ocp/OCP/');
    $ocpLoader->register();
}

// Bootstrap Nextcloud only when a server tree is present (e.g. running inside a
// full checkout). In CI / standalone unit runs there is no ../../../lib/base.php, so
// the OCP interfaces come from the stubs registered above and unit tests mock every
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

// Register the OpenRegister, Talk and OC\Hooks stubs — but ONLY here in the test
// entry point, and only when the real apps/server do not already supply them.
//
// These mappings MUST NEVER live in composer.json. `autoload-dev` is baked into the
// generated autoloader by a plain `composer install`, and in this dev topology the
// app checkout IS the served app — Application.php requires vendor/autoload.php, so
// the stubs would shadow the real classes on EVERY request. A
// `tests/Stubs/Db/OrganisationMapper.php` fake then shadows the real OpenRegister
// OrganisationMapper for the whole instance, and OpenRegister's ConfigurationService
// fatals with "Call to undefined method OrganisationMapper::getDefaultOrganisationFromConfig()"
// — silently breaking the register import of EVERY app on the instance (the
// 2026-07-18 hrmq/doriath/decidesk outage). Static analysis does not need the
// mapping either: PHPStan ignores unknown `OCA\OpenRegister\`/`OC\` classes and Psalm
// suppresses them via <referencedClass>.
if (interface_exists(\OCA\OpenRegister\Mcp\IMcpToolProvider::class) === false) {
    $openRegisterStubLoader = new \Composer\Autoload\ClassLoader();
    $openRegisterStubLoader->addPsr4('OCA\\OpenRegister\\', __DIR__ . '/Stubs/');
    $openRegisterStubLoader->register();
}

if (class_exists(\OCA\Talk\Room::class) === false) {
    $talkStubLoader = new \Composer\Autoload\ClassLoader();
    $talkStubLoader->addPsr4('OCA\\Talk\\', __DIR__ . '/Stubs/Talk/');
    $talkStubLoader->register();
}

if (interface_exists(\OC\Hooks\Emitter::class) === false) {
    require_once __DIR__ . '/Stubs/OC/Hooks/Emitter.php';
}

// Load minimal Doctrine\DBAL stubs when doctrine/dbal is absent (standalone
// CI: php:8.3-cli + OCP stubs). The OCP IQueryBuilder stub initialises its
// PARAM_* class constants from Doctrine constants at class-load, so mocking
// OCP\IDBConnection (agent-engine-port ChatStreamController tests) fatals
// without them. The real classes ship with the Nextcloud server at runtime.
if (class_exists(\Doctrine\DBAL\ParameterType::class) === false) {
    require_once __DIR__ . '/Stubs/Doctrine/ParameterType.php';
    require_once __DIR__ . '/Stubs/Doctrine/ArrayParameterType.php';
    require_once __DIR__ . '/Stubs/Doctrine/Types.php';
}

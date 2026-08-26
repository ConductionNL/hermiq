<?php

declare(strict_types=1);

// Define that we're running PHPUnit.
define('PHPUNIT_RUN', 1);

// Include Composer's autoloader.
$autoloader = require __DIR__ . '/../vendor/autoload.php';

// Register the cross-app stub namespaces at TEST TIME only (openregister#2036 /
// hermiq#21). These mappings MUST NOT live in composer.json `autoload-dev`: a plain
// `composer install` bakes autoload-dev into the generated classmap, and in the dev
// topology the app checkout IS the served app (Application.php requires
// vendor/autoload.php), so the stubs would shadow the REAL OpenRegister/Talk classes
// on every request instance-wide → 500s everywhere. Loading here is lazy, so ordering
// versus any live server class is irrelevant — the stub is only ever resolved when the
// real class is absent (standalone CI).
$autoloader->addPsr4('OCA\\OpenRegister\\', __DIR__ . '/Stubs/');
$autoloader->addPsr4('OCA\\Talk\\', __DIR__ . '/Stubs/Talk/');

// ── The CAPABILITY grammar is loaded from OpenRegister's REAL SOURCE, never a stub.
//
// ADR-099 §5 moved `ToolGrantSet`, `ToolGrantCodec`, `ToolGrantResolver`,
// `ToolReachResolver` and `ToolGrantResolutionException` out of
// `OCA\Hermiq\Service\Engine` and into `OCA\OpenRegister\Service\Capability`.
// Fifteen hermiq test classes exercise them, and most do so for their BEHAVIOUR
// — the grant grammar, the argument-constraint parser, the waiver matcher.
//
// 🔴 THERE IS DELIBERATELY NO STUB FOR THIS NAMESPACE, and there must never be
// one. A stub of a value class is a second copy of the rules, and this repo has
// already paid for that: `tests/Stubs/Service/Mcp/ToolRegistryFacade.php` drifted
// from the real facade by one method (`describeTools()`), and every matrix cell
// in which OpenRegister failed to enable errored on the missing method instead
// of exercising the controller. That was a 3-method facade. This is ~2,400 lines
// of security-relevant parsing whose codec carries a measured scar — 35 of 87
// tools parsed wrong — so a stubbed copy would not merely drift, it would make
// fifteen test classes validate a fake while reporting green.
//
// A LONGER PSR-4 PREFIX WINS over the blanket stub mapping above regardless of
// registration order, so this cannot be defeated by someone reordering the file.
//
// WHERE THE SOURCE IS. Both topologies put OpenRegister beside hermiq:
//   * CI  — the quality workflow checks the app out at `server/apps/<name>` and
//           additional apps at `server/apps/openregister`, so `../openregister`.
//   * dev — `apps-extra/hermiq` next to `apps-extra/openregister`, same relative
//           path.
// `HERMIQ_OPENREGISTER_PATH` overrides both for an unusual layout.
//
// If none resolves, NOTHING is registered. The class is then genuinely absent
// and the affected tests ERROR loudly, which is the correct outcome: an absent
// dependency must not be able to masquerade as a passing suite.
$capabilityRoots = array_filter(
	[
		getenv('HERMIQ_OPENREGISTER_PATH') ?: null,
		__DIR__ . '/../../openregister',
	]
);
foreach ($capabilityRoots as $capabilityRoot) {
	$capabilityDir = rtrim((string)$capabilityRoot, '/') . '/lib/Service/Capability';
	if (is_dir($capabilityDir) === true) {
		$autoloader->addPsr4('OCA\\OpenRegister\\Service\\Capability\\', $capabilityDir);
		break;
	}
}

// OCP\Files\IRootFolder extends the private OC\Hooks\Emitter interface, absent from the
// nextcloud/ocp stubs. Register it lazily so standalone runs can mock IRootFolder; the
// real interface ships with the Nextcloud server. (Formerly an autoload-dev classmap.)
if (interface_exists(\OC\Hooks\Emitter::class) === false) {
	$autoloader->addClassMap(['OC\\Hooks\\Emitter' => __DIR__ . '/Stubs/OC/Hooks/Emitter.php']);
}

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

// Load the IMcpToolProvider stub when the openregister runtime (PR #1466,
// ai-chat-companion-orchestrator) is absent. Also registered via autoload-dev
// PSR-4 in composer.json (OCA\OpenRegister\ -> tests/Stubs/).
if (interface_exists(\OCA\OpenRegister\Mcp\IMcpToolProvider::class) === false) {
	require_once __DIR__ . '/Stubs/Mcp/IMcpToolProvider.php';
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

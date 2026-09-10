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

// ── OpenRegister's PUBLISHED CONTRACTS, loaded from real source for the same
// reason and by the same mechanism as the capability grammar above: a longer
// PSR-4 prefix beats the blanket stub mapping, so these never come from a stub.
//
// `RegisterSlugResolverInterface` and its return type `RegisterSlugResolution`
// are what tells "this register is not on this instance" from "this register
// holds nothing", and a stubbed copy of a type whose whole job is to make an
// absence unmistakable would be a second copy of that rule, kept here by someone
// who does not own it. There is no stub, and there must not be one.
//
// TWO SOURCES, ONE DEFINITION. Gate 67 (`openregister-contract-parity`) requires
// openregister's `lib/Contract/` and the hydra-gates package's
// `hydra-gates/contracts/` to be byte identical, so either yields the same type.
// Both are listed because they become available at different times: the package
// copy ships on a TAG, openregister's own tree is what CI checks out beside this
// app and what a dev checkout has next to it. That difference is not
// hypothetical. Measured here at v1.17.0, the vendored directory holds
// ObjectEntityInterface, ObjectServiceInterface and fleet-schema-slugs.json and
// nothing else, because the resolver contract was published to the package by
// ConductionNL/.github#739, which merged AFTER v1.17.0 was cut. Bumping the
// constraint alone does not make it loadable.
//
// PSR-4 with several directories searches them in order, so nothing is required
// eagerly and no duplicate declaration is possible.
$hermiqContractDirs = array_values(
	array_filter(
		array_merge(
			array_map(
				static fn (string $root): string => rtrim($root, '/') . '/lib/Contract',
				array_map('strval', $capabilityRoots)
			),
			[__DIR__ . '/../vendor/conduction/hydra-gates/hydra-gates/contracts']
		),
		'is_dir'
	)
);
if ($hermiqContractDirs !== []) {
	$autoloader->addPsr4('OCA\\OpenRegister\\Contract\\', $hermiqContractDirs);
}

// This app's OWN test-support classes (doubles that are not themselves tests, so
// PHPUnit never loads them by file). Registered here rather than in composer.json
// `autoload-dev` to keep every test-time mapping in one place, per the warning
// above. Unlike the mappings above this one carries no shadowing risk whatsoever:
// nothing under lib/ names `OCA\Hermiq\Tests\`, and no other app declares it.
$autoloader->addPsr4('OCA\\Hermiq\\Tests\\', __DIR__ . '/');

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

/**
 * Tell whether a Nextcloud root is an INSTALLED instance, not just a source tree.
 *
 * `lib/base.php` from a source tree that was never installed still declares
 * `OC` and builds `\OC::$server` before it throws "Not installed". That server
 * cannot be undone (`OC::$server` is a typed static), so from then on every
 * `\OC::$server->get()` in the code under test hits a container that knows
 * none of this app's registrations and autowires from scratch; constructor
 * cycles then recurse until memory runs out (19 GB on one openregister test,
 * 2026-09-08). So the decision has to be made BEFORE base.php is loaded, and
 * the only cheap signal is the `installed` flag in config/config.php.
 *
 * @param string $ncRoot Candidate Nextcloud root.
 *
 * @return bool True when config/config.php declares `installed => true`.
 */
function hermiq_nc_root_is_installed(string $ncRoot): bool
{
	$configFile = $ncRoot . '/config/config.php';
	if (is_file($configFile) === false || filesize($configFile) === 0) {
		return false;
	}

	// The config file is a plain `$CONFIG = [...]` script; including it in a
	// closure keeps `$CONFIG` out of the global scope.
	$config = (static function () use ($configFile): array {
		$CONFIG = [];
		try {
			include $configFile;
		} catch (\Throwable) {
			return [];
		}

		if (is_array($CONFIG) === false) {
			return [];
		}

		return $CONFIG;
	})();

	return ($config['installed'] ?? false) === true;
}

// The Nextcloud root this checkout sits under (apps-extra/hermiq/), or null
// when there is none or it is only a bare source tree. Decided ONCE, up here,
// so that lib/base.php is never loaded from a tree that cannot finish booting.
$hermiqNcRoot = null;
$hermiqNcCandidate = dirname(__DIR__, 3);
if (is_file($hermiqNcCandidate . '/lib/base.php') === true) {
	if (hermiq_nc_root_is_installed($hermiqNcCandidate) === true) {
		$hermiqNcRoot = $hermiqNcCandidate;
	} else {
		fwrite(
			STDERR,
			sprintf(
				"[hermiq/tests/bootstrap] Nextcloud tree at %s is not installed (config/config.php lacks installed => true); "
				. "skipping lib/base.php and running in pure-unit mode.\n",
				$hermiqNcCandidate
			)
		);
	}
}

// Bootstrap Nextcloud only when an INSTALLED server tree is present (e.g.
// running inside a full checkout). In CI / standalone unit runs there is no
// usable ../../../lib/base.php, so the OCP interfaces come from the stubs
// registered above and unit tests mock every collaborator. Guard the OC_* calls
// with class_exists so the suite runs in either environment.
if (!defined('OC_CONSOLE') && $hermiqNcRoot !== null) {
	try {
		require_once $hermiqNcRoot . '/lib/base.php';

		// NC's own tests/autoload.php starts with `require_once ../lib/base.php`,
		// so it is only safe once base.php itself has succeeded.
		if (file_exists($hermiqNcRoot . '/tests/autoload.php')) {
			require_once $hermiqNcRoot . '/tests/autoload.php';
		}

		if (class_exists('\OC_App')) {
			\OC_App::loadApps();
			\OC_App::loadApp('hermiq');
		}

		if (class_exists('\OC_Hook')) {
			\OC_Hook::clear();
		}
	} catch (\Throwable $e) {
		// The tree IS installed, so the dangerous case this guard exists for
		// (loading a bare source tree) did not happen. base.php still failed
		// part-way.
		//
		// This does NOT abort. `OC::$server` is a typed static, so a half-built
		// container cannot be unset, and aborting was tried: it turned all six
		// PHPUnit legs red on a suite that passes (humaniq, 2026-09-08). The
		// runaway this guard exists for needs an autowiring lookup to reach the
		// poisoned container, this app has none in lib, and phpunit.xml's 2G cap
		// bounds one anyway.
		//
		// So: say plainly that the container is unreliable, and let the pure unit
		// tests run. A container-bound test failing loudly is the intended outcome.
		fwrite(
			STDERR,
			sprintf(
				"[hermiq/tests/bootstrap] Nextcloud at %s could not finish booting (%s).\n"
				. "  \\OC::\$server now holds a HALF-BUILT container and cannot be unset. Pure unit tests\n"
				. "  continue; anything resolving a service from that container is UNVERIFIED by this run.\n",
				$hermiqNcRoot,
				$e->getMessage()
			)
		);
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

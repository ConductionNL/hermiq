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

// Register the cross-app stub namespaces at TEST TIME only (openregister#2036 /
// hermiq#21). These mappings MUST NOT live in composer.json `autoload-dev`: a plain
// `composer install` bakes autoload-dev into the generated classmap, and in the dev
// topology the app checkout IS the served app (Application.php requires
// vendor/autoload.php), so the stubs would shadow the REAL OpenRegister/Talk classes
// on every request instance-wide → 500s everywhere. Loading here is lazy, so the stub
// is only ever resolved when the real class is absent (standalone CI).
$autoloader->addPsr4('OCA\\OpenRegister\\', __DIR__ . '/Stubs/');
$autoloader->addPsr4('OCA\\Talk\\', __DIR__ . '/Stubs/Talk/');

// OCP\Files\IRootFolder extends the private OC\Hooks\Emitter interface, absent from the
// nextcloud/ocp stubs. Register it lazily so standalone runs can mock IRootFolder; the
// real interface ships with the Nextcloud server. (Formerly an autoload-dev classmap.)
if (interface_exists(\OC\Hooks\Emitter::class) === false) {
	$autoloader->addClassMap(['OC\\Hooks\\Emitter' => __DIR__ . '/Stubs/OC/Hooks/Emitter.php']);
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
				"[hermiq/tests/bootstrap-unit] Nextcloud tree at %s is not installed (config/config.php lacks installed => true); "
				. "skipping lib/base.php and running in pure-unit mode.\n",
				$hermiqNcCandidate
			)
		);
	}
}

// Bootstrap Nextcloud only when an INSTALLED instance is present. The old
// version caught whatever base.php threw and carried on "in standalone mode",
// which is exactly the half-booted state the helper above exists to prevent.
if ($hermiqNcRoot !== null) {
	try {
		require_once $hermiqNcRoot . '/lib/base.php';
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
				"[hermiq/tests/bootstrap-unit] Nextcloud at %s could not finish booting (%s).\n"
				. "  \\OC::\$server now holds a HALF-BUILT container and cannot be unset. Pure unit tests\n"
				. "  continue; anything resolving a service from that container is UNVERIFIED by this run.\n",
				$hermiqNcRoot,
				$e->getMessage()
			)
		);
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
// (ai-chat-companion-orchestrator) so Hermiq's MCP tool provider can be
// unit-tested in standalone CI. AbstractToolHandler provides standardised auth
// helpers used by HermiqToolProvider. Both are also registered via autoload-dev
// PSR-4 in composer.json (OCA\OpenRegister\ -> tests/Stubs/) for non-bootstrapped runs.
if (class_exists(\OCA\OpenRegister\Mcp\AbstractToolHandler::class) === false) {
	require_once __DIR__ . '/Stubs/Mcp/AbstractToolHandler.php';
}

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

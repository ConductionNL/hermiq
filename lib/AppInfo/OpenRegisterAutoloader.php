<?php

/**
 * Hermiq OpenRegister autoload prelude
 *
 * Puts OpenRegister's PSR-4 prefix on the autoloader before
 * `Application::register()` probes for any `OCA\OpenRegister\…` class.
 *
 * @category AppInfo
 * @package  OCA\Hermiq\AppInfo
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Hermiq\AppInfo;

/**
 * Registers OpenRegister's autoload prefix before its classes are referenced.
 *
 * ## Why this is needed (ADR-040)
 *
 * `OC_App::getEnabledApps()` does `sort($apps)`, and
 * `Coordinator::registerApps()` walks THAT sorted list calling
 * `OC_App::registerAutoloading($appId, $path)` and then `$app->register()` for
 * one app at a time — a single loop, not two passes. So every app's
 * `register()` runs BEFORE the PSR-4 prefix of every alphabetically-LATER app
 * exists.
 *
 * `hermiq` sorts before `openregister`, so `OCA\OpenRegister\` is NOT
 * autoloadable inside `Application::register()` on a perfectly healthy instance
 * with OpenRegister enabled. The three `class_exists()` guards there then answer
 * FALSE — and a FALSE is indistinguishable from OpenRegister being absent — so
 * the flow-node, agent-leaf and shareable-config plumbing is silently skipped
 * and the app still looks fine.
 *
 * ⚠️ Measured on the shared dev instance 2026-08-16, and the result is why this
 * class exists rather than a comment: all three listeners WERE registered, but
 * only because `doriath` — which sorts before `hermiq` and ships this same
 * prelude — had already put the prefix on the autoloader. Dumping
 * `OC_App::$alreadyRegistered` in registration order showed `doriath` #22,
 * `openregister` #23 (out of alphabetical position, i.e. forced by doriath),
 * `hermiq` #37. **Hermiq's guards were answering TRUE by accident of another
 * app being installed.** On an instance without doriath they answer FALSE.
 *
 * Lives in its own class rather than inline in `Application::register()` for one
 * reason: `Application` cannot be constructed without a Nextcloud DI container,
 * so an inline prelude is unreachable from a unit test. Here the degraded-path
 * contract — "this NEVER throws, whatever the instance looks like" — is directly
 * assertable, and it is asserted.
 *
 * @spec openspec/changes/hermiq-agent-leaf/specs/agent-object-leaf/spec.md#requirement-agent-integration-leaf-registration
 */
final class OpenRegisterAutoloader {

	/**
	 * The app whose autoload prefix this prelude registers.
	 */
	private const OPENREGISTER_APP_ID = 'openregister';

	/**
	 * Register OpenRegister's PSR-4 prefix on the composer autoloader.
	 *
	 * MUST be called before any `OCA\OpenRegister\…` reference in
	 * `Application::register()`, including a `class_exists()` probe.
	 *
	 * `OC_App::registerAutoloading()` touches only the autoloader and is
	 * idempotent: it early-returns on an `$alreadyRegistered` key, so calling
	 * this more than once is free.
	 *
	 * Deliberately NOT `IAppManager::loadApp('openregister')`: that marks
	 * OpenRegister loaded and calls `Coordinator::bootApp()`, booting it before
	 * its own `register()` has run.
	 *
	 * @return bool True when the prefix is registered, false when OpenRegister
	 *              is absent, disabled, or otherwise unresolvable — in which
	 *              case the caller MUST fall through to its degraded path.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) OC_App is Nextcloud's legacy
	 * bootstrap class. There is no OCP interface for registering another app's
	 * autoloader, and this runs at the composition root where no container is
	 * available to resolve an adapter from.
	 *
	 * @spec openspec/changes/hermiq-agent-leaf/specs/agent-object-leaf/spec.md#requirement-agent-integration-leaf-registration
	 */
	public static function register(): bool {
		try {
			$appManager = \OCP\Server::get(\OCP\App\IAppManager::class);
			$path = $appManager->getAppPath(self::OPENREGISTER_APP_ID);
			\OC_App::registerAutoloading(self::OPENREGISTER_APP_ID, $path);
			return true;
		} catch (\Throwable) {
			// OpenRegister absent, disabled, or the server container is not up
			// (unit tests). The caller's class_exists() guard then skips the
			// OpenRegister plumbing. Never rethrow: an exception escaping here
			// would abort the caller's entire register(), which is the exact
			// defect this prelude exists to prevent.
			return false;
		}

	}//end register()
}//end class

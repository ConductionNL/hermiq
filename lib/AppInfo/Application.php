<?php

/**
 * Hermiq Application
 *
 * Main application class for the Hermiq Nextcloud app.
 *
 * @category AppInfo
 * @package  OCA\Hermiq\AppInfo
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/2026-09-06-agent-schedule-dispatcher/tasks.md#task-1-2
 */

declare(strict_types=1);

namespace OCA\Hermiq\AppInfo;

use OCA\Hermiq\Kernel\AppIdentity;
use OCA\Hermiq\Listener\ListenerRegistrar;
use OCA\Hermiq\Middleware\MiddlewareRegistrar;
use OCA\Hermiq\Mcp\McpRegistrar;
use OCA\Hermiq\Notification\NotificationRegistrar;
use OCA\Hermiq\Service\ServiceRegistrar;
use OCA\Hermiq\TaskProcessing\ProviderRegistrar;
use OCA\Hermiq\Tenant\TenantRegistrar;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Main application class for the Hermiq Nextcloud app.
 *
 * @spec openspec/changes/archive/2026-09-06-agent-schedule-dispatcher/tasks.md#task-1-2
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Bootstrap class wires the component
 * registrars — one reference per registered integration point, no
 * behavioural coupling.
 */
class Application extends App implements IBootstrap {
	public const APP_ID = AppIdentity::APP_ID;

	/**
	 * Constructor for the Application class.
	 *
	 * @return void
	 */
	public function __construct() {
		parent::__construct(appName: self::APP_ID);
	}//end __construct()

	/**
	 * Register event listeners and services.
	 *
	 * @param IRegistrationContext $context The registration context
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 * @SuppressWarnings(PHPMD.StaticAccess)          OpenRegisterAutoloader::register() is
	 * a static bootstrap hook: there is nothing to inject before the container exists.
	 *
	 * @spec openspec/changes/archive/2026-09-06-agent-schedule-dispatcher/tasks.md#task-1-2
	 */
	public function register(IRegistrationContext $context): void {
		$middleware = new MiddlewareRegistrar();
		$middleware->registerFirst(context: $context);
		// Load the app's own composer autoloader so bundled dependencies (e.g.
		// dragonmantank/cron-expression, used by ScheduleService) resolve at
		// runtime — Nextcloud does not auto-load an app's vendor/autoload.php.
		// Mirrors openregister/openconnector (ADR-002 dispatcher chain).
		include_once __DIR__ . '/../../vendor/autoload.php';

		// ADR-040 prelude. `Coordinator::registerApps()` registers ONE app's
		// autoloader and then immediately calls that app's register(), walking
		// the alphabetically sorted enabled-app list. `hermiq` sorts before
		// `openregister`, so without this line every `class_exists('OCA\
		// OpenRegister\…')` probe below answers FALSE on a healthy instance and
		// the flow-node / agent-leaf / shareable-config plumbing is skipped in
		// silence. Returns false (never throws) when OpenRegister really is
		// absent, and the guards below then do the right thing.
		OpenRegisterAutoloader::register();

		(new ListenerRegistrar())->register(context: $context, addInitScript: $this->addInitScript(...));
		(new NotificationRegistrar())->register(context: $context);
		(new TenantRegistrar())->register(context: $context);
		(new ServiceRegistrar())->register(context: $context);
		(new McpRegistrar())->register(context: $context);
		(new ProviderRegistrar())->register(context: $context);

		$middleware->registerLast(context: $context);

	}//end register()


	/**
	 * Load one of Hermiq's bundles on every Nextcloud page.
	 *
	 * @param string $suffix The bundle name after the app id.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) \OCP\Util::addInitScript is the Nextcloud
	 * asset API: there is no injectable equivalent in a bootstrap register() hook.
	 *
	 * @spec openspec/changes/archive/2026-09-06-agent-schedule-dispatcher/tasks.md#task-1-2
	 */
	protected function addInitScript(string $suffix): void {
		\OCP\Util::addInitScript(self::APP_ID, self::APP_ID . $suffix);
	}//end addInitScript()

	/**
	 * Boot the application.
	 *
	 * @param IBootContext $context The boot context
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 *
	 * @spec exclude Empty framework boot hook — all wiring happens in register(); no behavioural contract.
	 */
	public function boot(IBootContext $context): void {
	}//end boot()
}//end class

<?php

/**
 * Hermiq Enable Engine On Install Repair Step
 *
 * Turns the in-app agent engine on for a new install.
 *
 * @category Repair
 * @package  OCA\Hermiq\Repair
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
 */

declare(strict_types=1);

namespace OCA\Hermiq\Repair;

use OCA\Hermiq\AppInfo\Application;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/**
 * Sets `hermiq`.`engine.enabled` to on for a new install, when it is unset.
 *
 * Registered under `<install>` only, so an upgrade never runs it: an existing
 * install keeps whatever it has stored (an unset key there still reads as off).
 * A value that is already stored, on or off, is never overwritten, so an
 * administrator who switched the engine off keeps it off when the app is
 * re-enabled.
 *
 * Without the engine, scheduled runs and goal turns take OpenRegister's old
 * chat path, which cannot find hermiq agents.
 *
 * @spec exclude Product decision (engine on by default for new installs); no capability spec covers the install default.
 */
class EnableEngineOnInstall implements IRepairStep {
	/**
	 * The app-config key of the in-app agent engine feature flag.
	 *
	 * @var string
	 */
	public const ENGINE_FLAG_KEY = 'engine.enabled';

	/**
	 * Constructor for EnableEngineOnInstall.
	 *
	 * @param IAppConfig $appConfig App config holding the engine feature flag.
	 *
	 * @return void
	 */
	public function __construct(
		private IAppConfig $appConfig,
	) {
	}//end __construct()

	/**
	 * Get the name of this repair step.
	 *
	 * @return string
	 *
	 * @spec exclude Trivial IRepairStep display-name accessor; no behavioural spec.
	 */
	public function getName(): string {
		return 'Enable the Hermiq agent engine on a new install';
	}//end getName()

	/**
	 * Store `engine.enabled=true` unless a value is already stored.
	 *
	 * @param IOutput $output The output interface for progress reporting.
	 *
	 * @return void
	 *
	 * @spec exclude Product decision (engine on by default for new installs); no capability spec covers the install default.
	 */
	public function run(IOutput $output): void {
		if ($this->appConfig->hasKey(Application::APP_ID, self::ENGINE_FLAG_KEY) === true) {
			$output->info('Hermiq agent engine flag already set; keeping the stored value.');
			return;
		}

		$this->appConfig->setValueString(Application::APP_ID, self::ENGINE_FLAG_KEY, 'true');
		$output->info('Hermiq agent engine enabled for this new install.');
	}//end run()
}//end class

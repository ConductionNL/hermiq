<?php

/**
 * Hermiq Initialize Settings Repair Step
 *
 * Repair step that initializes Hermiq register and schemas on install/upgrade.
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
use OCA\Hermiq\Service\SettingsService;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;

/**
 * Repair step that initializes Hermiq configuration via SettingsService.
 *
 * @spec openspec/specs/configuration-initialization/spec.md#req-init-002-import-configuration-on-install-upgrade
 */
class InitializeSettings implements IRepairStep {
	/**
	 * App-config key tracking the register version that was last APPLIED via a forced
	 * import. Deliberately hermiq-owned bookkeeping: OpenRegister's own stored version
	 * advances on `importFromApp(force: false)` even when nothing was applied
	 * (openregister#2075), so it cannot be trusted to decide when to force.
	 *
	 * @var string
	 */
	private const APPLIED_VERSION_KEY = 'register_version_applied';

	/**
	 * Constructor for InitializeSettings.
	 *
	 * @param SettingsService $settingsService The settings service
	 * @param IAppConfig $appConfig App config (applied register-version bookkeeping)
	 * @param LoggerInterface $logger The logger interface
	 *
	 * @return void
	 */
	public function __construct(
		private SettingsService $settingsService,
		private IAppConfig $appConfig,
		private LoggerInterface $logger,
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
		return 'Initialize Hermiq register and schemas via ConfigurationService';
	}//end getName()

	/**
	 * Run the repair step to initialize Hermiq configuration.
	 *
	 * The import is FORCED whenever the register version declared in
	 * hermiq_register.json differs from the version this step last applied:
	 * `importFromApp(force: false)` advances the stored version WITHOUT applying
	 * schema changes to existing schemas (openregister#2075), so relying on it
	 * would leave existing installs' schemas without newly-declared properties
	 * (e.g. the skill-maturity `agentskill` fields).
	 *
	 * @param IOutput $output The output interface for progress reporting
	 *
	 * @return void
	 *
	 * @spec openspec/specs/configuration-initialization/spec.md#req-init-002-import-configuration-on-install-upgrade
	 * @spec openspec/specs/skill-maturity/spec.md#requirement-the-skill-schema-carries-maturity-metadata-as-optional-inert-fields
	 */
	public function run(IOutput $output): void {
		$output->info('Initializing Hermiq configuration...');

		if ($this->settingsService->isOpenRegisterAvailable() === false) {
			$this->reportOpenRegisterMissing(output: $output);
			return;
		}

		try {
			$this->importConfiguration(output: $output);
		} catch (\Throwable $e) {
			$this->reportFailure(error: $e, output: $output);
		}
	}//end run()

	/**
	 * Warn and log that the configuration could not be imported at all.
	 *
	 * @param \Throwable $error What went wrong.
	 * @param IOutput $output The output interface for progress reporting
	 *
	 * @return void
	 */
	private function reportFailure(\Throwable $error, IOutput $output): void {
		$output->warning('Could not auto-configure Hermiq: ' . $error->getMessage());
		$this->logger->error(
			'Hermiq initialization failed',
			['exception' => $error->getMessage()]
		);
	}//end reportFailure()

	/**
	 * Warn that OpenRegister is not available and the step is skipped.
	 *
	 * @param IOutput $output The output interface for progress reporting
	 *
	 * @return void
	 */
	private function reportOpenRegisterMissing(IOutput $output): void {
		$output->warning(
			'OpenRegister is not installed or enabled. Skipping auto-configuration.'
		);
		$this->logger->warning(
			'Hermiq: OpenRegister not available, skipping register initialization'
		);
	}//end reportOpenRegisterMissing()

	/**
	 * Import the register configuration, forced when its version changed, and record
	 * the applied version only after a forced import succeeded.
	 *
	 * @param IOutput $output The output interface for progress reporting
	 *
	 * @return void
	 */
	private function importConfiguration(IOutput $output): void {
		$configVersion = $this->settingsService->getRegisterConfigVersion();
		$force = $this->mustForce(configVersion: $configVersion, output: $output);

		$result = $this->settingsService->loadConfiguration(force: $force);
		if ($result['success'] !== true) {
			$this->reportImportIssue(result: $result, output: $output);
			return;
		}

		if ($force === true) {
			$this->recordApplied(configVersion: $configVersion);
		}

		$this->reportImported(result: $result, output: $output);
	}//end importConfiguration()

	/**
	 * Warn about an import that reported a problem.
	 *
	 * @param array $result The import result.
	 * @param IOutput $output The output interface for progress reporting
	 *
	 * @return void
	 */
	private function reportImportIssue(array $result, IOutput $output): void {
		$message = ($result['message'] ?? 'unknown error');
		$output->warning(
			'Hermiq configuration import issue: ' . $message
		);
	}//end reportImportIssue()

	/**
	 * Report an import that succeeded, with the version it reported.
	 *
	 * @param array $result The import result.
	 * @param IOutput $output The output interface for progress reporting
	 *
	 * @return void
	 */
	private function reportImported(array $result, IOutput $output): void {
		$version = ($result['version'] ?? 'unknown');
		$output->info(
			'Hermiq configuration imported successfully (version: ' . $version . ')'
		);
	}//end reportImported()

	/**
	 * Record the register version a forced import has just applied.
	 *
	 * @param string $configVersion The version hermiq_register.json declares.
	 *
	 * @return void
	 */
	private function recordApplied(string $configVersion): void {
		$this->appConfig->setValueString(
			Application::APP_ID,
			self::APPLIED_VERSION_KEY,
			$configVersion
		);
	}//end recordApplied()

	/**
	 * Whether the import must be forced: whenever the declared register version differs
	 * from the one this step last applied. Says so on the output when it does.
	 *
	 * @param string $configVersion The version hermiq_register.json declares.
	 * @param IOutput $output The output interface for progress reporting
	 *
	 * @return bool
	 */
	private function mustForce(string $configVersion, IOutput $output): bool {
		$appliedVersion = $this->appConfig->getValueString(
			Application::APP_ID,
			self::APPLIED_VERSION_KEY,
			''
		);

		// Force on any version change so existing installs' schemas actually gain
		// newly-declared properties (openregister#2075); a fresh install forces its
		// very first import too, which is harmless.
		$force = ($appliedVersion !== $configVersion);
		if ($force === true) {
			$output->info(
				'Register config version changed (' . $appliedVersion . ' → ' . $configVersion . ') — forcing re-import.'
			);
		}

		return $force;
	}//end mustForce()
}//end class

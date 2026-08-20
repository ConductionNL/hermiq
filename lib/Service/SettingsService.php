<?php

/**
 * Hermiq Settings Service
 *
 * Service for managing Hermiq application configuration and settings.
 *
 * @category Service
 * @package  OCA\Hermiq\Service
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

namespace OCA\Hermiq\Service;

use OCA\Hermiq\AppInfo\Application;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for managing Hermiq application configuration and settings.
 *
 * @spec openspec/specs/settings-management/spec.md
 */
class SettingsService {

	/**
	 * Configuration keys managed by this service.
	 *
	 * @var array<string>
	 */
	private const CONFIG_KEYS = [
		'register',
	];

	/**
	 * Constructor for the SettingsService.
	 *
	 * @param IAppConfig $appConfig The app config interface
	 * @param IAppManager $appManager The app manager
	 * @param ContainerInterface $container The container
	 * @param IGroupManager $groupManager The group manager
	 * @param IUserSession $userSession The user session
	 * @param LoggerInterface $logger The logger
	 *
	 * @return void
	 */
	public function __construct(
		private IAppConfig $appConfig,
		private IAppManager $appManager,
		private ContainerInterface $container,
		private IGroupManager $groupManager,
		private IUserSession $userSession,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Check whether OpenRegister is installed and available.
	 *
	 * @return bool
	 *
	 * @spec exclude Trivial app-availability probe delegating to IAppManager; no behavioural spec.
	 */
	public function isOpenRegisterAvailable(): bool {
		return $this->appManager->isInstalled('openregister');
	}//end isOpenRegisterAvailable()

	/**
	 * Retrieve all current settings.
	 *
	 * Returns a flat array containing all app config values plus metadata
	 * fields (openregisters, isAdmin) consumed by the frontend.
	 *
	 * @return array<string,mixed>
	 *
	 * @spec openspec/specs/settings-management/spec.md#req-cfg-001-read-current-settings
	 */
	public function getSettings(): array {
		$settings = [];
		foreach (self::CONFIG_KEYS as $key) {
			$settings[$key] = $this->appConfig->getValueString(Application::APP_ID, $key, '');
		}

		$user = $this->userSession->getUser();
		$isAdmin = ($user !== null && $this->groupManager->isAdmin($user->getUID()));

		return array_merge(
			$settings,
			[
				'openregisters' => $this->isOpenRegisterAvailable(),
				'isAdmin' => $isAdmin,
			]
		);
	}//end getSettings()

	/**
	 * Update settings with the provided data.
	 *
	 * @param array<string,mixed> $data The data to update
	 *
	 * @return array<string,mixed> The updated settings
	 *
	 * @spec openspec/specs/settings-management/spec.md#req-cfg-002-update-settings-admin-only
	 */
	public function updateSettings(array $data): array {
		foreach (self::CONFIG_KEYS as $key) {
			if (isset($data[$key]) === true) {
				$this->appConfig->setValueString(Application::APP_ID, $key, (string)$data[$key]);
			}
		}

		return $this->getSettings();
	}//end updateSettings()

	/**
	 * Read the register configuration version declared in hermiq_register.json.
	 *
	 * Used by the InitializeSettings repair step to decide whether the import must be
	 * FORCED: OpenRegister's `importFromApp(force: false)` advances the stored version
	 * WITHOUT applying schema changes to existing schemas (openregister#2075), so a
	 * version bump that must reach existing installs (e.g. the skill-maturity
	 * `agentskill` properties) needs a forced import keyed on this value.
	 *
	 * @return string The configured register version (SemVer), or '0.0.0' when unreadable.
	 *
	 * @spec openspec/specs/skill-maturity/spec.md#requirement-the-skill-schema-carries-maturity-metadata-as-optional-inert-fields
	 */
	public function getRegisterConfigVersion(): string {
		$configPath = __DIR__ . '/../Settings/hermiq_register.json';
		if (file_exists($configPath) === false) {
			return '0.0.0';
		}

		$configContent = file_get_contents($configPath);
		if ($configContent === false) {
			return '0.0.0';
		}

		$configData = json_decode($configContent, true);
		if (json_last_error() !== JSON_ERROR_NONE || is_array($configData) === false) {
			return '0.0.0';
		}

		return (string)($configData['info']['version'] ?? '0.0.0');
	}//end getRegisterConfigVersion()

	/**
	 * Load configuration from hermiq_register.json via OpenRegister.
	 *
	 * @param bool $force Force re-import even if already configured.
	 *
	 * @return array<string,mixed> Result with success flag, message, and version.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) `$force` is a standard idempotent-import
	 *   override toggle (skip-if-configured vs. re-import), not a behavioural mode switch.
	 *
	 * @spec openspec/specs/settings-management/spec.md#req-cfg-003-reload-configuration-from-json-file-admin-only
	 */
	public function loadConfiguration(bool $force = false): array {
		if ($this->isOpenRegisterAvailable() === false) {
			$this->logger->warning('Hermiq: OpenRegister not available, skipping register initialization');
			return [
				'success' => false,
				'message' => 'OpenRegister is not installed or enabled.',
			];
		}

		$configPath = __DIR__ . '/../Settings/hermiq_register.json';
		if (file_exists($configPath) === false) {
			$this->logger->error('Hermiq: hermiq_register.json not found at ' . $configPath);
			return [
				'success' => false,
				'message' => 'Configuration file hermiq_register.json not found.',
			];
		}

		$configContent = file_get_contents($configPath);
		if ($configContent === false) {
			$this->logger->error('Hermiq: failed to read hermiq_register.json');
			return [
				'success' => false,
				'message' => 'Failed to read configuration file.',
			];
		}

		$configData = json_decode($configContent, true);
		if (json_last_error() !== JSON_ERROR_NONE) {
			$this->logger->error('Hermiq: failed to parse hermiq_register.json: ' . json_last_error_msg());
			return [
				'success' => false,
				'message' => 'Failed to parse configuration file: ' . json_last_error_msg(),
			];
		}

		$configVersion = ($configData['info']['version'] ?? '0.0.0');

		try {
			$configurationService = $this->container->get('OCA\OpenRegister\Service\ConfigurationService');

			$result = $configurationService->importFromApp(
				appId: Application::APP_ID,
				data: $configData,
				version: $configVersion,
				force: $force
			);

			if (empty($result) === false) {
				$this->logger->info('Hermiq: register configuration imported successfully');
				return [
					'success' => true,
					'message' => 'Configuration imported successfully.',
					'version' => ($result['version'] ?? $configVersion),
				];
			}

			return [
				'success' => false,
				'message' => 'Import returned an empty result.',
			];
		} catch (\Throwable $e) {
			$this->logger->error(
				'Hermiq: configuration import failed',
				['exception' => $e->getMessage()]
			);
			return [
				'success' => false,
				'message' => $e->getMessage(),
			];
		}//end try
	}//end loadConfiguration()
}//end class

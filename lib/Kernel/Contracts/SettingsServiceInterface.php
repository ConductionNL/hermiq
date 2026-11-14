<?php

/**
 * Contract of SettingsService
 *
 * The public surface of SettingsService that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Hermiq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/hermiq
 *
 * @spec exclude Structural contract, no functional requirement.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Hermiq\Kernel\Contracts;

/**
 * Contract of SettingsService.
 *
 * @category Kernel
 * @package  OCA\Hermiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/hermiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface SettingsServiceInterface {

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
	public function getRegisterConfigVersion(): string;

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
	public function getSettings(): array;

	/**
	 * Check whether OpenRegister is installed and available.
	 *
	 * @return bool
	 *
	 * @spec exclude Trivial app-availability probe delegating to IAppManager; no behavioural spec.
	 */
	public function isOpenRegisterAvailable(): bool;

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
	public function loadConfiguration(bool $force = false): array;

	/**
	 * Update settings with the provided data.
	 *
	 * @param array<string,mixed> $data The data to update
	 *
	 * @return array<string,mixed> The updated settings
	 *
	 * @spec openspec/specs/settings-management/spec.md#req-cfg-002-update-settings-admin-only
	 */
	public function updateSettings(array $data): array;
}//end interface

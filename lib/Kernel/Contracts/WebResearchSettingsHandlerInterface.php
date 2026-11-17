<?php

/**
 * Contract of WebResearchSettingsHandler
 *
 * The public surface of WebResearchSettingsHandler that other components call, so they depend
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

use RuntimeException;

/**
 * Contract of WebResearchSettingsHandler.
 *
 * @category Kernel
 * @package  OCA\Hermiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/hermiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface WebResearchSettingsHandlerInterface {

	/**
	 * The allowed `searchProvider` values. An empty string is the valid, default
	 * "unconfigured" state (`web.search` then self-reports `search_unavailable`).
	 *
	 * @var string[]
	 */
	public const ALLOWED_SEARCH_PROVIDERS = ['', 'searxng', 'generic-json'];

	/**
	 * Get the web-research settings, defaulting every field when the key is unset.
	 *
	 * @return array<string, mixed> The web-research configuration (design.md
	 *                              "Configuration Shape").
	 *
	 * @throws RuntimeException If the stored config cannot be decoded.
	 *
	 * @spec openspec/specs/web-research-tool/spec.md#requirement-pluggable-admin-configured-search-backend
	 */
	public function getWebResearchSettingsOnly(): array;

	/**
	 * Update the web-research settings (PATCH semantics — merges with the existing
	 * stored config; an omitted or unset field keeps its prior value).
	 *
	 * @param array<string, mixed> $data Partial or full web-research configuration.
	 *
	 * @return array<string, mixed> The merged, persisted configuration.
	 *
	 * @throws RuntimeException If persisting fails.
	 *
	 * @spec openspec/specs/web-research-tool/spec.md#requirement-pluggable-admin-configured-search-backend
	 */
	public function updateWebResearchSettingsOnly(array $data): array;
}//end interface

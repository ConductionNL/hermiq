<?php

/**
 * Contract of ToolSearchService
 *
 * The public surface of ToolSearchService that other components call, so they depend
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
 * Contract of ToolSearchService.
 *
 * @category Kernel
 * @package  OCA\Hermiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/hermiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ToolSearchServiceInterface {

	/**
	 * Register this run's resolved (grant-filtered, default-denied) descriptor
	 * set. Safe to call once per turn; a later call replaces the set (a fresh
	 * `listAgentFunctions()` resolution supersedes the previous one).
	 *
	 * @param array<int, mixed> $descriptors The resolved descriptor list. Typed loosely on
	 *                                       purpose: these cross the OpenRegister tool-facade
	 *                                       boundary, so each entry is re-checked below.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/agent-tool-governance/spec.md#scenario-a-resolved-catalog-exceeds-the-disclosure-threshold
	 */
	public function registerResolved(array $descriptors): void;
}//end interface

<?php

/**
 * Contract of ForgeEgressPolicy
 *
 * The public surface of ForgeEgressPolicy that other components call, so they depend
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
 * Contract of ForgeEgressPolicy.
 *
 * @category Kernel
 * @package  OCA\Hermiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/hermiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ForgeEgressPolicyInterface {

	/**
	 * Whether a destination is the configured forge host.
	 *
	 * @param string $host The destination host.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/agent-workspace-git-tools/spec.md#scenario-the-forge-host-is-denied-without-the-grant
	 */
	public function isForgeHost(string $host): bool;

	/**
	 * Whether this run's agent holds a resolving grant for a tool that needs the forge.
	 *
	 * @param string $agentId The agent UUID from the verified run token.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/agent-workspace-git-tools/spec.md#scenario-the-forge-host-is-denied-without-the-grant
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) RepoEffectingGrants is a pure rule over the grant grammar, like ToolGrantResolver's static classifiers.
	 */
	public function permits(string $agentId): bool;
}//end interface

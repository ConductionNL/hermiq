<?php

/**
 * Contract of ToolAccessRequestService
 *
 * The public surface of ToolAccessRequestService that other components call, so they depend
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
 * Contract of ToolAccessRequestService.
 *
 * @category Kernel
 * @package  OCA\Hermiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/hermiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ToolAccessRequestServiceInterface {

	/**
	 * List tools the acting user can reach, marking which the agent already holds.
	 *
	 * ⚠️ Scoped to the USER, not to the agent's grants and not to the whole
	 * instance. Seeing past the grant is the point of the feature; returning the
	 * unfiltered catalogue would tell whoever can start a chat which apps are
	 * installed and what they do.
	 *
	 * @param string $uid The acting user.
	 * @param string|null $agentId The calling agent, for the `held` flag.
	 * @param string $query Optional keyword filter.
	 *
	 * @return array<string, mixed> Tool metadata — never anything dispatchable.
	 *
	 * @spec openspec/changes/archive/2026-11-12-tool-discovery-and-access-requests/specs/tool-discovery-and-access-requests/spec.md
	 */
	public function listAvailable(string $uid, ?string $agentId, string $query = ''): array;

	/**
	 * The approvals an agent is currently waiting on, for the chat to show.
	 *
	 * Shaped as a GENERIC approval, not as a tool-access request: each item
	 * carries its own `kind` and its own `resolveUrl`, so the chat posts a
	 * decision where it is told to rather than knowing how any particular
	 * approval is resolved. A future approval — fetching a URL, editing a file
	 * that is not open — supplies its own two fields and needs no change in the
	 * client at all.
	 *
	 * Read-only and safe to call on every turn: it neither creates nor resolves
	 * anything.
	 *
	 * @param string|null $agentId The agent whose pending requests to list.
	 *
	 * @return array<int, array<string, mixed>> Pending approvals, oldest first.
	 *
	 * @spec openspec/changes/archive/2026-11-12-tool-discovery-and-access-requests/specs/tool-discovery-and-access-requests/spec.md
	 */
	public function pendingApprovals(?string $agentId): array;

	/**
	 * Raise an access request. Grants nothing.
	 *
	 * @param string $uid The acting user.
	 * @param string|null $agentId The agent asking.
	 * @param string $toolId The tool it wants.
	 * @param string $reason Why — shown to the human who decides.
	 *
	 * @return array<string, mixed> The pending request, or an error.
	 *
	 * @spec openspec/changes/archive/2026-11-12-tool-discovery-and-access-requests/specs/tool-discovery-and-access-requests/spec.md
	 */
	public function request(string $uid, ?string $agentId, string $toolId, string $reason): array;
}//end interface

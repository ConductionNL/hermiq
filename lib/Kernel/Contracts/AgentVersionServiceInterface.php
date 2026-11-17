<?php

/**
 * Contract of AgentVersionService
 *
 * The public surface of AgentVersionService that other components call, so they depend
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

use OCA\OpenRegister\Db\ObjectEntity;
use RuntimeException;

/**
 * Contract of AgentVersionService.
 *
 * @category Kernel
 * @package  OCA\Hermiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/hermiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface AgentVersionServiceInterface {

	/**
	 * Diff two versions across the fixed `VERSIONED_FIELDS` allowlist.
	 *
	 * @param string $agentUuid The Agent object UUID.
	 * @param string $fromId The "old" version's AuditTrail entry UUID.
	 * @param string $toId The "new" version's AuditTrail entry UUID.
	 *
	 * @return array<string, array{old: mixed, new: mixed}> Only the fields that
	 *                                                      differ between the two versions (empty when $fromId === $toId or
	 *                                                      nothing in the allowlist changed).
	 *
	 * @throws RuntimeException When either version id is unknown for this agent.
	 *
	 * @spec openspec/specs/agent-versioning/spec.md#requirement-diff-two-agent-versions-across-the-versioned-config-field-set
	 */
	public function diff(string $agentUuid, string $fromId, string $toId): array;

	/**
	 * List an Agent's version history, newest-first.
	 *
	 * @param string $agentUuid The Agent object UUID.
	 *
	 * @return array<int, array<string, mixed>> The versions (id, timestamp, user,
	 *                                          action, changedFields), newest-first.
	 *
	 * @spec openspec/specs/agent-versioning/spec.md#requirement-list-an-agents-version-history
	 */
	public function listVersions(string $agentUuid): array;

	/**
	 * Roll an Agent back to a previous version's `VERSIONED_FIELDS` values.
	 *
	 * Reconstructs the target version's allowlisted field values and merges
	 * them onto the agent's CURRENT payload (every non-allowlisted field is
	 * left untouched), then persists via `ObjectService::saveObject()` — the
	 * exact call `AgentsController::update()` already makes — which itself
	 * writes a brand-new AuditTrail entry. History is never mutated: the
	 * target version's own entry is only ever read here.
	 *
	 * @param string $agentUuid The Agent object UUID.
	 * @param string $versionId The target version's AuditTrail entry UUID.
	 *
	 * @return ObjectEntity The updated agent (a new version, equal in content
	 *                      to the target for every allowlisted field).
	 *
	 * @throws RuntimeException When the version id is unknown, or the agent
	 *                          cannot be resolved.
	 *
	 * @spec openspec/specs/agent-versioning/spec.md#requirement-roll-back-an-agent-to-a-previous-version-without-mutating-history
	 */
	public function rollback(string $agentUuid, string $versionId): ObjectEntity;
}//end interface

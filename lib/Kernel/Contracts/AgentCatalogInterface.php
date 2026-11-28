<?php

/**
 * Contract of AgentCatalog
 *
 * The public surface of AgentCatalog that other components call, so they depend
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

/**
 * Contract of AgentCatalog.
 *
 * @category Kernel
 * @package  OCA\Hermiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/hermiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface AgentCatalogInterface {

	/**
	 * One page of the agents the user may see.
	 *
	 * @param string $uid The user.
	 * @param integer $limit Page size.
	 * @param integer $offset Rows to skip.
	 *
	 * @return array{results: array<int, array<string, mixed>>, total: int, limit: int, offset: int}
	 *
	 * @spec openspec/changes/agents-sharing-and-catalog-columns/specs/agent-management-ui/spec.md#requirement-the-agent-catalog-shows-owner-sharing-and-status-req-agshare-003
	 * @spec openspec/changes/agents-sharing-and-catalog-columns/specs/agent-management-ui/spec.md#requirement-an-organisation-admin-sees-every-agent-of-the-organisation-req-agshare-004
	 */
	public function page(string $uid, int $limit, int $offset): array;

	/**
	 * One agent as the user may see it: the full row, the reduced row for an
	 * organisation admin, or null (a 404 to the caller).
	 *
	 * @param ObjectEntity $agent The agent.
	 * @param string $uid The user.
	 *
	 * @return array<string, mixed>|null The row.
	 *
	 * @spec openspec/changes/agents-sharing-and-catalog-columns/specs/agent-management-ui/spec.md#requirement-an-organisation-admin-sees-every-agent-of-the-organisation-req-agshare-004
	 */
	public function row(ObjectEntity $agent, string $uid): ?array;
}//end interface

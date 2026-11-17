<?php

/**
 * Contract of ComplianceService
 *
 * The public surface of ComplianceService that other components call, so they depend
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
 * Contract of ComplianceService.
 *
 * @category Kernel
 * @package  OCA\Hermiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/hermiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ComplianceServiceInterface {

	/**
	 * The auditor's-pack export: the existing, unmodified `exportAuditTrail()`
	 * output nested alongside the same per-control coverage data the dashboard
	 * shows. Never replaces or alters the existing Art. 12-only export endpoint.
	 *
	 * @param string $organisation The organisation identifier (may be '').
	 *
	 * @return array<string, mixed> `{ auditTrail, complianceCoverage, generatedAt }`.
	 *
	 * @spec openspec/changes/archive/2026-07-13-compliance-control-packs/tasks.md#task-4-complianceservice-dashboard-export-and-factsheet-aggregation
	 */
	public function auditorPack(string $organisation): array;

	/**
	 * The org-scoped compliance dashboard: per-framework coverage percentage and the
	 * full gap list (every control not `satisfied`).
	 *
	 * @param string $organisation The organisation identifier (may be '' for the
	 *                             instance-default scope, mirrors ModelPolicy).
	 *
	 * @return array<string, mixed> `{ frameworks: [...], gaps: [...] }`.
	 *
	 * @spec openspec/changes/archive/2026-07-13-compliance-control-packs/tasks.md#task-4-complianceservice-dashboard-export-and-factsheet-aggregation
	 */
	public function dashboard(string $organisation): array;

	/**
	 * The per-agent AI factsheet: a read-only envelope assembled live from the
	 * `Agent`, `AiFeature` (best-effort match, see class docblock), `Approval`, and
	 * `Incident` data — no new persisted object.
	 *
	 * @param string $agentId The agent UUID.
	 *
	 * @return array<string, mixed>|null The factsheet, or null when the agent does not exist.
	 *
	 * @spec openspec/changes/archive/2026-07-13-compliance-control-packs/tasks.md#task-4-complianceservice-dashboard-export-and-factsheet-aggregation
	 */
	public function factsheet(string $agentId): ?array;

	/**
	 * Load an Agent by UUID, system-wide (RBAC-off — the controller applies its own
	 * ownership/action-auth IDOR guard before calling `factsheet()`).
	 *
	 * @param string $agentId The agent UUID.
	 *
	 * @return ObjectEntity|null The agent, or null when absent.
	 *
	 * @spec openspec/changes/archive/2026-07-13-compliance-control-packs/tasks.md#task-5-compliancecontroller-routes-action-auth-gating
	 */
	public function findAgent(string $agentId): ?ObjectEntity;
}//end interface

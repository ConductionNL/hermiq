<?php

/**
 * Hermiq ToolInvocationLog.
 *
 * The rows of the per-agent tool-invocation oversight read (EU AI Act art.12/14):
 * OpenRegister's MCP invocation audit entries by the agent's correlated owners,
 * or the coarser `run` entries of its schedules when the richer shape is absent,
 * and their CSV export.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\ToolOversight
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/agent-tool-governance/spec.md#requirement-per-agent-tool-invocation-oversight-surface-ai-act-art-12-14
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\ToolOversight;

use OCA\Hermiq\Service\ToolInvocationRows;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * Reads and exports one agent's tool invocations.
 *
 * @psalm-api
 *
 * @spec openspec/specs/agent-tool-governance/spec.md#requirement-per-agent-tool-invocation-oversight-surface-ai-act-art-12-14
 */
class ToolInvocationLog {

	/**
	 * OpenRegister register slug that holds Hermiq objects.
	 *
	 * @var string
	 */
	private const REGISTER_SLUG = 'hermiq';

	/**
	 * Schema slug for schedule objects (owner-correlation for the oversight read).
	 *
	 * @var string
	 */
	private const SCHEDULE_SCHEMA = 'schedule';

	/**
	 * The audit `action` values an MCP tool invocation entry carries
	 * (`AuditTrailMapper::createToolInvocationEntry()`: `mcp.{verb}`).
	 *
	 * @var string
	 */
	private const MCP_ACTIONS = 'mcp.search,mcp.get,mcp.create,mcp.update,mcp.delete';

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService OpenRegister object read (the agent's schedules).
	 * @param AuditTrailMapper $auditTrailMapper OR audit read (MCP invocation / run entries).
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly AuditTrailMapper $auditTrailMapper,
	) {
	}//end __construct()

	/**
	 * The owner UIDs correlated with this agent: its own owner plus the owners
	 * of every schedule bound to it (the LIMITATION documented in the class
	 * docblock: OR's MCP audit entry records the ambient session user, not an
	 * agent principal, so this is the best available correlation).
	 *
	 * @param string $agentId Agent UUID.
	 * @param ObjectEntity $agent Agent object.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/specs/agent-tool-governance/spec.md#requirement-per-agent-tool-invocation-oversight-surface-ai-act-art-12-14
	 */
	public function correlatedOwnerUids(string $agentId, ObjectEntity $agent): array {
		$owners = [];

		$agentOwner = (string)($agent->getOwner() ?? '');
		if ($agentOwner !== '') {
			$owners[$agentOwner] = true;
		}

		foreach ($this->agentSchedules(agentId: $agentId) as $schedule) {
			$owner = (string)($schedule->getOwner() ?? '');
			if ($owner !== '') {
				$owners[$owner] = true;
			}
		}

		return array_keys($owners);
	}//end correlatedOwnerUids()

	/**
	 * Rich source: OR's per-invocation MCP audit entries by this agent's correlated owners.
	 *
	 * @param array<int, string> $ownerUids The agent's owner and its schedules' owners.
	 * @param array{from: ?string, to: ?string} $window Optional ISO 8601 bounds (inclusive).
	 *
	 * @return array<int, array<string, mixed>> Newest-first rows.
	 *
	 * @spec openspec/specs/agent-tool-governance/spec.md#scenario-the-richer-invocation-audit-shape-is-not-yet-available
	 */
	public function richRows(array $ownerUids, array $window): array {
		if ($ownerUids === []) {
			return [];
		}

		$ownerSet = array_flip($ownerUids);
		$shape = new ToolInvocationRows();
		$rows = [];
		foreach ($this->auditTrailMapper->findAll(filters: ['action' => self::MCP_ACTIONS]) as $log) {
			$occurredAt = $shape->occurredAt(log: $log);
			if (isset($ownerSet[(string)($log->getUser() ?? '')]) === true && $shape->inWindow(occurredAt: $occurredAt, window: $window) === true) {
				$rows[] = $shape->richRow(log: $log, occurredAt: $occurredAt);
			}
		}

		return $shape->newestFirst(rows: $rows);
	}//end richRows()

	/**
	 * Degraded fallback: coarse `action='run'` entries for this agent's
	 * schedules (the same source `run-audit-log`/`AnalyticsService` already
	 * read), used when the richer per-invocation shape is not yet available.
	 *
	 * @param string $agentId Agent UUID.
	 * @param array{from: ?string, to: ?string} $window Optional ISO 8601 bounds (inclusive).
	 *
	 * @return array<int, array<string, mixed>> Newest-first rows.
	 *
	 * @throws DoesNotExistException Propagated when the hermiq register/schema
	 *                               cannot be resolved by `ObjectService::findAll()`: intentional,
	 *                               `toolInvocations()`'s catch translates it to a JSON error response.
	 *
	 * @spec openspec/specs/agent-tool-governance/spec.md#scenario-the-richer-invocation-audit-shape-is-not-yet-available
	 */
	public function degradedRows(string $agentId, array $window): array {
		$scheduleUuids = $this->agentScheduleUuids(agentId: $agentId);
		if ($scheduleUuids === []) {
			return [];
		}

		$shape = new ToolInvocationRows();
		$rows = [];
		foreach ($this->auditTrailMapper->findAll(filters: ['action' => 'run']) as $log) {
			$occurredAt = $shape->occurredAt(log: $log);
			if (isset($scheduleUuids[(string)$log->getObjectUuid()]) === true && $shape->inWindow(occurredAt: $occurredAt, window: $window) === true) {
				$rows[] = $this->degradedRow(log: $log, occurredAt: $occurredAt);
			}
		}

		return $shape->newestFirst(rows: $rows);
	}//end degradedRows()

	/**
	 * The UUIDs of this agent's schedules, as a set.
	 *
	 * @param string $agentId Agent UUID.
	 *
	 * @return array<string, true> Schedule UUID => true.
	 *
	 * @spec openspec/specs/agent-tool-governance/spec.md#scenario-the-richer-invocation-audit-shape-is-not-yet-available
	 */
	private function agentScheduleUuids(string $agentId): array {
		$uuids = [];
		foreach ($this->agentSchedules(agentId: $agentId) as $schedule) {
			$uuids[(string)$schedule->getUuid()] = true;
		}

		return $uuids;
	}//end agentScheduleUuids()

	/**
	 * Render rows as CSV text (header + one line per row; `resultSummary`/
	 * `dataTouched` flattened to JSON so the export stays one row per invocation).
	 *
	 * @param array<int, array<string, mixed>> $rows The rows to export.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/agent-tool-governance/spec.md#requirement-per-agent-tool-invocation-oversight-surface-ai-act-art-12-14
	 */
	public function toCsv(array $rows): string {
		$handle = fopen('php://temp', 'r+');
		if ($handle === false) {
			return '';
		}

		// Explicit $escape: PHP 8.4 deprecates relying on the default (RFC 4180
		// knows no escape character — pass '' once the minimum PHP allows it).
		fputcsv($handle, ['at', 'toolId', 'actingUser', 'paramsDigest', 'resultSummary', 'dataTouched'], ',', '"', '\\');

		foreach ($rows as $row) {
			fputcsv($handle, $this->csvFields(row: $row), ',', '"', '\\');
		}

		rewind($handle);
		$csv = stream_get_contents($handle);
		fclose($handle);

		if ($csv === false) {
			return '';
		}

		return $csv;
	}//end toCsv()

	/**
	 * The agent's schedules: the schedule objects (one read, capped at 1000) whose agentId is the agent's.
	 *
	 * @param string $agentId The agent UUID.
	 *
	 * @return array<int, ObjectEntity> The schedules.
	 */
	private function agentSchedules(string $agentId): array {
		$schedules = $this->objectService
			->setRegister(self::REGISTER_SLUG)
			->setSchema(self::SCHEDULE_SCHEMA)
			->findAll(config: ['limit' => 1000]);

		$matching = [];
		foreach ($schedules as $schedule) {
			if (($schedule instanceof ObjectEntity) === true && (string)($schedule->getObject()['agentId'] ?? '') === $agentId) {
				$matching[] = $schedule;
			}
		}

		return $matching;
	}//end agentSchedules()

	/**
	 * A coarse run entry as an invocation row: when, who, and the run's status; no tool, digest or data.
	 *
	 * @param AuditTrail  $log        The audit trail entry.
	 * @param string|null $occurredAt When it happened (ISO-8601), or null.
	 *
	 * @return array<string, mixed> The row.
	 */
	private function degradedRow(AuditTrail $log, ?string $occurredAt): array {
		return [
			'callId' => (string)($log->getUuid() ?? ''),
			'at' => $occurredAt,
			'toolId' => null,
			'actingUser' => $log->getUser(),
			'paramsDigest' => null,
			'resultSummary' => ['status' => (($log->getChanged() ?? [])['status'] ?? 'unknown')],
			'dataTouched' => [],
		];
	}//end degradedRow()

	/**
	 * One row's CSV fields, in the header's order; the summary and data as JSON.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return array<int, string> The fields.
	 */
	private function csvFields(array $row): array {
		return [
			(string)($row['at'] ?? ''),
			(string)($row['toolId'] ?? ''),
			(string)($row['actingUser'] ?? ''),
			(string)($row['paramsDigest'] ?? ''),
			(string)json_encode($row['resultSummary'] ?? null),
			(string)json_encode($row['dataTouched'] ?? []),
		];
	}//end csvFields()
}//end class

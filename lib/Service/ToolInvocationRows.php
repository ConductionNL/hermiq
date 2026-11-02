<?php

/**
 * Hermiq ToolInvocationRows.
 *
 * The shape, time window and order of the rows the tool-invocation listing
 * returns, shared by its rich and its degraded source.
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
 * @link https://conduction.nl
 *
 * @spec openspec/specs/agent-tool-governance/spec.md#scenario-the-richer-invocation-audit-shape-is-not-yet-available
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service;

use OCA\OpenRegister\Db\AuditTrail;

/**
 * Row helpers for the tool-invocation listing.
 *
 * @spec openspec/specs/agent-tool-governance/spec.md#scenario-the-richer-invocation-audit-shape-is-not-yet-available
 */
final class ToolInvocationRows {

	/**
	 * One rich row.
	 *
	 * The audit entry never carries raw argument values (REQ-DERIVED-006: "never raw
	 * object payloads") — only a SHA-256 digest survives. Surfacing the digest is
	 * honest; inventing decoded field values is not.
	 *
	 * @param AuditTrail $log The MCP audit entry.
	 * @param string|null $occurredAt Its timestamp.
	 *
	 * @return array<string, mixed> The row.
	 */
	public function richRow(AuditTrail $log, ?string $occurredAt): array {
		return [
			'at' => $occurredAt,
			'toolId' => $log->getToolId(),
			'actingUser' => (string)($log->getUser() ?? ''),
			'paramsDigest' => $log->getParamsDigest(),
			'resultSummary' => $log->getResultSummary(),
			'dataTouched' => array_values(array_filter([$log->getObjectUuid()])),
		];
	}//end richRow()

	/**
	 * An entry's timestamp as ISO 8601, or null when it has none.
	 *
	 * @param AuditTrail $log The audit entry.
	 *
	 * @return string|null The timestamp.
	 */
	public function occurredAt(AuditTrail $log): ?string {
		return $log->getCreated()?->format('c');
	}//end occurredAt()

	/**
	 * Rows ordered newest first (ISO 8601 strings sort lexically).
	 *
	 * @param array<int, array<string, mixed>> $rows The rows.
	 *
	 * @return array<int, array<string, mixed>> The rows, newest first.
	 */
	public function newestFirst(array $rows): array {
		usort($rows, static fn (array $a, array $b): int => strcmp((string)$b['at'], (string)$a['at']));
		return $rows;
	}//end newestFirst()

	/**
	 * The `[from, to]` window from the request parameters; an empty parameter leaves that side open.
	 *
	 * @param string $from The lower bound parameter.
	 * @param string $to The upper bound parameter.
	 *
	 * @return array{from: ?string, to: ?string} The window.
	 */
	public function window(string $from, string $to): array {
		return ['from' => $this->bound(value: $from), 'to' => $this->bound(value: $to)];
	}//end window()

	/**
	 * Whether a row's timestamp falls within an optional `[from, to]` window
	 * (string comparison — both are ISO 8601, sortable lexically). A row without
	 * a timestamp is only in an open window.
	 *
	 * @param string|null $occurredAt The row's timestamp, or null.
	 * @param array{from: ?string, to: ?string} $window Optional bounds (inclusive).
	 *
	 * @return bool
	 */
	public function inWindow(?string $occurredAt, array $window): bool {
		if ($occurredAt === null) {
			return ($window['from'] === null && $window['to'] === null);
		}

		return ($occurredAt >= ($window['from'] ?? '') && $occurredAt <= ($window['to'] ?? $occurredAt));
	}//end inWindow()

	/**
	 * A window bound from a request parameter: null when it is empty.
	 *
	 * @param string $value The parameter.
	 *
	 * @return string|null The bound.
	 */
	private function bound(string $value): ?string {
		if ($value === '') {
			return null;
		}

		return $value;
	}//end bound()
}//end class

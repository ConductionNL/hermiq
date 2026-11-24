<?php

/**
 * Unit tests for RunAuditScope (analytics-unbounded-audit-scan).
 *
 * Covers the bounded run read: one search-bounded query per agent, none without an
 * agent, entries de-duplicated and returned newest first.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service
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
 * @spec openspec/changes/analytics-unbounded-audit-scan/tasks.md#2-tests-make-the-phantom-green-mock-assert-the-query-shape
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service;

use DateTime;
use OCA\Hermiq\Service\RunAuditScope;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapper;
use PHPUnit\Framework\TestCase;

/**
 * Tests for RunAuditScope.
 *
 * @spec openspec/changes/analytics-unbounded-audit-scan/tasks.md#2-tests-make-the-phantom-green-mock-assert-the-query-shape
 */
class RunAuditScopeTest extends TestCase {

	/**
	 * A run entry for one agent, written at a given time.
	 *
	 * @param string $uuid    The entry UUID.
	 * @param string $agentId The agent that ran.
	 * @param string $created When it was written.
	 *
	 * @return AuditTrail
	 */
	private function entry(string $uuid, string $agentId, string $created): AuditTrail {
		$log = new AuditTrail();
		$log->setUuid(uuid: $uuid);
		$log->setAction(action: 'run');
		$log->setChanged(changed: ['agentId' => $agentId]);
		$log->setCreated(created: new DateTime(datetime: $created));
		return $log;
	}//end entry()

	/**
	 * Each agent gets its own bounded query; the merged result is de-duplicated, newest first.
	 *
	 * @return void
	 */
	public function testOneBoundedQueryPerAgentMergedNewestFirst(): void {
		$old    = $this->entry(uuid: 'r-old', agentId: 'agentA', created: '2026-09-01 09:00:00');
		$new    = $this->entry(uuid: 'r-new', agentId: 'agentB', created: '2026-09-05 09:00:00');
		$shared = $this->entry(uuid: 'r-mid', agentId: 'agentA', created: '2026-09-03 09:00:00');

		$byAgent = ['agentA' => [$old, $shared], 'agentB' => [$new, $shared]];
		$calls   = new \ArrayObject();
		$mapper  = $this->createMock(originalClassName: AuditTrailMapper::class);
		$mapper->method('findAll')->willReturnCallback(
			static function (
				?int $limit=null,
				?int $offset=null,
				?array $filters=[],
				?array $sort=null,
				?string $search=null
			) use (
				$byAgent,
				$calls
			): array {
				$calls->append([$limit, ($filters['action'] ?? null), $search]);
				return ($byAgent[(string) $search] ?? []);
			}
		);

		$logs = (new RunAuditScope(auditTrailMapper: $mapper))->logsOf(agentIds: ['agentA', 'agentB', ' ']);

		$this->assertSame(
			expected: [[null, RunAuditScope::RUN_ACTIONS, 'agentA'], [null, RunAuditScope::RUN_ACTIONS, 'agentB']],
			actual: $calls->getArrayCopy(),
			message: 'One query per agent, bounded by its UUID and never by a limit; a blank id is skipped.'
		);
		$this->assertSame(
			expected: ['r-new', 'r-mid', 'r-old'],
			actual: array_map(static fn (AuditTrail $log): ?string => $log->getUuid(), $logs)
		);
	}//end testOneBoundedQueryPerAgentMergedNewestFirst()

	/**
	 * Without an agent there is nothing the caller may see, so nothing is read.
	 *
	 * @return void
	 */
	public function testNoAgentsMeansNoQuery(): void {
		$mapper = $this->createMock(originalClassName: AuditTrailMapper::class);
		$mapper->expects($this->never())->method('findAll');

		$this->assertSame(expected: [], actual: (new RunAuditScope(auditTrailMapper: $mapper))->logsOf(agentIds: []));
	}//end testNoAgentsMeansNoQuery()
}//end class

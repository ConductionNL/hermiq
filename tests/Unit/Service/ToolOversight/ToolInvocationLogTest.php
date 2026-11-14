<?php

/**
 * ToolInvocationLog unit tests: whose MCP calls count for an agent, the
 * degraded run log, and the CSV export.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\ToolOversight;

use DateTime;
use OCA\Hermiq\Service\ToolInvocationRows;
use OCA\Hermiq\Service\ToolOversight\ToolInvocationLog;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The invocation log over a mocked object store and audit trail.
 */
#[CoversClass(ToolInvocationLog::class)]
#[UsesClass(ToolInvocationRows::class)]
class ToolInvocationLogTest extends TestCase {

	private ObjectService&MockObject $objects;

	private AuditTrailMapper&MockObject $audit;

	/**
	 * @return void
	 */
	protected function setUp(): void {
		$this->objects = $this->createMock(ObjectService::class);
		$this->objects->method('setRegister')->willReturnSelf();
		$this->objects->method('setSchema')->willReturnSelf();
		$this->audit = $this->createMock(AuditTrailMapper::class);
	}//end setUp()

	/**
	 * An object entity.
	 *
	 * @param string               $uuid    Its uuid.
	 * @param string|null          $owner   Its owner.
	 * @param array<string, mixed> $payload Its data.
	 *
	 * @return ObjectEntity
	 */
	private static function entity(string $uuid, ?string $owner, array $payload = []): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setOwner($owner);
		$entity->setObject($payload);
		return $entity;
	}//end entity()

	/**
	 * An audit entry.
	 *
	 * @param string                    $user    Acting user.
	 * @param string                    $at      When.
	 * @param array<string, mixed>      $fields  Extra setters by name.
	 *
	 * @return AuditTrail
	 */
	private static function entry(string $user, string $at, array $fields = []): AuditTrail {
		$entry = new AuditTrail();
		$entry->setUser($user);
		$entry->setCreated(new DateTime($at));
		foreach ($fields as $name => $value) {
			$entry->{'set' . ucfirst($name)}($value);
		}

		return $entry;
	}//end entry()

	/**
	 * The service under test.
	 *
	 * @return ToolInvocationLog
	 */
	private function log(): ToolInvocationLog {
		return new ToolInvocationLog($this->objects, $this->audit);
	}//end log()

	/**
	 * The agent's owner and the owners of its own schedules count, once each; other agents' schedules do not.
	 *
	 * @return void
	 */
	public function testTheOwnersAreTheAgentsAndItsSchedules(): void {
		$this->objects->method('findAll')->with(['limit' => 1000])->willReturn([
			self::entity('s-1', 'bob', ['agentId' => 'agent-1']),
			self::entity('s-2', 'alice', ['agentId' => 'agent-1']),
			self::entity('s-3', 'carol', ['agentId' => 'agent-2']),
			self::entity('s-4', null, ['agentId' => 'agent-1']),
			['agentId' => 'agent-1', 'owner' => 'mallory'],
		]);

		$owners = $this->log()->correlatedOwnerUids(agentId: 'agent-1', agent: self::entity('agent-1', 'alice'));

		$this->assertSame(['alice', 'bob'], $owners);
	}//end testTheOwnersAreTheAgentsAndItsSchedules()

	/**
	 * Without owners nothing is read from the audit trail.
	 *
	 * @return void
	 */
	public function testWithoutOwnersTheAuditIsNotRead(): void {
		$this->audit->expects($this->never())->method('findAll');

		$this->assertSame([], $this->log()->richRows(ownerUids: [], window: ['from' => null, 'to' => null]));
	}//end testWithoutOwnersTheAuditIsNotRead()

	/**
	 * MCP calls of the owners inside the window come back newest first; others are left out.
	 *
	 * @return void
	 */
	public function testRichRowsAreTheOwnersCallsInTheWindowNewestFirst(): void {
		$this->audit->expects($this->once())->method('findAll')
			->with($this->anything(), $this->anything(), ['action' => 'mcp.search,mcp.get,mcp.create,mcp.update,mcp.delete'])
			->willReturn([
				self::entry('alice', '2026-11-02T10:00:00+00:00', ['toolId' => 'hermiq.x.get', 'objectUuid' => 'o-1']),
				self::entry('mallory', '2026-11-03T10:00:00+00:00', ['toolId' => 'hermiq.x.get']),
				self::entry('bob', '2026-11-05T10:00:00+00:00', ['toolId' => 'hermiq.x.update', 'objectUuid' => 'o-2']),
				self::entry('alice', '2026-10-01T10:00:00+00:00', ['toolId' => 'hermiq.x.delete']),
			]);

		$rows = $this->log()->richRows(
			ownerUids: ['alice', 'bob'],
			window: ['from' => '2026-11-01T00:00:00+00:00', 'to' => '2026-11-30T23:59:59+00:00']
		);

		$this->assertSame(['hermiq.x.update', 'hermiq.x.get'], array_column($rows, 'toolId'));
		$this->assertSame(['bob', 'alice'], array_column($rows, 'actingUser'));
		$this->assertSame([['o-2'], ['o-1']], array_column($rows, 'dataTouched'));
	}//end testRichRowsAreTheOwnersCallsInTheWindowNewestFirst()

	/**
	 * Without schedules the degraded log is empty and the audit trail is not read.
	 *
	 * @return void
	 */
	public function testWithoutSchedulesTheDegradedLogIsEmpty(): void {
		$this->objects->method('findAll')->willReturn([self::entity('s-3', 'carol', ['agentId' => 'agent-2'])]);
		$this->audit->expects($this->never())->method('findAll');

		$this->assertSame([], $this->log()->degradedRows(agentId: 'agent-1', window: ['from' => null, 'to' => null]));
	}//end testWithoutSchedulesTheDegradedLogIsEmpty()

	/**
	 * The degraded log lists the agent's schedule runs with their status, and nothing else.
	 *
	 * @return void
	 */
	public function testTheDegradedLogListsTheSchedulesRuns(): void {
		$this->objects->method('findAll')->willReturn([self::entity('s-1', 'bob', ['agentId' => 'agent-1'])]);
		$this->audit->expects($this->once())->method('findAll')->with($this->anything(), $this->anything(), ['action' => 'run'])->willReturn([
			self::entry('bob', '2026-11-02T10:00:00+00:00', ['objectUuid' => 's-1', 'changed' => ['status' => 'ok']]),
			self::entry('bob', '2026-11-04T10:00:00+00:00', ['objectUuid' => 's-1']),
			self::entry('carol', '2026-11-03T10:00:00+00:00', ['objectUuid' => 's-9', 'changed' => ['status' => 'ok']]),
		]);

		$rows = $this->log()->degradedRows(agentId: 'agent-1', window: ['from' => null, 'to' => null]);

		$this->assertSame(
			[
				['at' => '2026-11-04T10:00:00+00:00', 'toolId' => null, 'actingUser' => 'bob', 'paramsDigest' => null, 'resultSummary' => ['status' => 'unknown'], 'dataTouched' => []],
				['at' => '2026-11-02T10:00:00+00:00', 'toolId' => null, 'actingUser' => 'bob', 'paramsDigest' => null, 'resultSummary' => ['status' => 'ok'], 'dataTouched' => []],
			],
			$rows
		);
	}//end testTheDegradedLogListsTheSchedulesRuns()

	/**
	 * The CSV has a header, one line per row, and JSON for the structured columns.
	 *
	 * @return void
	 */
	public function testTheCsvCarriesAHeaderAndOneLinePerRow(): void {
		$csv = $this->log()->toCsv(rows: [
			['at' => '2026-11-02T10:00:00+00:00', 'toolId' => 'hermiq.x.get', 'actingUser' => 'alice', 'paramsDigest' => 'ab, cd', 'resultSummary' => ['isError' => false], 'dataTouched' => ['o-1']],
			['at' => null, 'toolId' => null],
		]);

		$this->assertSame(
			"at,toolId,actingUser,paramsDigest,resultSummary,dataTouched\n"
			. "2026-11-02T10:00:00+00:00,hermiq.x.get,alice,\"ab, cd\",\"{\"\"isError\"\":false}\",\"[\"\"o-1\"\"]\"\n"
			. ",,,,null,[]\n",
			$csv
		);
	}//end testTheCsvCarriesAHeaderAndOneLinePerRow()
}//end class

<?php

/**
 * Unit tests for AuditTrailVersionHistory: the version list read from the
 * audit trail, the field diff between two states and the version record.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service;

use DateTime;
use OCA\Hermiq\Service\AuditTrailVersionHistory;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapper;
use PHPUnit\Framework\TestCase;

/**
 * The version history an agent or skill shows, built from audit-trail entries.
 *
 * @covers \OCA\Hermiq\Service\AuditTrailVersionHistory
 */
class AuditTrailVersionHistoryTest extends TestCase {

	/**
	 * An audit-trail entry.
	 *
	 * @param string      $uuid    The entry id.
	 * @param string      $action  The action.
	 * @param string|null $created When it was written, or null.
	 * @param array|null  $changed The changed fields.
	 *
	 * @return AuditTrail
	 */
	private function entry(string $uuid, string $action, ?string $created, ?array $changed = null): AuditTrail {
		$entry = new AuditTrail();
		$entry->setUuid($uuid);
		$entry->setAction($action);
		$entry->setUser('ada');
		$entry->setChanged($changed);
		$entry->setCreated($created === null ? null : new DateTime($created));
		return $entry;

	}//end entry()

	/**
	 * The history over a mapper answering the given entries.
	 *
	 * @param array $logs  The entries the mapper answers.
	 * @param array $calls Collects the filters the mapper was asked for.
	 *
	 * @return AuditTrailVersionHistory
	 */
	private function history(array $logs = [], array &$calls = []): AuditTrailVersionHistory {
		$mapper = $this->createMock(AuditTrailMapper::class);
		$mapper->method('findAll')->willReturnCallback(
			static function (?int $limit = null, ?int $offset = null, ?array $filters = []) use ($logs, &$calls): array {
				$calls[] = $filters;
				return $logs;
			}
		);
		return new AuditTrailVersionHistory(auditTrailMapper: $mapper, versionedFields: ['name', 'prompt', 'model']);

	}//end history()

	/**
	 * Only create and update entries of the object are versions, newest first; an undated one sorts last.
	 *
	 * @return void
	 */
	public function testTheVersionsAreTheObjectsCreatesAndUpdatesNewestFirst(): void {
		$calls = [];
		$history = $this->history(
			logs: [
				$this->entry('v1', 'create', '2026-01-01T10:00:00Z'),
				$this->entry('read', 'read', '2026-03-01T10:00:00Z'),
				$this->entry('v3', 'update', '2026-02-01T10:00:00Z'),
				$this->entry('undated', 'update', null),
				$this->entry('del', 'delete', '2026-04-01T10:00:00Z'),
				$this->entry('v2', 'update', '2026-01-15T10:00:00Z'),
			],
			calls: $calls
		);

		$entries = $history->entriesFor(objectUuid: 'agent-1');

		self::assertSame(['v3', 'v2', 'v1', 'undated'], array_map(static fn (AuditTrail $e): ?string => $e->getUuid(), $entries));
		self::assertSame([['object_uuid' => 'agent-1', 'action' => 'create,update']], $calls);

	}//end testTheVersionsAreTheObjectsCreatesAndUpdatesNewestFirst()

	/**
	 * A version is found by its id, and an unknown id is null.
	 *
	 * @return void
	 */
	public function testAVersionIsFoundByItsId(): void {
		$entries = [$this->entry('v3', 'update', null), $this->entry('v2', 'update', null)];

		self::assertSame(1, $this->history()->indexOf(entries: $entries, versionId: 'v2'));
		self::assertNull($this->history()->indexOf(entries: $entries, versionId: 'v9'));

	}//end testAVersionIsFoundByItsId()

	/**
	 * Only versioned fields that differ are reported, with old and new; a missing field reads as null.
	 *
	 * @return void
	 */
	public function testOnlyChangedVersionedFieldsAreReported(): void {
		$changed = $this->history()->changedBetween(
			fromState: ['name' => 'Planner', 'prompt' => 'Plan.', 'model' => 'm-1', 'colour' => 'red'],
			toState: ['name' => 'Planner', 'prompt' => 'Plan well.', 'colour' => 'blue']
		);

		self::assertSame(
			[
				'prompt' => ['old' => 'Plan.', 'new' => 'Plan well.'],
				'model' => ['old' => 'm-1', 'new' => null],
			],
			$changed
		);

	}//end testOnlyChangedVersionedFieldsAreReported()

	/**
	 * The diff compares strictly, so a number and its string form differ.
	 *
	 * @return void
	 */
	public function testTheDiffComparesStrictly(): void {
		self::assertSame(
			['model' => ['old' => 1, 'new' => '1']],
			$this->history()->changedBetween(fromState: ['model' => 1], toState: ['model' => '1'])
		);

	}//end testTheDiffComparesStrictly()

	/**
	 * The record carries id, ISO timestamp, user, action and only the versioned fields that changed.
	 *
	 * @return void
	 */
	public function testTheVersionRecordNamesOnlyVersionedChangedFields(): void {
		$record = $this->history()->toVersionRecord(
			entry: $this->entry('v2', 'update', '2026-01-15T10:00:00+00:00', ['colour' => [], 'prompt' => [], 'name' => []])
		);

		self::assertSame(
			['id' => 'v2', 'timestamp' => '2026-01-15T10:00:00+00:00', 'user' => 'ada', 'action' => 'update', 'changedFields' => ['prompt', 'name']],
			$record
		);

	}//end testTheVersionRecordNamesOnlyVersionedChangedFields()

	/**
	 * An entry without a date or changes gives a null timestamp and no changed fields.
	 *
	 * @return void
	 */
	public function testAnUndatedEntryWithoutChanges(): void {
		$record = $this->history()->toVersionRecord(entry: $this->entry('v1', 'create', null));

		self::assertNull($record['timestamp']);
		self::assertSame([], $record['changedFields']);

	}//end testAnUndatedEntryWithoutChanges()
}//end class

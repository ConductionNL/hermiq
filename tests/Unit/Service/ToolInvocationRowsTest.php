<?php

/**
 * Unit tests for ToolInvocationRows (tool-oversight invocation log row shaping).
 *
 * Covers: the rich row built from an AuditTrail entry, the ISO occurrence
 * timestamp, newest-first ordering, the from/to window with empty bounds as
 * open, and the window membership rule including undated rows.
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
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service;

use DateTime;
use DateTimeZone;
use OCA\Hermiq\Service\ToolInvocationRows;
use OCA\OpenRegister\Db\AuditTrail;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * ToolInvocationRows behaviour tests.
 */
#[CoversClass(ToolInvocationRows::class)]
class ToolInvocationRowsTest extends TestCase {

	/**
	 * An AuditTrail entry with the tool-invocation fields filled.
	 *
	 * @param string|null $objectUuid The touched object, or null.
	 * @param string|null $user       The acting user, or null.
	 *
	 * @return AuditTrail
	 */
	private function log(?string $objectUuid, ?string $user): AuditTrail {
		$log = new AuditTrail();
		$log->setToolId('openregister.objects.save');
		$log->setUser($user);
		$log->setParamsDigest('sha256:abc');
		$log->setResultSummary(['status' => 'ok']);
		$log->setObjectUuid($objectUuid);

		return $log;

	}//end log()

	/**
	 * The rich row carries the tool, actor, digest, summary and touched object.
	 *
	 * @return void
	 */
	public function testRichRowShapesTheAuditEntry(): void {
		$row = (new ToolInvocationRows())->richRow(log: $this->log('obj-1', 'alice'), occurredAt: '2026-11-01T10:00:00+00:00');

		$this->assertSame(
			[
				'at' => '2026-11-01T10:00:00+00:00',
				'toolId' => 'openregister.objects.save',
				'actingUser' => 'alice',
				'paramsDigest' => 'sha256:abc',
				'resultSummary' => ['status' => 'ok'],
				'dataTouched' => ['obj-1'],
			],
			$row
		);

	}//end testRichRowShapesTheAuditEntry()

	/**
	 * A missing user becomes '' and a missing object an empty touched list.
	 *
	 * @return void
	 */
	public function testRichRowDefaultsMissingUserAndObject(): void {
		$row = (new ToolInvocationRows())->richRow(log: $this->log(null, null), occurredAt: null);

		$this->assertNull($row['at']);
		$this->assertSame('', $row['actingUser']);
		$this->assertSame([], $row['dataTouched']);

	}//end testRichRowDefaultsMissingUserAndObject()

	/**
	 * The occurrence time is the entry's created stamp in ISO-8601, or null.
	 *
	 * @return void
	 */
	public function testOccurredAtFormatsTheCreatedStamp(): void {
		$rows = new ToolInvocationRows();
		$log  = $this->log('obj-1', 'alice');

		$this->assertNull($rows->occurredAt(log: $log));

		$log->setCreated(new DateTime('2026-11-01 10:00:00', new DateTimeZone('UTC')));
		$this->assertSame('2026-11-01T10:00:00+00:00', $rows->occurredAt(log: $log));

	}//end testOccurredAtFormatsTheCreatedStamp()

	/**
	 * Rows sort newest first by their `at` stamp.
	 *
	 * @return void
	 */
	public function testNewestFirstOrdersByTimestampDescending(): void {
		$sorted = (new ToolInvocationRows())->newestFirst(
			rows: [
				['at' => '2026-11-01T10:00:00+00:00', 'id' => 'b'],
				['at' => '2026-11-03T10:00:00+00:00', 'id' => 'c'],
				['at' => null, 'id' => 'z'],
				['at' => '2026-10-01T10:00:00+00:00', 'id' => 'a'],
			]
		);

		$this->assertSame(['c', 'b', 'a', 'z'], array_column($sorted, 'id'));

	}//end testNewestFirstOrdersByTimestampDescending()

	/**
	 * Empty bounds become open (null) bounds.
	 *
	 * @return void
	 */
	public function testWindowTreatsEmptyBoundsAsOpen(): void {
		$rows = new ToolInvocationRows();

		$this->assertSame(['from' => null, 'to' => null], $rows->window(from: '', to: ''));
		$this->assertSame(
			['from' => '2026-11-01', 'to' => null],
			$rows->window(from: '2026-11-01', to: '')
		);

	}//end testWindowTreatsEmptyBoundsAsOpen()

	/**
	 * Membership is inclusive on both bounds; an undated row only fits an open window.
	 *
	 * @return void
	 */
	public function testInWindowIsInclusiveAndHandlesUndatedRows(): void {
		$rows   = new ToolInvocationRows();
		$closed = $rows->window(from: '2026-11-01', to: '2026-11-30');
		$open   = $rows->window(from: '', to: '');

		$this->assertTrue($rows->inWindow(occurredAt: '2026-11-01', window: $closed));
		$this->assertTrue($rows->inWindow(occurredAt: '2026-11-30', window: $closed));
		$this->assertFalse($rows->inWindow(occurredAt: '2026-10-31', window: $closed));
		$this->assertFalse($rows->inWindow(occurredAt: '2026-12-01', window: $closed));
		$this->assertTrue($rows->inWindow(occurredAt: '2020-01-01', window: $open));
		$this->assertTrue($rows->inWindow(occurredAt: null, window: $open));
		$this->assertFalse($rows->inWindow(occurredAt: null, window: $closed));
		$this->assertFalse($rows->inWindow(occurredAt: null, window: $rows->window(from: '2026-11-01', to: '')));

	}//end testInWindowIsInclusiveAndHandlesUndatedRows()
}//end class

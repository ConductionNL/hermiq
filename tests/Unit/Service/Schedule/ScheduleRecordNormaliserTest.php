<?php

/**
 * Unit tests for ScheduleRecordNormaliser.
 *
 * The full golden over every branch runs through ScheduleService (its
 * persist path delegates here); these pin the class's own public surface.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\Schedule
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/2026-09-06-agent-schedule-dispatcher/tasks.md#3-scheduleservice-dispatch-logic
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Schedule;

use OCA\Hermiq\Service\Schedule\ScheduleRecordNormaliser;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ScheduleRecordNormaliser.
 */
class ScheduleRecordNormaliserTest extends TestCase {

	/**
	 * A round-tripped record comes back in the shape the schema accepts.
	 *
	 * @return void
	 */
	public function testSanitizeForSaveRepairsRoundTripArtifacts(): void {
		$record = [
			'nextRun' => '2026-03-01 10:00:00',
			'repeat' => ['times' => 0, 'completed' => 0],
			'retryState' => [],
			'prompt' => 'p',
		];

		$this->assertSame(
			[
				'nextRun' => '2026-03-01T10:00:00+00:00',
				'repeat' => null,
				'retryState' => null,
				'prompt' => 'p',
			],
			(new ScheduleRecordNormaliser())->sanitizeForSave(data: $record)
		);
	}//end testSanitizeForSaveRepairsRoundTripArtifacts()

	/**
	 * The readers answer null for what they cannot read.
	 *
	 * @return void
	 */
	public function testReadersAnswerNullForUnreadableValues(): void {
		$normaliser = new ScheduleRecordNormaliser();

		$this->assertNull($normaliser->parseDate(value: ''));
		$this->assertNull($normaliser->normaliseRetryState(raw: ['attempt' => 1, 'nextAttemptAt' => null]));
		$this->assertSame(
			['attempt' => 1, 'nextAttemptAt' => '2026-03-01'],
			$normaliser->normaliseRetryState(raw: ['attempt' => 1.0, 'nextAttemptAt' => '2026-03-01'])
		);
	}//end testReadersAnswerNullForUnreadableValues()
}//end class

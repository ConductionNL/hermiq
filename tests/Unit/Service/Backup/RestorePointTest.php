<?php

/**
 * Unit tests for RestorePoint.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\Backup
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
 * @spec openspec/changes/operations-backup-and-restore/specs/backup-restore/spec.md#requirement-a-restore-starts-with-a-preview-of-what-will-change-req-bkup-002
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Backup;

use InvalidArgumentException;
use OCA\Hermiq\Service\Backup\RestorePoint;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A restore point is a backup file or a moment, read from a request.
 *
 * @covers \OCA\Hermiq\Service\Backup\RestorePoint
 * @uses   \OCA\Hermiq\Service\Backup\BackupFiles
 */
final class RestorePointTest extends TestCase {

	/**
	 * A backup point keeps the file and the choice for later objects.
	 *
	 * @return void
	 */
	public function testABackupPointNamesItsFile(): void {
		$point = RestorePoint::fromArray(values: ['kind' => 'backup', 'file' => 'hermiq-backup-tilburg-2026-09-28-0200.json.gz', 'removeCreatedAfter' => 'true']);

		$this->assertSame('backup', $point->kind);
		$this->assertSame('hermiq-backup-tilburg-2026-09-28-0200.json.gz', $point->file);
		$this->assertTrue($point->removeCreatedAfter);
		$this->assertNull($point->at);
	}//end testABackupPointNamesItsFile()

	/**
	 * A moment is read in UTC and survives the round trip through a job argument.
	 *
	 * @return void
	 */
	public function testAMomentRoundTripsInUtc(): void {
		$point = RestorePoint::fromArray(values: ['kind' => 'moment', 'organisation' => 'tilburg', 'at' => '2026-09-28T04:00:00+02:00']);

		$this->assertSame('2026-09-28T02:00:00+00:00', $point->toArray()['at']);
		$this->assertFalse($point->removeCreatedAfter);
		$this->assertEquals($point, RestorePoint::fromArray(values: $point->toArray()));
		$this->assertSame('breda', $point->withOrganisation(organisation: 'breda')->organisation);
	}//end testAMomentRoundTripsInUtc()

	/**
	 * What a request can get wrong.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: string}>
	 */
	public static function refusals(): array {
		return [
			'no kind'            => [[], 'a backup file or a moment'],
			'a path as file'     => [['kind' => 'backup', 'file' => '../secret.json.gz'], 'Pick a backup file'],
			'a moment, no org'   => [['kind' => 'moment', 'at' => '2026-09-28T02:00:00Z'], 'Name the organisation'],
			'a moment, no time'  => [['kind' => 'moment', 'organisation' => 'tilburg'], 'Choose the moment'],
			'a moment, bad time' => [['kind' => 'moment', 'organisation' => 'tilburg', 'at' => 'yesterday-ish'], 'not a date and time'],
		];
	}//end refusals()

	/**
	 * Each is refused with a sentence naming what is wrong.
	 *
	 * @param array<string, mixed> $values  The request.
	 * @param string               $message Part of the refusal.
	 *
	 * @return void
	 */
	#[DataProvider('refusals')]
	public function testAPointThatCannotBeUnderstoodIsRefused(array $values, string $message): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage($message);

		RestorePoint::fromArray(values: $values);
	}//end testAPointThatCannotBeUnderstoodIsRefused()
}//end class

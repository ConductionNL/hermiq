<?php

/**
 * Pins which queued arguments TalkTurnJob hands to TalkTurnService: only a
 * map with all four turn fields non-empty, passed as strings by name; every
 * other shape is dropped.
 *
 * @category Tests
 * @package  OCA\Hermiq\Tests\Unit\BackgroundJob
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

namespace OCA\Hermiq\Tests\Unit\BackgroundJob;

use OCA\Hermiq\BackgroundJob\TalkTurnJob;
use OCA\Hermiq\Service\Talk\TalkTurnService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * The queued Talk turn, argument shape by argument shape.
 *
 * @covers \OCA\Hermiq\BackgroundJob\TalkTurnJob
 */
final class TalkTurnJobTest extends TestCase {

	/**
	 * A complete turn.
	 *
	 * @var array<string, string>
	 */
	private const TURN = [
		'conversationUuid' => 'conv-1',
		'speakerUid' => 'alice',
		'message' => 'Hallo',
		'roomToken' => 'room-1',
	];

	/**
	 * Run the job with an argument and return the turns the service received.
	 *
	 * @param mixed $argument The queued argument.
	 *
	 * @return array<int, array<int, mixed>> The runTurn calls.
	 */
	private function runJob(mixed $argument): array {
		$calls   = [];
		$service = $this->createMock(TalkTurnService::class);
		$service->method('runTurn')->willReturnCallback(
			static function (string $conversationUuid, string $speakerUid, string $message, string $roomToken) use (&$calls): bool {
				$calls[] = [$conversationUuid, $speakerUid, $message, $roomToken];
				return true;
			}
		);

		$job = new TalkTurnJob($this->createMock(ITimeFactory::class), $service);
		(new ReflectionMethod(TalkTurnJob::class, 'run'))->invoke($job, $argument);

		return $calls;
	}//end runJob()

	/**
	 * Arguments that are not a map never reach the service.
	 *
	 * @return void
	 */
	public function testANonArrayArgumentIsDropped(): void {
		foreach ([null, '', 'conv-1', 42, false] as $argument) {
			$this->assertSame([], $this->runJob($argument), var_export($argument, true));
		}
	}//end testANonArrayArgumentIsDropped()

	/**
	 * A turn missing any one field, or with it empty or null, never reaches the service.
	 *
	 * @return void
	 */
	public function testATurnWithAMissingOrEmptyFieldIsDropped(): void {
		foreach (array_keys(self::TURN) as $field) {
			$missing = self::TURN;
			unset($missing[$field]);
			$this->assertSame([], $this->runJob($missing), $field.' missing');

			foreach (['', null] as $blank) {
				$this->assertSame([], $this->runJob(array_merge(self::TURN, [$field => $blank])), $field.' blank');
			}
		}
	}//end testATurnWithAMissingOrEmptyFieldIsDropped()

	/**
	 * A complete turn is handed on once, field by field, with extra keys
	 * ignored and scalar values passed as strings.
	 *
	 * @return void
	 */
	public function testACompleteTurnIsHandedOnAsStrings(): void {
		$this->assertSame(
			[['conv-1', 'alice', 'Hallo', 'room-1']],
			$this->runJob(['roomToken' => 'room-1', 'message' => 'Hallo', 'speakerUid' => 'alice', 'conversationUuid' => 'conv-1', 'extra' => 'ignored'])
		);

		$this->assertSame(
			[['7', 'alice', '0', '42']],
			$this->runJob(['conversationUuid' => 7, 'speakerUid' => 'alice', 'message' => '0', 'roomToken' => 42])
		);
	}//end testACompleteTurnIsHandedOnAsStrings()
}//end class

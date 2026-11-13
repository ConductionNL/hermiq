<?php

/**
 * OwnedSchedule: a schedule is the caller's only when it loads and the caller owns it.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\Schedule
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

namespace OCA\Hermiq\Tests\Unit\Service\Schedule;

use OCA\Hermiq\Service\Schedule\OwnedSchedule;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Hermiq\Service\Schedule\OwnedSchedule
 */
final class OwnedScheduleTest extends TestCase {

	private function owned(mixed $found): OwnedSchedule {
		$objects = $this->createMock(ObjectService::class);
		$call = $objects->method('find')->with('s-1', [], false, 'hermiq', 'schedule');
		if ($found instanceof \Throwable) {
			$call->willThrowException($found);
		} else {
			$call->willReturn($found);
		}

		return new OwnedSchedule($objects, $this->createMock(LoggerInterface::class));
	}

	private static function schedule(?string $owner): ObjectEntity {
		$schedule = new ObjectEntity();
		$schedule->setOwner($owner);
		return $schedule;
	}

	public function testTheOwnerGetsTheSchedule(): void {
		$schedule = self::schedule('alice');

		$this->assertSame($schedule, $this->owned($schedule)->load('s-1', 'alice'));
	}

	public function testAnotherUsersOrAnUnownedScheduleIsNotTheCallers(): void {
		$this->assertNull($this->owned(self::schedule('bob'))->load('s-1', 'alice'));
		$this->assertNull($this->owned(self::schedule(null))->load('s-1', 'alice'));
	}

	public function testAScheduleThatDoesNotLoadIsNotTheCallers(): void {
		$this->assertNull($this->owned(null)->load('s-1', 'alice'));
		$this->assertNull($this->owned(new RuntimeException('absent'))->load('s-1', 'alice'));
	}
}

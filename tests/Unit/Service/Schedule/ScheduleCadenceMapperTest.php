<?php

/**
 * Pins the cadence mapper's shape map, case by case, before its split.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\Schedule
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Schedule;

use OCA\Hermiq\Service\Schedule\ScheduleCadenceMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\IConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Hermiq\Service\Schedule\ScheduleCadenceMapper
 */
class ScheduleCadenceMapperTest extends TestCase {

	/**
	 * A mapper whose owner lives in the server's timezone, or in another one.
	 *
	 * @param boolean $sameZone Whether the owner's zone equals the server default.
	 *
	 * @return ScheduleCadenceMapper The mapper.
	 */
	private function mapper(bool $sameZone): ScheduleCadenceMapper {
		$zone = date_default_timezone_get();
		if ($sameZone === false) {
			$zone = ($zone === 'Pacific/Kiritimati') ? 'America/Adak' : 'Pacific/Kiritimati';
		}

		$config = $this->createMock(IConfig::class);
		$config->method('getUserValue')->willReturn($zone);
		$config->method('getSystemValueString')->willReturn(date_default_timezone_get());

		return new ScheduleCadenceMapper($config);
	}//end mapper()

	/**
	 * A schedule entity with the given payload.
	 *
	 * @param array $payload The schedule payload.
	 *
	 * @return ObjectEntity The schedule.
	 */
	private function schedule(array $payload): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setOwner('alice');
		$entity->setObject($payload);

		return $entity;
	}//end schedule()

	/**
	 * Interval and cron shapes with the cron each maps to, in the same zone and in another.
	 *
	 * @return array<string, array{0: array, 1: ?string, 2: ?string}>
	 */
	public static function shapes(): array {
		return [
			'interval 0' => [['kind' => 'interval', 'intervalMinutes' => 0], null, null],
			'interval 1' => [['kind' => 'interval', 'intervalMinutes' => 1], '* * * * *', '* * * * *'],
			'interval 7' => [['kind' => 'interval', 'intervalMinutes' => 7], null, null],
			'interval 15' => [['kind' => 'interval', 'intervalMinutes' => 15], '*/15 * * * *', '*/15 * * * *'],
			'interval 59' => [['kind' => 'interval', 'intervalMinutes' => 59], null, null],
			'interval 60' => [['kind' => 'interval', 'intervalMinutes' => 60], '0 * * * *', '0 * * * *'],
			'interval 90' => [['kind' => 'interval', 'intervalMinutes' => 90], null, null],
			'interval 180' => [['kind' => 'interval', 'intervalMinutes' => 180], '0 */3 * * *', '0 */3 * * *'],
			'interval 300' => [['kind' => 'interval', 'intervalMinutes' => 300], null, null],
			'interval 1440' => [['kind' => 'interval', 'intervalMinutes' => 1440], null, null],
			'interval negative' => [['kind' => 'interval', 'intervalMinutes' => -5], null, null],
			'cron every minute' => [['kind' => 'cron', 'cronExpr' => ' * * * * * '], '* * * * *', '* * * * *'],
			'cron minute cadence' => [['kind' => 'cron', 'cronExpr' => '*/10 * * * *'], '*/10 * * * *', '*/10 * * * *'],
			'cron fixed minute' => [['kind' => 'cron', 'cronExpr' => '5 * * * *'], '5 * * * *', null],
			'cron hour anchored' => [['kind' => 'cron', 'cronExpr' => '*/5 9 * * *'], '*/5 9 * * *', null],
			'cron day field' => [['kind' => 'cron', 'cronExpr' => '* * 1 * *'], '* * 1 * *', null],
			'cron month field' => [['kind' => 'cron', 'cronExpr' => '* * * 2 *'], '* * * 2 *', null],
			'cron weekday field' => [['kind' => 'cron', 'cronExpr' => '* * * * 1'], '* * * * 1', null],
			'cron six fields' => [['kind' => 'cron', 'cronExpr' => '0 0 8 * * *'], null, null],
			'cron empty' => [['kind' => 'cron'], null, null],
			'once' => [['kind' => 'once'], null, null],
			'no kind' => [[], null, null],
		];
	}//end shapes()

	/**
	 * Each shape maps to its cron, or stays local.
	 *
	 * @param array       $payload   The schedule payload.
	 * @param string|null $sameZone  The cron when the owner shares the server's zone.
	 * @param string|null $otherZone The cron when the owner is in another zone.
	 *
	 * @return void
	 */
	#[DataProvider('shapes')]
	public function testEachShapeMapsToItsCron(array $payload, ?string $sameZone, ?string $otherZone): void {
		$this->assertSame($sameZone, $this->mapper(sameZone: true)->mappedCron($this->schedule($payload)));
		$this->assertSame($otherZone, $this->mapper(sameZone: false)->mappedCron($this->schedule($payload)));
	}//end testEachShapeMapsToItsCron()
}//end class

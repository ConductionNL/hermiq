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
	 * @param boolean     $sameZone         Whether the owner's zone equals the server default.
	 * @param boolean     $engineReadsZones Whether the pinned engine reads the trigger's `timezone` key.
	 * @param string|null $zone             An explicit owner zone, overriding $sameZone.
	 *
	 * @return ScheduleCadenceMapper The mapper.
	 */
	private function mapper(bool $sameZone, bool $engineReadsZones = false, ?string $zone = null): ScheduleCadenceMapper {
		if ($zone === null) {
			$zone = date_default_timezone_get();
			if ($sameZone === false) {
				$zone = $this->otherZone();
			}
		}

		$config = $this->createMock(IConfig::class);
		$config->method('getUserValue')->willReturn($zone);
		$config->method('getSystemValueString')->willReturn(date_default_timezone_get());

		return new class($config, $engineReadsZones) extends ScheduleCadenceMapper {
			/**
			 * @param IConfig $config           The config mock.
			 * @param boolean $engineReadsZones The pinned engine answer.
			 */
			public function __construct(IConfig $config, private readonly bool $engineReadsZones) {
				parent::__construct($config);
			}

			/**
			 * @return boolean The pinned engine answer.
			 */
			protected function engineReadsTimezone(): bool {
				return $this->engineReadsZones;
			}
		};
	}//end mapper()

	/**
	 * A valid zone that is not the server default.
	 *
	 * @return string The zone.
	 */
	private function otherZone(): string {
		return (date_default_timezone_get() === 'Pacific/Kiritimati') ? 'America/Adak' : 'Pacific/Kiritimati';
	}//end otherZone()

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

	/**
	 * On an engine that reads the trigger's timezone, every 5-field cron
	 * mirrors in any zone; only a sensitive one carries the owner's zone.
	 *
	 * @param array       $payload   The schedule payload.
	 * @param string|null $sameZone  The cron when the owner shares the server's zone.
	 * @param string|null $otherZone The cron on an engine without zones.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/schedules-onto-engine-triggers/tasks.md#task-2-2
	 */
	#[DataProvider('shapes')]
	public function testAZoneReadingEngineTakesSensitiveCronsWithTheOwnersZone(array $payload, ?string $sameZone, ?string $otherZone): void {
		$mapper = $this->mapper(sameZone: false, engineReadsZones: true);
		$schedule = $this->schedule($payload);

		$this->assertSame($sameZone, $mapper->mappedCron($schedule), 'Same cron as for an owner in the server zone.');

		$sensitive = ($sameZone !== null && $otherZone === null);
		$this->assertSame(($sensitive === true) ? $this->otherZone() : '', $mapper->mirrorTimezone($schedule));
		$this->assertSame('', $this->mapper(sameZone: true, engineReadsZones: true)->mirrorTimezone($schedule), 'A same-zone owner needs no zone.');
		$this->assertSame('', $this->mapper(sameZone: false)->mirrorTimezone($schedule), 'An engine without zones never gets one.');
	}//end testAZoneReadingEngineTakesSensitiveCronsWithTheOwnersZone()

	/**
	 * A zone PHP does not know would be read as the server's by the engine,
	 * so a sensitive cron of such an owner stays local.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/schedules-onto-engine-triggers/tasks.md#task-2-2
	 */
	public function testAnUnknownOwnerZoneKeepsASensitiveCronLocal(): void {
		$mapper = $this->mapper(sameZone: false, engineReadsZones: true, zone: 'Mars/Olympus_Mons');
		$schedule = $this->schedule(['kind' => 'cron', 'cronExpr' => '0 8 * * *']);

		$this->assertNull($mapper->mappedCron($schedule));
		$this->assertSame('', $mapper->mirrorTimezone($schedule));
		$this->assertSame('*/10 * * * *', $mapper->mappedCron($this->schedule(['kind' => 'cron', 'cronExpr' => '*/10 * * * *'])), 'A pure cadence needs no zone.');
	}//end testAnUnknownOwnerZoneKeepsASensitiveCronLocal()
}//end class

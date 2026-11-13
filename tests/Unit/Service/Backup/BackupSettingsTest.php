<?php

/**
 * Unit tests for BackupSettings and BackupSettingsStore.
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
 * @spec openspec/changes/operations-backup-and-restore/specs/backup-restore/spec.md#requirement-an-administrator-can-back-up-an-organisations-hermiq-data-to-files-on-a-schedule-req-bkup-001
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Backup;

use InvalidArgumentException;
use OCA\Hermiq\Service\Backup\BackupSettings;
use OCA\Hermiq\Service\Backup\BackupSettingsStore;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * The backup settings: defaults, validation and storage.
 *
 * @covers \OCA\Hermiq\Service\Backup\BackupSettings
 * @covers \OCA\Hermiq\Service\Backup\BackupSettingsStore
 */
final class BackupSettingsTest extends TestCase {

	/**
	 * Off, "Hermiq backups", 02:00, 14, without sessions, until an administrator says otherwise.
	 *
	 * @return void
	 */
	public function testTheDefaults(): void {
		$this->assertSame(
			['enabled' => false, 'folder' => 'Hermiq backups', 'hour' => 2, 'keep' => 14, 'includeSessions' => false, 'ownerUid' => ''],
			(new BackupSettings())->toArray()
		);
		$this->assertSame((new BackupSettings())->toArray(), BackupSettings::fromArray(values: [])->toArray());
	}//end testTheDefaults()

	/**
	 * Posted values, as JSON or as form strings, are read.
	 *
	 * @return void
	 */
	public function testPostedValuesAreRead(): void {
		$settings = BackupSettings::fromArray(values: ['enabled' => 'true', 'folder' => ' Back-ups/Hermiq ', 'hour' => '23', 'keep' => 30, 'includeSessions' => '1']);

		$this->assertSame(
			['enabled' => true, 'folder' => 'Back-ups/Hermiq', 'hour' => 23, 'keep' => 30, 'includeSessions' => true, 'ownerUid' => ''],
			$settings->toArray()
		);
		$this->assertSame('admin', $settings->withOwner(ownerUid: 'admin')->ownerUid);
		$this->assertSame(30, $settings->withOwner(ownerUid: 'admin')->keep);
	}//end testPostedValuesAreRead()

	/**
	 * Values out of range are refused, naming what is wrong.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: string}>
	 */
	public static function refusals(): array {
		return [
			'hour 24'           => [['hour' => 24], 'between 0 and 23'],
			'hour -1'           => [['hour' => -1], 'between 0 and 23'],
			'hour text'         => [['hour' => 'two'], 'whole number'],
			'keep 0'            => [['keep' => 0], 'between 1 and 365'],
			'keep 366'          => [['keep' => 366], 'between 1 and 365'],
			'folder empty'      => [['folder' => '  '], 'folder name'],
			'folder parent'     => [['folder' => '../other'], 'folder name'],
			'folder absolute'   => [['folder' => '/etc'], 'folder name'],
		];
	}//end refusals()

	/**
	 * @dataProvider refusals
	 *
	 * @param array<string, mixed> $values  The posted values.
	 * @param string               $message Part of the refusal.
	 *
	 * @return void
	 */
	public function testValuesOutOfRangeAreRefused(array $values, string $message): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage($message);
		BackupSettings::fromArray(values: $values);
	}//end testValuesOutOfRangeAreRefused()

	/**
	 * The store keeps the settings as one JSON value and reads them back.
	 *
	 * @return void
	 */
	public function testTheStoreKeepsTheSettingsAsOneValue(): void {
		$stored = [];
		$config = $this->createMock(IAppConfig::class);
		$config->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value) use (&$stored): bool {
				$stored[$app . '/' . $key] = $value;
				return true;
			}
		);
		$config->method('getValueString')->willReturnCallback(
			function (string $app, string $key, string $default = '') use (&$stored): string {
				return ($stored[$app . '/' . $key] ?? $default);
			}
		);
		$store = new BackupSettingsStore(appConfig: $config);

		$store->save(settings: new BackupSettings(enabled: true, keep: 7, ownerUid: 'admin'));

		$this->assertSame(['hermiq/backup'], array_keys($stored));
		$this->assertSame(7, $store->get()->keep);
		$this->assertTrue($store->get()->enabled);
		$this->assertSame('admin', $store->get()->ownerUid);

		$this->assertSame('', $store->lastRunDate());
		$store->markRun(date: '2026-09-28');
		$this->assertSame('2026-09-28', $store->lastRunDate());
	}//end testTheStoreKeepsTheSettingsAsOneValue()

	/**
	 * Nothing stored, unreadable JSON or a stored value out of range reads as the defaults: off.
	 *
	 * @return void
	 */
	public function testAnUnusableStoredValueReadsAsOff(): void {
		foreach (['', '{not json', '{"enabled": true, "hour": 99}'] as $raw) {
			$config = $this->createMock(IAppConfig::class);
			$config->method('getValueString')->willReturn($raw);

			$this->assertFalse((new BackupSettingsStore(appConfig: $config))->get()->enabled, $raw);
		}
	}//end testAnUnusableStoredValueReadsAsOff()
}//end class

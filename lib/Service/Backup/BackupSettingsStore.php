<?php

/**
 * Hermiq BackupSettingsStore.
 *
 * Reads and writes the backup settings, kept as one JSON value `backup` in
 * hermiq's app config (design D1), and the date the nightly backup last ran,
 * so it runs once on the chosen hour.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Backup
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

namespace OCA\Hermiq\Service\Backup;

use InvalidArgumentException;
use OCA\Hermiq\Kernel\AppIdentity;
use OCP\IAppConfig;

/**
 * The stored backup settings.
 *
 * @spec openspec/changes/operations-backup-and-restore/specs/backup-restore/spec.md#requirement-an-administrator-can-back-up-an-organisations-hermiq-data-to-files-on-a-schedule-req-bkup-001
 */
class BackupSettingsStore {

	public const CONFIG_KEY = 'backup';

	public const CONFIG_LAST_RUN = 'backup_last_run';

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig Hermiq's app config.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
	) {
	}//end __construct()

	/**
	 * The stored settings, or the defaults when none or unreadable ones are stored.
	 *
	 * @return BackupSettings The settings.
	 *
	 * @spec openspec/changes/operations-backup-and-restore/specs/backup-restore/spec.md#requirement-an-administrator-can-back-up-an-organisations-hermiq-data-to-files-on-a-schedule-req-bkup-001
	 */
	public function get(): BackupSettings {
		$raw = $this->appConfig->getValueString(AppIdentity::APP_ID, self::CONFIG_KEY, '');
		$values = json_decode($raw, true);
		if (is_array($values) === false) {
			return new BackupSettings();
		}

		try {
			return BackupSettings::fromArray(values: $values);
		} catch (InvalidArgumentException $e) {
			// A stored value out of range is not a reason to back up somewhere unexpected: off.
			return new BackupSettings();
		}
	}//end get()

	/**
	 * Store the settings.
	 *
	 * @param BackupSettings $settings The settings.
	 *
	 * @return BackupSettings The stored settings.
	 *
	 * @spec openspec/changes/operations-backup-and-restore/specs/backup-restore/spec.md#requirement-an-administrator-can-back-up-an-organisations-hermiq-data-to-files-on-a-schedule-req-bkup-001
	 */
	public function save(BackupSettings $settings): BackupSettings {
		$this->appConfig->setValueString(AppIdentity::APP_ID, self::CONFIG_KEY, (string)json_encode($settings->toArray()));
		return $settings;
	}//end save()

	/**
	 * The date (Y-m-d) the nightly backup last ran, or ''.
	 *
	 * @return string The date.
	 *
	* @spec openspec/changes/operations-backup-and-restore/specs/backup-restore/spec.md#requirement-an-administrator-can-back-up-an-organisations-hermiq-data-to-files-on-a-schedule-req-bkup-001
	 */
	public function lastRunDate(): string {
		return $this->appConfig->getValueString(AppIdentity::APP_ID, self::CONFIG_LAST_RUN, '');
	}//end lastRunDate()

	/**
	 * Remember the date the nightly backup ran.
	 *
	 * @param string $date The date (Y-m-d).
	 *
	 * @return void
	 *
	* @spec openspec/changes/operations-backup-and-restore/specs/backup-restore/spec.md#requirement-an-administrator-can-back-up-an-organisations-hermiq-data-to-files-on-a-schedule-req-bkup-001
	 */
	public function markRun(string $date): void {
		$this->appConfig->setValueString(AppIdentity::APP_ID, self::CONFIG_LAST_RUN, $date);
	}//end markRun()
}//end class

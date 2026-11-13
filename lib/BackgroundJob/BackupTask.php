<?php

/**
 * Hermiq BackupTask.
 *
 * The nightly backup (operations-backup-and-restore, design D2). Wakes every
 * hour and backs up once a day, in the hour the administrator chose, when
 * backups are switched on.
 *
 * @category BackgroundJob
 * @package  OCA\Hermiq\BackgroundJob
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

namespace OCA\Hermiq\BackgroundJob;

use OCA\Hermiq\Service\Backup\BackupSettingsStore;
use OCA\Hermiq\Service\Backup\BackupWriter;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Runs the scheduled backup once a day at the chosen hour.
 *
 * @spec openspec/changes/operations-backup-and-restore/specs/backup-restore/spec.md#requirement-an-administrator-can-back-up-an-organisations-hermiq-data-to-files-on-a-schedule-req-bkup-001
 */
class BackupTask extends TimedJob {

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory        $time     The clock.
	 * @param BackupSettingsStore $settings The backup settings.
	 * @param BackupWriter        $writer   Writes the backups.
	 * @param LoggerInterface     $logger   The logger.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly BackupSettingsStore $settings,
		private readonly BackupWriter $writer,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);

		// Hourly, so the backup lands in the hour the administrator chose.
		$this->setInterval(seconds: 3600);
		$this->setAllowParallelRuns(allow: false);
	}//end __construct()

	/**
	 * Back up when switched on, in the chosen hour, and not yet today.
	 *
	 * @param mixed $argument Unused.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/operations-backup-and-restore/specs/backup-restore/spec.md#requirement-an-administrator-can-back-up-an-organisations-hermiq-data-to-files-on-a-schedule-req-bkup-001
	 */
	protected function run($argument): void {
		$settings = $this->settings->get();
		$now = $this->time->getDateTime('now');
		$today = $now->format('Y-m-d');
		if ($settings->enabled === false
			|| (int)$now->format('G') !== $settings->hour
			|| $this->settings->lastRunDate() === $today
		) {
			return;
		}

		// Marked before the run: a run that fails is reported, not retried every hour into the day.
		$this->settings->markRun(date: $today);
		try {
			$report = $this->writer->backUp(settings: $settings);
			$this->logger->info(
				sprintf('Hermiq backed up %d organisation(s)', count($report)),
				['app' => 'hermiq']
			);
		} catch (Throwable $e) {
			$this->logger->error('Hermiq nightly backup failed: ' . $e->getMessage(), ['exception' => $e, 'app' => 'hermiq']);
		}
	}//end run()
}//end class

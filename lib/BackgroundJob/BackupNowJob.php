<?php

/**
 * Hermiq BackupNowJob.
 *
 * "Back up now" (operations-backup-and-restore, design D2): queued by an
 * administrator so the request returns at once, and writing the same backup
 * as the nightly run, whether or not the schedule is switched on.
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
use OCP\BackgroundJob\QueuedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Writes one backup now.
 *
 * @spec openspec/changes/operations-backup-and-restore/specs/backup-restore/spec.md#requirement-an-administrator-can-back-up-an-organisations-hermiq-data-to-files-on-a-schedule-req-bkup-001
 */
class BackupNowJob extends QueuedJob {

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
	}//end __construct()

	/**
	 * Write the backup.
	 *
	 * @param mixed $argument Unused.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/operations-backup-and-restore/specs/backup-restore/spec.md#requirement-an-administrator-can-back-up-an-organisations-hermiq-data-to-files-on-a-schedule-req-bkup-001
	 */
	protected function run($argument): void {
		try {
			$report = $this->writer->backUp(settings: $this->settings->get());
			$this->logger->info(sprintf('Hermiq backed up %d organisation(s) on request', count($report)), ['app' => 'hermiq']);
		} catch (Throwable $e) {
			$this->logger->error('Hermiq backup on request failed: ' . $e->getMessage(), ['exception' => $e, 'app' => 'hermiq']);
		}
	}//end run()
}//end class

<?php

/**
 * Hermiq RestoreJob.
 *
 * A restore an administrator asked for (operations-backup-and-restore, design
 * D4): queued so the request returns at once, and run once. The argument holds
 * the restore point and the administrator; the work is RestoreRunner's.
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
 * @spec openspec/changes/operations-backup-and-restore/specs/backup-restore/spec.md#requirement-a-restore-stops-the-organisations-agents-and-writes-through-the-audit-trail-req-bkup-003
 */

declare(strict_types=1);

namespace OCA\Hermiq\BackgroundJob;

use OCA\Hermiq\Service\Backup\BackupSettingsStore;
use OCA\Hermiq\Service\Backup\RestorePoint;
use OCA\Hermiq\Service\Backup\RestoreRunner;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Runs one queued restore.
 *
 * @spec openspec/changes/operations-backup-and-restore/specs/backup-restore/spec.md#requirement-a-restore-stops-the-organisations-agents-and-writes-through-the-audit-trail-req-bkup-003
 */
class RestoreJob extends QueuedJob {

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory        $time     The clock.
	 * @param BackupSettingsStore $settings The backup settings.
	 * @param RestoreRunner       $runner   Runs the restore.
	 * @param LoggerInterface     $logger   The logger.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly BackupSettingsStore $settings,
		private readonly RestoreRunner $runner,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
	}//end __construct()

	/**
	 * Run the restore.
	 *
	 * @param mixed $argument `{point: array, adminUid: string}`.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/operations-backup-and-restore/specs/backup-restore/spec.md#requirement-a-restore-stops-the-organisations-agents-and-writes-through-the-audit-trail-req-bkup-003
	 */
	protected function run($argument): void {
		$argument = (array)$argument;
		try {
			$point = RestorePoint::fromArray(values: (array)($argument['point'] ?? []));
			$result = $this->runner->run(point: $point, settings: $this->settings->get(), adminUid: (string)($argument['adminUid'] ?? ''));
			$this->logger->info('Hermiq restored organisation ' . $result['organisation'] . ': ' . $result['outcome'], ['app' => 'hermiq']);
		} catch (Throwable $e) {
			$this->logger->error('Hermiq restore failed before writing: ' . $e->getMessage(), ['exception' => $e, 'app' => 'hermiq']);
		}
	}//end run()
}//end class

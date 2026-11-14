<?php

/**
 * Hermiq BackupController.
 *
 * The admin-only restore routes (operations-backup-and-restore, design D3 and
 * D4): the backups an administrator can restore from, the preview of a
 * restore, and the restore itself, which is queued so the request returns at
 * once. Every action requires a Nextcloud administrator (hermiq ADR-023
 * rule 3); the attribute is checked by Nextcloud before the action runs.
 *
 * @category Controller
 * @package  OCA\Hermiq\Controller
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
 * @spec openspec/changes/operations-backup-and-restore/specs/backup-restore/spec.md#requirement-backup-and-restore-are-for-administrators-only-req-bkup-004
 */

declare(strict_types=1);

namespace OCA\Hermiq\Controller;

use InvalidArgumentException;
use OCA\Hermiq\BackgroundJob\RestoreJob;
use OCA\Hermiq\Kernel\AppIdentity;
use OCA\Hermiq\Service\Backup\BackupFiles;
use OCA\Hermiq\Service\Backup\BackupSettingsStore;
use OCA\Hermiq\Service\Backup\RestorePlanner;
use OCA\Hermiq\Service\Backup\RestorePoint;
use OCA\Hermiq\Settings\AdminSettings;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\BackgroundJob\IJobList;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * List backups, preview a restore, queue a restore.
 *
 * @spec openspec/changes/operations-backup-and-restore/specs/backup-restore/spec.md#requirement-backup-and-restore-are-for-administrators-only-req-bkup-004
 */
class BackupController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest            $request     The request.
	 * @param BackupSettingsStore $settings    The backup settings (the folder).
	 * @param BackupFiles         $files       The backup files.
	 * @param RestorePlanner      $planner     Works out what a restore would do.
	 * @param IJobList            $jobList     Queues the restore.
	 * @param IUserSession        $userSession The administrator.
	 * @param LoggerInterface     $logger      The logger.
	 */
	public function __construct(
		IRequest $request,
		private readonly BackupSettingsStore $settings,
		private readonly BackupFiles $files,
		private readonly RestorePlanner $planner,
		private readonly IJobList $jobList,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The backups in the backup folder, newest first, each with its manifest.
	 *
	 * @return JSONResponse The backups.
	 *
	 * @spec openspec/changes/operations-backup-and-restore/specs/backup-restore/spec.md#requirement-a-restore-starts-with-a-preview-of-what-will-change-req-bkup-002
	 */
	#[AuthorizedAdminSetting(AdminSettings::class)]
	public function files(): JSONResponse {
		try {
			return new JSONResponse(['backups' => $this->files->list(settings: $this->settings->get())]);
		} catch (Throwable $e) {
			$this->logger->error('[BackupController] The backup folder could not be read', ['exception' => $e->getMessage(), 'app' => AppIdentity::APP_ID]);
			return new JSONResponse(['error' => 'The backup folder could not be read.'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}//end files()

	/**
	 * What a restore to a backup or a moment would do. Nothing is written.
	 *
	 * @return JSONResponse The preview; 422 for a point that cannot be understood,
	 *                      404 for a backup that is not there, 409 when it is refused.
	 *
	 * @spec openspec/changes/operations-backup-and-restore/specs/backup-restore/spec.md#requirement-a-restore-starts-with-a-preview-of-what-will-change-req-bkup-002
	 */
	#[AuthorizedAdminSetting(AdminSettings::class)]
	public function preview(): JSONResponse {
		try {
			$point = RestorePoint::fromArray(values: $this->request->getParams());
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		}

		try {
			$preview = $this->planner->preview(point: $point, settings: $this->settings->get());
		} catch (Throwable $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
		}

		if ($preview['refused'] !== null) {
			return new JSONResponse($preview, Http::STATUS_CONFLICT);
		}

		return new JSONResponse($preview);
	}//end preview()

	/**
	 * Queue a restore; the request returns at once. A point the preview refuses is
	 * refused here too, before anything is queued.
	 *
	 * @return JSONResponse 202 when queued; 422, 404 or 409 as for the preview.
	 *
	 * @spec openspec/changes/operations-backup-and-restore/specs/backup-restore/spec.md#requirement-a-restore-stops-the-organisations-agents-and-writes-through-the-audit-trail-req-bkup-003
	 */
	#[AuthorizedAdminSetting(AdminSettings::class)]
	public function restore(): JSONResponse {
		$checked = $this->preview();
		if ($checked->getStatus() !== Http::STATUS_OK) {
			return $checked;
		}

		$preview = (array)$checked->getData();
		$point = RestorePoint::fromArray(values: (array)$preview['point']);
		$adminUid = (string)($this->userSession->getUser()?->getUID() ?? '');
		$this->jobList->add(RestoreJob::class, ['point' => $point->toArray(), 'adminUid' => $adminUid]);

		return new JSONResponse(['queued' => true, 'organisation' => $point->organisation, 'writes' => $preview['writes']], Http::STATUS_ACCEPTED);
	}//end restore()
}//end class

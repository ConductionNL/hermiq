<?php

/**
 * Hermiq BackupSettingsController.
 *
 * The backup section of hermiq's admin settings (operations-backup-and-
 * restore, design D1): read and save the settings, and queue "Back up now".
 * Every route is for a Nextcloud administrator only (REQ-BKUP-004, hermiq
 * ADR-023 rule 3). The folder is in the Files of the administrator who saved
 * the settings.
 *
 * @category Controller
 * @package  OCA\Hermiq\Controller\Settings
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

namespace OCA\Hermiq\Controller\Settings;

use InvalidArgumentException;
use OCA\Hermiq\BackgroundJob\BackupNowJob;
use OCA\Hermiq\Kernel\AppIdentity;
use OCA\Hermiq\Service\Backup\BackupSettings;
use OCA\Hermiq\Service\Backup\BackupSettingsStore;
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
 * Admin-only backup settings and "Back up now".
 *
 * @spec openspec/changes/operations-backup-and-restore/specs/backup-restore/spec.md#requirement-backup-and-restore-are-for-administrators-only-req-bkup-004
 */
class BackupSettingsController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest            $request     The request.
	 * @param BackupSettingsStore $store       The backup settings.
	 * @param IJobList            $jobList     Queues "Back up now".
	 * @param IUserSession        $userSession The administrator.
	 * @param LoggerInterface     $logger      The logger.
	 */
	public function __construct(
		IRequest $request,
		private readonly BackupSettingsStore $store,
		private readonly IJobList $jobList,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The backup settings.
	 *
	 * @return JSONResponse The settings.
	 *
	 * @spec openspec/changes/operations-backup-and-restore/specs/backup-restore/spec.md#requirement-backup-and-restore-are-for-administrators-only-req-bkup-004
	 */
	#[AuthorizedAdminSetting(AdminSettings::class)]
	public function get(): JSONResponse {
		return new JSONResponse($this->store->get()->toArray());
	}//end get()

	/**
	 * Save the backup settings; the folder is in the saving administrator's Files.
	 *
	 * @return JSONResponse The saved settings, or 422 naming the value out of range.
	 *
	 * @spec openspec/changes/operations-backup-and-restore/specs/backup-restore/spec.md#requirement-backup-and-restore-are-for-administrators-only-req-bkup-004
	 */
	#[AuthorizedAdminSetting(AdminSettings::class)]
	public function update(): JSONResponse {
		$uid = (string)($this->userSession->getUser()?->getUID() ?? '');
		try {
			$settings = BackupSettings::fromArray(values: $this->request->getParams())->withOwner(ownerUid: $uid);
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		}

		try {
			return new JSONResponse($this->store->save(settings: $settings)->toArray());
		} catch (Throwable $e) {
			$this->logger->error(
				message: '[BackupSettingsController] Failed to save the backup settings',
				context: ['file' => __FILE__, 'line' => __LINE__, 'error' => $e->getMessage()]
			);
			return new JSONResponse(['error' => 'Failed to save the backup settings'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}//end update()

	/**
	 * Queue a backup now; the request returns at once.
	 *
	 * @return JSONResponse 202 when queued, 409 when no folder owner is set yet.
	 *
	 * @spec openspec/changes/operations-backup-and-restore/specs/backup-restore/spec.md#requirement-backup-and-restore-are-for-administrators-only-req-bkup-004
	 */
	#[AuthorizedAdminSetting(AdminSettings::class)]
	public function backupNow(): JSONResponse {
		if ($this->store->get()->ownerUid === '') {
			return new JSONResponse(['error' => 'Save the backup settings first, so the backup has a folder.'], Http::STATUS_CONFLICT);
		}

		$this->jobList->add(BackupNowJob::class);
		return new JSONResponse(['queued' => true], Http::STATUS_ACCEPTED);
	}//end backupNow()
}//end class

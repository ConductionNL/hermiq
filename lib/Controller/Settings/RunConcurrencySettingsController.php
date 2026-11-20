<?php

/**
 * Hermiq RunConcurrencySettingsController
 *
 * Admin API for how many scheduled runs may go at once.
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
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Hermiq\Controller\Settings;

use InvalidArgumentException;
use OCA\Hermiq\Kernel\AppIdentity;
use OCA\Hermiq\Service\Run\RunConcurrencySettings;
use OCA\Hermiq\Settings\AdminSettings;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reads and stores the limits on parallel scheduled runs.
 *
 * Both routes are `#[AuthorizedAdminSetting]`: Nextcloud refuses anybody who is
 * not an administrator (or delegated this settings section) before the method
 * runs.
 *
 * @spec openspec/changes/operations-parallel-run-workers/specs/agent-schedule/spec.md#requirement-an-administrator-limits-how-many-runs-go-at-once-req-prun-003
 */
class RunConcurrencySettingsController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest               $request  The request.
	 * @param RunConcurrencySettings $settings The limits.
	 * @param LoggerInterface        $logger   Logs a failed read or write.
	 */
	public function __construct(
		IRequest $request,
		private readonly RunConcurrencySettings $settings,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);

	}//end __construct()

	/**
	 * The limits in force, with their bounds.
	 *
	 * @return JSONResponse `{maxParallel, maxParallelPerOrganisation, maximum}`.
	 *
	 * @spec openspec/changes/operations-parallel-run-workers/specs/agent-schedule/spec.md#requirement-an-administrator-limits-how-many-runs-go-at-once-req-prun-003
	 */
	#[AuthorizedAdminSetting(AdminSettings::class)]
	public function get(): JSONResponse {
		try {
			return new JSONResponse($this->settings->limits() + ['maximum' => RunConcurrencySettings::MAXIMUM]);
		} catch (Throwable $e) {
			$this->logger->error(
				message: '[RunConcurrencySettingsController] Failed to read the run limits',
				context: ['file' => __FILE__, 'line' => __LINE__, 'error' => $e->getMessage()]
			);
			return new JSONResponse(['error' => 'Failed to read the run limits'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

	}//end get()

	/**
	 * Store new limits.
	 *
	 * @return JSONResponse The limits stored, or 422 with the reason.
	 *
	 * @spec openspec/changes/operations-parallel-run-workers/specs/agent-schedule/spec.md#requirement-an-administrator-limits-how-many-runs-go-at-once-req-prun-003
	 */
	#[AuthorizedAdminSetting(AdminSettings::class)]
	public function update(): JSONResponse {
		try {
			$stored = $this->settings->setLimits(
				maxParallel: $this->request->getParam('maxParallel'),
				maxParallelPerOrganisation: $this->request->getParam('maxParallelPerOrganisation')
			);
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		} catch (Throwable $e) {
			$this->logger->error(
				message: '[RunConcurrencySettingsController] Failed to store the run limits',
				context: ['file' => __FILE__, 'line' => __LINE__, 'error' => $e->getMessage()]
			);
			return new JSONResponse(['error' => 'Failed to save the run limits'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new JSONResponse($stored + ['maximum' => RunConcurrencySettings::MAXIMUM]);

	}//end update()
}//end class

<?php

/**
 * Hermiq Run Retention Settings Controller.
 *
 * The admin surface over how long AI run records are kept, and the report that makes
 * the keeping checkable rather than assumed: when retention last ran, and how many
 * run payloads it removed. A job that has never run says so, because "never ran" and
 * "ran and removed nothing" are different answers to an administrator asking whether
 * retention is enforced.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Controller
 * @package  OCA\Hermiq\Controller\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/what-the-model-reads-and-what-is-kept/specs/run-audit-log/spec.md#requirement-a-scheduled-job-must-enforce-retention-and-must-report-that-it-did
 */

declare(strict_types=1);

namespace OCA\Hermiq\Controller\Settings;

use InvalidArgumentException;
use OCA\Hermiq\AppInfo\Application;
use OCA\Hermiq\Service\AiFeature\RunRetentionPolicy;
use OCA\Hermiq\Settings\AdminSettings;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Read and set the instance retention, and read the last cleanup.
 *
 * @spec openspec/changes/what-the-model-reads-and-what-is-kept/specs/run-audit-log/spec.md#requirement-every-ai-run-must-carry-the-retention-that-applied-when-it-was-written
 */
class RunRetentionSettingsController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param RunRetentionPolicy $policy Resolves and reports retention.
	 * @param LoggerInterface $logger Logger.
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly RunRetentionPolicy $policy,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);

	}//end __construct()

	/**
	 * Read the instance retention and the last cleanup.
	 *
	 * @return JSONResponse The default in days, the permitted range and the report.
	 *
	 * @spec openspec/changes/what-the-model-reads-and-what-is-kept/specs/run-audit-log/spec.md#scenario-the-last-cleanup-is-an-answerable-question
	 */
	#[AuthorizedAdminSetting(AdminSettings::class)]
	public function get(): JSONResponse {
		try {
			return new JSONResponse(
				[
					'defaultDays' => $this->policy->defaultDays(),
					'minimumDays' => RunRetentionPolicy::MINIMUM_DAYS,
					'maximumDays' => RunRetentionPolicy::MAXIMUM_DAYS,
					'lastCleanup' => $this->policy->lastCleanup(),
				]
			);
		} catch (Throwable $e) {
			$this->logger->error(
				message: '[RunRetentionSettingsController] Failed to read the retention settings',
				context: ['file' => __FILE__, 'line' => __LINE__, 'error' => $e->getMessage()]
			);
			return new JSONResponse(['error' => 'Failed to read the retention settings'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

	}//end get()

	/**
	 * Set the instance retention.
	 *
	 * @return JSONResponse The stored default, or 422 when it is outside the permitted range.
	 *
	 * @spec openspec/changes/what-the-model-reads-and-what-is-kept/specs/run-audit-log/spec.md#scenario-changing-the-default-does-not-move-an-old-promise
	 */
	#[AuthorizedAdminSetting(AdminSettings::class)]
	public function update(): JSONResponse {
		$days = $this->request->getParam('defaultDays');

		if (is_numeric($days) === false) {
			return new JSONResponse(
				['error' => 'A retention is a number of days.'],
				Http::STATUS_UNPROCESSABLE_ENTITY
			);
		}

		try {
			$stored = $this->policy->setDefaultDays(days: (int)$days);
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		} catch (Throwable $e) {
			$this->logger->error(
				message: '[RunRetentionSettingsController] Failed to persist the retention default',
				context: ['file' => __FILE__, 'line' => __LINE__, 'error' => $e->getMessage()]
			);
			return new JSONResponse(['error' => 'Failed to save the retention setting'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new JSONResponse(
			[
				'defaultDays' => $stored,
				'lastCleanup' => $this->policy->lastCleanup(),
			]
		);

	}//end update()
}//end class

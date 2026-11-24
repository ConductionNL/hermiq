<?php

/**
 * Hermiq EvalScheduleController.
 *
 * "Run on a schedule" (reuse-scheduled-bias-evals, REQ-BIASEVAL-003):
 * `GET /api/evals/{datasetId}/schedules` lists the caller's schedules of a dataset,
 * `POST` adds one and `PUT .../{id}` changes one. A dataset, agent or schedule the
 * caller does not own is a 404, never a 403, so nothing is confirmed about another
 * person's objects; a cron expression or threshold that is not valid is a 400.
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
 * @spec openspec/changes/archive/2026-11-24-reuse-scheduled-bias-evals/specs/agent-evals/spec.md#requirement-a-dataset-can-run-against-an-agent-on-a-schedule-req-biaseval-003
 */

declare(strict_types=1);

namespace OCA\Hermiq\Controller;

use OCA\Hermiq\Service\Eval\EvalScheduleEditor;
use OCA\Hermiq\Kernel\AppIdentity;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http;
use OCP\AppFramework\Controller;
use OCP\IRequest;
use OCP\IUserSession;
use InvalidArgumentException;

/**
 * Lists, adds and changes a dataset's eval schedules for its owner.
 *
 * @spec openspec/changes/archive/2026-11-24-reuse-scheduled-bias-evals/specs/agent-evals/spec.md#requirement-a-dataset-can-run-against-an-agent-on-a-schedule-req-biaseval-003
 */
class EvalScheduleController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest           $request     The request.
	 * @param IUserSession       $userSession The caller.
	 * @param EvalScheduleEditor $editor      Applies the owner rule and stores the schedule.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-11-24-reuse-scheduled-bias-evals/specs/agent-evals/spec.md#requirement-a-dataset-can-run-against-an-agent-on-a-schedule-req-biaseval-003
	 */
	public function __construct(
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly EvalScheduleEditor $editor,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The caller's schedules of the dataset.
	 *
	 * @param string $datasetId The dataset uuid.
	 *
	 * @return JSONResponse 200 with the schedules; 401 or 404 otherwise.
	 *
	 * @spec openspec/changes/archive/2026-11-24-reuse-scheduled-bias-evals/specs/agent-evals/spec.md#requirement-a-dataset-can-run-against-an-agent-on-a-schedule-req-biaseval-003
	 */
	#[NoAdminRequired]
	public function index(string $datasetId): JSONResponse {
		$uid = $this->userSession->getUser()?->getUID();
		if ($uid === null) {
			return new JSONResponse(['error' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$schedules = $this->editor->listFor(datasetId: $datasetId, uid: $uid);
		if ($schedules === null) {
			return new JSONResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse(['results' => $schedules]);
	}//end index()

	/**
	 * Add a schedule to the dataset.
	 *
	 * @param string $datasetId The dataset uuid.
	 *
	 * @return JSONResponse 201 with the schedule; 400, 401 or 404 otherwise.
	 *
	 * @spec openspec/changes/archive/2026-11-24-reuse-scheduled-bias-evals/specs/agent-evals/spec.md#requirement-a-dataset-can-run-against-an-agent-on-a-schedule-req-biaseval-003
	 */
	#[NoAdminRequired]
	public function create(string $datasetId): JSONResponse {
		return $this->store(datasetId: $datasetId, scheduleId: null, created: Http::STATUS_CREATED);
	}//end create()

	/**
	 * Change one of the dataset's schedules.
	 *
	 * @param string $datasetId The dataset uuid.
	 * @param string $id        The schedule uuid.
	 *
	 * @return JSONResponse 200 with the schedule; 400, 401 or 404 otherwise.
	 *
	 * @spec openspec/changes/archive/2026-11-24-reuse-scheduled-bias-evals/specs/agent-evals/spec.md#requirement-a-dataset-can-run-against-an-agent-on-a-schedule-req-biaseval-003
	 */
	#[NoAdminRequired]
	public function update(string $datasetId, string $id): JSONResponse {
		return $this->store(datasetId: $datasetId, scheduleId: $id, created: Http::STATUS_OK);
	}//end update()

	/**
	 * Save the schedule from the request fields.
	 *
	 * @param string      $datasetId  The dataset uuid.
	 * @param string|null $scheduleId The schedule to change; null adds one.
	 * @param int         $created    The status of a saved schedule.
	 *
	 * @return JSONResponse
	 */
	private function store(string $datasetId, ?string $scheduleId, int $created): JSONResponse {
		$uid = $this->userSession->getUser()?->getUID();
		if ($uid === null) {
			return new JSONResponse(['error' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$input = [];
		foreach (['agentId', 'cronExpr', 'enabled', 'regressionThresholdPercent'] as $field) {
			$input[$field] = $this->request->getParam($field);
		}

		try {
			$schedule = $this->editor->save(datasetId: $datasetId, uid: $uid, input: $input, scheduleId: $scheduleId);
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}

		if ($schedule === null) {
			return new JSONResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse($schedule, $created);
	}//end store()
}//end class

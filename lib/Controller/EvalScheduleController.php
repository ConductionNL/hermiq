<?php

/**
 * Hermiq EvalScheduleController: use a template for an agent, and run a dataset on a schedule.
 *
 * Security (ADR-005 rule 3, IDOR): every action is #[NoAdminRequired], so the
 * services check ownership: the template copy needs the caller to own the agent,
 * a schedule needs the caller to own the dataset and the agent. Anything the
 * caller does not own answers 404, never 403, so it cannot learn what exists.
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
 * @spec openspec/changes/reuse-scheduled-bias-evals/specs/agent-evals/spec.md#requirement-a-dataset-can-run-against-an-agent-on-a-schedule-req-biaseval-003
 */

declare(strict_types=1);

namespace OCA\Hermiq\Controller;

use OCA\Hermiq\Kernel\AppIdentity;
use OCA\Hermiq\Kernel\Contracts\EvalScheduleServiceInterface;
use OCA\Hermiq\Kernel\Contracts\EvalTemplateServiceInterface;
use OCA\Hermiq\Service\EvalActionException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Owner-guarded eval dataset actions with no OpenRegister object equivalent.
 *
 * @spec openspec/changes/reuse-scheduled-bias-evals/specs/agent-evals/spec.md#requirement-a-dataset-can-run-against-an-agent-on-a-schedule-req-biaseval-003
 */
class EvalScheduleController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest                     $request     The request.
	 * @param IUserSession                 $userSession The caller.
	 * @param EvalTemplateServiceInterface $templates   Copies a template for an agent.
	 * @param EvalScheduleServiceInterface $schedules   Reads and saves eval schedules.
	 */
	public function __construct(
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly EvalTemplateServiceInterface $templates,
		private readonly EvalScheduleServiceInterface $schedules,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Copy a template dataset into a dataset of the caller's own for one of their agents.
	 *
	 * @param string $datasetId The template.
	 *
	 * @return JSONResponse 201 with the new dataset; 400, 401 or 404.
	 *
	 * @spec openspec/changes/reuse-scheduled-bias-evals/specs/agent-evals/spec.md#requirement-a-ready-made-bias-test-set-can-be-used-for-an-agent-req-biaseval-002
	 */
	#[NoAdminRequired]
	public function useForAgent(string $datasetId): JSONResponse {
		$uid = $this->callerUid();
		if ($uid === '') {
			return new JSONResponse(['error' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$copy = $this->templates->useForAgent(
				templateId: $datasetId,
				agentId: (string)$this->request->getParam('agentId', ''),
				uid: $uid
			);
		} catch (EvalActionException $e) {
			return self::refusal(error: $e);
		}

		return new JSONResponse($copy, Http::STATUS_CREATED);
	}//end useForAgent()

	/**
	 * The caller's schedules of a dataset they own.
	 *
	 * @param string $datasetId The dataset.
	 *
	 * @return JSONResponse 200 with the schedules; 401 or 404.
	 *
	 * @spec openspec/changes/reuse-scheduled-bias-evals/specs/agent-evals/spec.md#requirement-a-dataset-can-run-against-an-agent-on-a-schedule-req-biaseval-003
	 */
	#[NoAdminRequired]
	public function index(string $datasetId): JSONResponse {
		$uid = $this->callerUid();
		if ($uid === '') {
			return new JSONResponse(['error' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			return new JSONResponse(['results' => $this->schedules->listForDataset(datasetId: $datasetId, uid: $uid)]);
		} catch (EvalActionException $e) {
			return self::refusal(error: $e);
		}
	}//end index()

	/**
	 * Create or change a schedule of a dataset the caller owns, for an agent they own.
	 *
	 * @param string $datasetId The dataset.
	 *
	 * @return JSONResponse 200 with the schedule and its next run; 400, 401 or 404.
	 *
	 * @spec openspec/changes/reuse-scheduled-bias-evals/specs/agent-evals/spec.md#requirement-a-dataset-can-run-against-an-agent-on-a-schedule-req-biaseval-003
	 */
	#[NoAdminRequired]
	public function save(string $datasetId): JSONResponse {
		$uid = $this->callerUid();
		if ($uid === '') {
			return new JSONResponse(['error' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$input = [
			'id' => $this->request->getParam('id', ''),
			'agentId' => $this->request->getParam('agentId', ''),
			'cronExpr' => $this->request->getParam('cronExpr', ''),
			'enabled' => filter_var($this->request->getParam('enabled', true), FILTER_VALIDATE_BOOLEAN),
			'regressionThresholdPercent' => $this->request->getParam('regressionThresholdPercent', null),
		];
		if (trim((string)$input['cronExpr']) === '') {
			unset($input['cronExpr']);
		}

		try {
			return new JSONResponse($this->schedules->save(datasetId: $datasetId, input: $input, uid: $uid));
		} catch (EvalActionException $e) {
			return self::refusal(error: $e);
		}
	}//end save()

	/**
	 * The signed-in caller's uid, or '' when there is none.
	 *
	 * @return string
	 */
	private function callerUid(): string {
		return (string)($this->userSession->getUser()?->getUID() ?? '');
	}//end callerUid()

	/**
	 * A refusal as a response with its status and message.
	 *
	 * @param EvalActionException $error The refusal.
	 *
	 * @return JSONResponse
	 */
	private static function refusal(EvalActionException $error): JSONResponse {
		$status = $error->getCode();
		if (in_array($status, [Http::STATUS_BAD_REQUEST, Http::STATUS_NOT_FOUND], true) === false) {
			$status = Http::STATUS_BAD_REQUEST;
		}

		return new JSONResponse(['error' => $error->getMessage()], $status);
	}//end refusal()
}//end class

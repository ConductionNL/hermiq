<?php

/**
 * Hermiq ManualRunController.
 *
 * `POST /api/agents/{id}/run`: run an agent by hand with a prompt, without a
 * schedule (scheduling-overview-and-start-choice D2), the "By hand" row of
 * "How this agent starts". Any signed-in user who may use the agent can call
 * it; the agent is loaded through AgentAccessService, so an agent the caller
 * may not use answers 404, the same as one that does not exist. The run itself
 * passes the scheduled-run gates in ManualRunService.
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
 * @spec openspec/changes/scheduling-overview-and-start-choice/specs/agent-schedule/spec.md#requirement-an-agents-page-offers-one-choice-of-how-it-starts-req-sched-002
 */

declare(strict_types=1);

namespace OCA\Hermiq\Controller;

use OCA\Hermiq\Kernel\AppIdentity;
use OCA\Hermiq\Kernel\Contracts\LiteracyRequirementInterface;
use OCA\Hermiq\Service\AgentAccessService;
use OCA\Hermiq\Service\Schedule\ManualRunService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Runs an agent by hand for a user who may use it.
 *
 * @spec openspec/changes/scheduling-overview-and-start-choice/specs/agent-schedule/spec.md#requirement-an-agents-page-offers-one-choice-of-how-it-starts-req-sched-002
 */
class ManualRunController extends Controller {

	/**
	 * The longest prompt a run by hand takes.
	 *
	 * @var integer
	 */
	private const MAX_PROMPT = 10000;

	/**
	 * The gates that hold a run, and the status each answers with.
	 *
	 * @var array<string, integer>
	 */
	private const GATE_STATUS = [
		'skipped_agent_off' => Http::STATUS_CONFLICT,
		'skipped_killswitch' => Http::STATUS_LOCKED,
		'skipped_budget' => Http::STATUS_PAYMENT_REQUIRED,
	];

	/**
	 * Constructor.
	 *
	 * @param IRequest                          $request     The request.
	 * @param IUserSession                      $userSession The caller.
	 * @param AgentAccessService                $access      Loads an agent only for a user who may use it.
	 * @param ManualRunService                  $runs        The gated, audited run.
	 * @param LiteracyRequirementInterface|null $literacy    The course a run by hand may require first.
	 */
	public function __construct(
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly AgentAccessService $access,
		private readonly ManualRunService $runs,
		private readonly ?LiteracyRequirementInterface $literacy = null,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Run the agent now with the body's `prompt`.
	 *
	 * @param string $id The agent uuid.
	 *
	 * @return JSONResponse 200 `{status, trigger, output}` after the run; 400 without a
	 *                      prompt; 401 without a session; 403 while a required course is
	 *                      not done; 404 for an agent the caller may not use; 409, 423 or
	 *                      402 when the agent is switched off, the kill switch is engaged
	 *                      or the budget is used up; 502 when the run itself failed.
	 *
	 * @spec openspec/changes/scheduling-overview-and-start-choice/specs/agent-schedule/spec.md#requirement-an-agents-page-offers-one-choice-of-how-it-starts-req-sched-002
	 */
	#[NoAdminRequired]
	public function run(string $id): JSONResponse {
		$uid = (string)$this->userSession->getUser()?->getUID();
		if ($uid === '') {
			return new JSONResponse(data: ['error' => 'Unauthenticated'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		$agent = $this->access->loadAccessibleAgent(agentId: $id, userId: $uid);
		if ($agent === null) {
			return new JSONResponse(data: ['error' => 'Agent not found'], statusCode: Http::STATUS_NOT_FOUND);
		}

		$prompt = trim((string)$this->request->getParam('prompt', ''));
		if ($prompt === '' || mb_strlen($prompt) > self::MAX_PROMPT) {
			return new JSONResponse(data: ['error' => 'Enter what the agent should do.'], statusCode: Http::STATUS_BAD_REQUEST);
		}

		$refusal = $this->literacy?->refusal(uid: $uid);
		if ($refusal !== null) {
			return new JSONResponse(data: ['error' => $refusal['message']] + $refusal, statusCode: Http::STATUS_FORBIDDEN);
		}

		$result = $this->runs->run(agent: $agent, uid: $uid, prompt: $prompt);

		if (isset(self::GATE_STATUS[$result['status']]) === true) {
			return new JSONResponse(
				data: ['status' => $result['status'], 'trigger' => $result['trigger']],
				statusCode: self::GATE_STATUS[$result['status']]
			);
		}

		if ($result['status'] === 'error') {
			return new JSONResponse(
				data: ['status' => 'error', 'trigger' => $result['trigger'], 'error' => 'The run failed.'],
				statusCode: Http::STATUS_BAD_GATEWAY
			);
		}

		return new JSONResponse(data: ['status' => 'ok', 'trigger' => $result['trigger'], 'output' => $result['output']]);
	}//end run()
}//end class

<?php

/**
 * Hermiq GoalController.
 *
 * The three endpoints of agents-standing-goal: read a session's newest goal
 * (GET /api/sessions/{uuid}/goal), set one (POST /api/sessions/{uuid}/goal) and
 * stop one (POST /api/goals/{id}/stop). The checks on who may do what live in
 * GoalService; this class maps its outcome to a response.
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
 * @spec openspec/changes/agents-standing-goal/specs/agent-schedule/spec.md#requirement-a-person-can-give-an-agent-a-standing-goal-with-a-check-req-aggoal-001
 */

declare(strict_types=1);

namespace OCA\Hermiq\Controller;

use OCA\Hermiq\AppInfo\Application;
use OCA\Hermiq\Service\GoalService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Set, read and stop a standing goal.
 *
 * @spec openspec/changes/agents-standing-goal/specs/agent-schedule/spec.md#requirement-a-person-can-give-an-agent-a-standing-goal-with-a-check-req-aggoal-001
 */
class GoalController extends Controller {

	/**
	 * The service refusals passed through with their own status.
	 */
	private const REFUSALS = [Http::STATUS_NOT_FOUND, Http::STATUS_CONFLICT, Http::STATUS_UNPROCESSABLE_ENTITY];

	/**
	 * Constructor.
	 *
	 * @param IRequest        $request     The request.
	 * @param GoalService     $goals       The goal lifecycle.
	 * @param IUserSession    $userSession The signed-in person.
	 * @param LoggerInterface $logger      PSR-3 logger.
	 */
	public function __construct(
		IRequest $request,
		private readonly GoalService $goals,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The session's newest goal, or `{goal: null}`; 404 for a session that is not the person's.
	 *
	 * @param string $uuid The session.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/agents-standing-goal/specs/agent-schedule/spec.md#requirement-a-person-can-give-an-agent-a-standing-goal-with-a-check-req-aggoal-001
	 */
	#[NoAdminRequired]
	public function show(string $uuid): JSONResponse {
		return $this->run(call: fn (string $uid): array => ['goal' => $this->goals->forSession(sessionId: $uuid, uid: $uid)]);
	}//end show()

	/**
	 * Set a goal on the person's own session.
	 *
	 * @param string $uuid The session.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/agents-standing-goal/specs/agent-schedule/spec.md#requirement-a-person-can-give-an-agent-a-standing-goal-with-a-check-req-aggoal-001
	 *
	 * @no-admin-idor-exempt GoalService::set() scopes the session to the caller (userId must equal
	 *   the uid) and answers 404 otherwise; GoalServiceTest::testSettingAGoal.
	 */
	#[NoAdminRequired]
	public function create(string $uuid): JSONResponse {
		$check = $this->request->getParam('check');
		if (is_array($check) === false) {
			$check = [];
		}

		return $this->run(
			call: fn (string $uid): array => $this->goals->set(
				sessionId: $uuid,
				uid: $uid,
				input: [
					'statement' => (string)$this->request->getParam('statement', ''),
					'check' => $check,
					'intervalMinutes' => $this->request->getParam('intervalMinutes', 60),
					'maxTurns' => $this->request->getParam('maxTurns', 10),
				]
			)
		);
	}//end create()

	/**
	 * Stop a goal; 404 for anyone but who set it and the agent's owner.
	 *
	 * @param string $id The goal.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/agents-standing-goal/specs/agent-schedule/spec.md#requirement-a-goal-can-be-stopped-by-its-owner-or-the-agent-owner-req-aggoal-003
	 *
	 * @no-admin-idor-exempt GoalService::stop() admits only the person who set the goal and the agent's
	 *   owner, 404 for anyone else; GoalServiceTest::testOnlyTheOwnersStopAGoal and
	 *   GoalControllerTest::testAnotherUserGets404WhenStoppingAGoal.
	 */
	#[NoAdminRequired]
	public function stop(string $id): JSONResponse {
		return $this->run(call: fn (string $uid): array => $this->goals->stop(goalId: $id, uid: $uid));
	}//end stop()

	/**
	 * Run a service call as the signed-in person and map its refusal.
	 *
	 * @param callable(string): array<string, mixed> $call The call.
	 *
	 * @return JSONResponse
	 */
	private function run(callable $call): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			return new JSONResponse($call($user->getUID()));
		} catch (RuntimeException $e) {
			if (in_array($e->getCode(), self::REFUSALS, true) === true) {
				return new JSONResponse(['error' => $e->getMessage()], $e->getCode());
			}

			$this->logger->error('[hermiq] Goal request failed: ' . $e->getMessage(), ['exception' => $e]);
			return new JSONResponse(['error' => 'Something went wrong'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}//end run()
}//end class

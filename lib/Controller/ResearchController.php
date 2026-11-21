<?php

/**
 * Hermiq ResearchController.
 *
 * Starts a deep research run from the chat, reads its progress, and stops it
 * (tools-deep-research-report, design D6). Every route acts for the signed-in
 * person; a run that is not theirs answers 404, the same as one that does not
 * exist, so a run id cannot be probed.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Controller
 * @package  OCA\Hermiq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/tools-deep-research-report/specs/deep-research/spec.md#requirement-a-user-can-start-deep-research-and-sees-the-plan-req-research-001
 */

declare(strict_types=1);

namespace OCA\Hermiq\Controller;

use InvalidArgumentException;
use OCA\Hermiq\Kernel\AppIdentity;
use OCA\Hermiq\Service\Research\ResearchRequest;
use OCA\Hermiq\Service\Research\ResearchStarter;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Research runs of the signed-in person.
 *
 * @spec openspec/changes/tools-deep-research-report/specs/deep-research/spec.md#requirement-a-user-can-start-deep-research-and-sees-the-plan-req-research-001
 */
class ResearchController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest        $request     The request.
	 * @param ResearchStarter $research    Starts, stops and shows runs (owner check inside).
	 * @param IUserSession    $userSession Resolves the caller.
	 *
	 * @spec openspec/changes/tools-deep-research-report/specs/deep-research/spec.md#requirement-a-user-can-start-deep-research-and-sees-the-plan-req-research-001
	 */
	public function __construct(
		IRequest $request,
		private readonly ResearchStarter $research,
		private readonly IUserSession $userSession,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Start a research run in a conversation.
	 *
	 * @param string $agentId        The agent that researches.
	 * @param string $conversationId The conversation.
	 * @param string $question       The research question.
	 * @param string $depth          `quick` or `thorough`.
	 *
	 * @return JSONResponse 201 with the run, 400 on a bad request, 401 signed out,
	 *                      404 when the agent or conversation cannot be read.
	 *
	 * @spec openspec/changes/tools-deep-research-report/specs/deep-research/spec.md#requirement-a-user-can-start-deep-research-and-sees-the-plan-req-research-001
	 */
	#[NoAdminRequired]
	public function create(string $agentId = '', string $conversationId = '', string $question = '', string $depth = 'thorough'): JSONResponse {
		$uid = $this->uid();
		if ($uid === '') {
			return new JSONResponse(data: ['error' => 'Authentication required'], statusCode: 401);
		}

		try {
			$request = new ResearchRequest(agentId: $agentId, conversationId: $conversationId, question: $question, depth: $depth);
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 400);
		}

		$run = $this->research->start(request: $request, userId: $uid);
		if ($run === null) {
			return new JSONResponse(data: ['error' => 'Not found'], statusCode: 404);
		}

		return new JSONResponse(data: $run, statusCode: 201);
	}//end create()

	/**
	 * One of the caller's research runs.
	 *
	 * @param string $id The run.
	 *
	 * @return JSONResponse 200 with the run, 401 signed out, 404 when it is not the caller's.
	 *
	 * @spec openspec/changes/tools-deep-research-report/specs/deep-research/spec.md#requirement-a-user-can-start-deep-research-and-sees-the-plan-req-research-001
	 */
	#[NoAdminRequired]
	public function show(string $id): JSONResponse {
		$uid = $this->uid();
		if ($uid === '') {
			return new JSONResponse(data: ['error' => 'Authentication required'], statusCode: 401);
		}

		$run = $this->research->show(handoffId: $id, userId: $uid);
		if ($run === null) {
			return new JSONResponse(data: ['error' => 'Not found'], statusCode: 404);
		}

		return new JSONResponse(data: $run);
	}//end show()

	/**
	 * Stop one of the caller's research runs at its next step.
	 *
	 * @param string $id The run.
	 *
	 * @return JSONResponse 200 when the stop is recorded, 401 signed out,
	 *                      404 when it is not the caller's or no longer running.
	 *
	 * @spec openspec/changes/tools-deep-research-report/specs/deep-research/spec.md#requirement-a-research-run-stops-at-its-caps-the-budget-the-kill-switch-or-the-user-req-research-005
	 */
	#[NoAdminRequired]
	public function stop(string $id): JSONResponse {
		$uid = $this->uid();
		if ($uid === '') {
			return new JSONResponse(data: ['error' => 'Authentication required'], statusCode: 401);
		}

		if ($this->research->stop(handoffId: $id, userId: $uid) === false) {
			return new JSONResponse(data: ['error' => 'Not found'], statusCode: 404);
		}

		return new JSONResponse(data: ['id' => $id, 'stopRequested' => true]);
	}//end stop()

	/**
	 * The signed-in person's uid, '' when signed out.
	 *
	 * @return string
	 */
	private function uid(): string {
		return (string)($this->userSession->getUser()?->getUID() ?? '');
	}//end uid()
}//end class

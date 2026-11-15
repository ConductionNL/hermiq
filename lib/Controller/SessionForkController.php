<?php

/**
 * Hermiq SessionForkController.
 *
 * POST /api/sessions/{uuid}/fork with { turnUuid }: the owner starts a new
 * session from one of the session's answers ("Fork from here").
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
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
 * @spec openspec/changes/chat-fork-and-share-link/specs/session-fork/spec.md#requirement-the-owner-can-fork-a-session-at-an-answer-req-sfork-001
 */

declare(strict_types=1);

namespace OCA\Hermiq\Controller;

use OCA\Hermiq\Kernel\AppIdentity;
use OCA\Hermiq\Service\SessionForkException;
use OCA\Hermiq\Service\SessionForkService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Forks a session at an answer; the owner only (the service checks ownership).
 *
 * @spec openspec/changes/chat-fork-and-share-link/specs/session-fork/spec.md#requirement-the-owner-can-fork-a-session-at-an-answer-req-sfork-001
 */
class SessionForkController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest           $request     The request.
	 * @param SessionForkService $forks       Forks a session (owner check inside).
	 * @param IUserSession       $userSession Resolves the caller.
	 *
	 * @spec openspec/changes/chat-fork-and-share-link/specs/session-fork/spec.md#requirement-the-owner-can-fork-a-session-at-an-answer-req-sfork-001
	 */
	public function __construct(
		IRequest $request,
		private readonly SessionForkService $forks,
		private readonly IUserSession $userSession,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Fork a session the caller owns at one of its answers (body: turnUuid).
	 *
	 * A session the caller does not own answers 404, so this route cannot tell
	 * whether it exists; a turn that is not an answer of it answers 400.
	 *
	 * @param string $uuid The source session uuid.
	 *
	 * @return JSONResponse 201 with the new session, or an error.
	 *
	 * @spec openspec/changes/chat-fork-and-share-link/specs/session-fork/spec.md#requirement-the-owner-can-fork-a-session-at-an-answer-req-sfork-001
	 */
	#[NoAdminRequired]
	public function create(string $uuid): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['error' => 'Authentication required'], statusCode: 401);
		}

		try {
			$fork = $this->forks->fork(
				uuid: $uuid,
				turnUuid: trim((string)$this->request->getParam('turnUuid', '')),
				callerUid: $user->getUID()
			);
		} catch (SessionForkException $e) {
			return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: $e->getCode());
		}

		$data = $fork['session']->getObject();

		return new JSONResponse(
			data: [
				'uuid' => $fork['session']->getUuid(),
				'title' => ($data['title'] ?? null),
				'agentId' => ($data['agentId'] ?? null),
				'forkedFrom' => ($data['forkedFrom'] ?? null),
				'messageCount' => $fork['turns'],
			],
			statusCode: 201
		);
	}//end create()
}//end class

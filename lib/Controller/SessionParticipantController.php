<?php

/**
 * Participant routes for a web session (chat-work-together-in-one-session).
 *
 * Thin: SessionParticipantService decides. Every refusal carries its status
 * (404 not the owner, 409 Talk-bound, 400 owner or unknown user).
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
 * @spec openspec/specs/session-participants/spec.md#requirement-the-owner-invites-colleagues-into-a-session-req-spart-001
 */

declare(strict_types=1);

namespace OCA\Hermiq\Controller;

use OCA\Hermiq\AppInfo\Application;
use OCA\Hermiq\Service\SessionParticipantException;
use OCA\Hermiq\Service\SessionParticipantService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * List, add and remove the participants of a session; the owner only.
 *
 * @spec openspec/specs/session-participants/spec.md#requirement-the-owner-invites-colleagues-into-a-session-req-spart-001
 */
class SessionParticipantController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param SessionParticipantService $participants The roster service (owner check inside).
	 * @param IUserSession $userSession Resolves the caller.
	 *
	 * @spec openspec/specs/session-participants/spec.md#requirement-the-owner-invites-colleagues-into-a-session-req-spart-001
	 */
	public function __construct(
		IRequest $request,
		private readonly SessionParticipantService $participants,
		private readonly IUserSession $userSession,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The participants of a session the caller owns.
	 *
	 * @param string $uuid The session uuid.
	 *
	 * @return JSONResponse The roster (results: uid and displayName each) or an error.
	 *
	 * @spec openspec/specs/session-participants/spec.md#requirement-the-owner-invites-colleagues-into-a-session-req-spart-001
	 */
	#[NoAdminRequired]
	public function index(string $uuid): JSONResponse {
		return $this->respond(action: fn (string $caller): array => $this->participants->list(uuid: $uuid, callerUid: $caller));
	}//end index()

	/**
	 * Add a colleague (body: uid) to a session the caller owns.
	 *
	 * @param string $uuid The session uuid.
	 *
	 * @return JSONResponse The roster (results: uid and displayName each) or an error.
	 *
	 * @spec openspec/specs/session-participants/spec.md#requirement-the-owner-invites-colleagues-into-a-session-req-spart-001
	 */
	#[NoAdminRequired]
	public function create(string $uuid): JSONResponse {
		$uid = trim((string)$this->request->getParam('uid', ''));
		return $this->respond(action: fn (string $caller): array => $this->participants->add(uuid: $uuid, callerUid: $caller, uid: $uid));
	}//end create()

	/**
	 * Take a colleague off a session the caller owns.
	 *
	 * @param string $uuid The session uuid.
	 * @param string $uid The colleague.
	 *
	 * @return JSONResponse The roster (results: uid and displayName each) or an error.
	 *
	 * @spec openspec/specs/session-participants/spec.md#requirement-the-owner-invites-colleagues-into-a-session-req-spart-001
	 */
	#[NoAdminRequired]
	public function destroy(string $uuid, string $uid): JSONResponse {
		return $this->respond(action: fn (string $caller): array => $this->participants->remove(uuid: $uuid, callerUid: $caller, uid: $uid));
	}//end destroy()

	/**
	 * Run one roster action as the caller and map a refusal to its status.
	 *
	 * @param callable(string): array $action The action, given the caller's uid.
	 *
	 * @return JSONResponse The response.
	 */
	private function respond(callable $action): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['error' => 'Authentication required'], statusCode: 401);
		}

		try {
			return new JSONResponse(data: ['results' => $action($user->getUID())], statusCode: 200);
		} catch (SessionParticipantException $e) {
			return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: $e->getCode());
		}
	}//end respond()
}//end class

<?php

/**
 * Share routes for a session (chat-fork-and-share-link).
 *
 * Thin: SessionShareService decides. Every refusal carries its status (404
 * not the owner, 400 an unknown recipient or type, 403 a share the
 * instance's sharing settings do not allow).
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
 * @spec openspec/changes/chat-fork-and-share-link/specs/session-sharing/spec.md#requirement-the-owner-shares-a-session-with-named-users-or-groups-req-sshare-001
 */

declare(strict_types=1);

namespace OCA\Hermiq\Controller;

use OCA\Hermiq\Kernel\AppIdentity;
use OCA\Hermiq\Kernel\Contracts\SessionShareServiceInterface;
use OCA\Hermiq\Service\SessionShareException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * List, add and remove the read-only shares of a session; the owner check is in the service.
 *
 * @spec openspec/changes/chat-fork-and-share-link/specs/session-sharing/spec.md#requirement-the-owner-shares-a-session-with-named-users-or-groups-req-sshare-001
 */
class SessionShareController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest                     $request     The request.
	 * @param SessionShareServiceInterface $shares      Decides and stores the shares.
	 * @param IUserSession                 $userSession The signed-in user.
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly SessionShareServiceInterface $shares,
		private readonly IUserSession $userSession,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The session's shares, for its owner.
	 *
	 * @param string $uuid The session uuid.
	 *
	 * @return JSONResponse `{results}`, 401 or 404.
	 *
	 * @spec openspec/changes/chat-fork-and-share-link/specs/session-sharing/spec.md#requirement-the-owner-shares-a-session-with-named-users-or-groups-req-sshare-001
	 */
	#[NoAdminRequired]
	public function index(string $uuid): JSONResponse {
		return $this->respond(action: fn (string $caller): array => $this->shares->list(uuid: $uuid, callerUid: $caller));
	}//end index()

	/**
	 * Share the session with `{type, id}`.
	 *
	 * @param string $uuid The session uuid.
	 *
	 * @return JSONResponse `{results}`, 400, 401, 403 or 404.
	 *
	 * @spec openspec/changes/chat-fork-and-share-link/specs/session-sharing/spec.md#requirement-the-owner-shares-a-session-with-named-users-or-groups-req-sshare-001
	 */
	#[NoAdminRequired]
	public function create(string $uuid): JSONResponse {
		$type = trim((string)$this->request->getParam('type', ''));
		$id   = trim((string)$this->request->getParam('id', ''));

		return $this->respond(
			action: fn (string $caller): array => $this->shares->add(uuid: $uuid, callerUid: $caller, type: $type, id: $id)
		);
	}//end create()

	/**
	 * Take a share back.
	 *
	 * @param string $uuid The session uuid.
	 * @param string $type `user` or `group`.
	 * @param string $id   The user id or group id.
	 *
	 * @return JSONResponse `{results}`, 401 or 404.
	 *
	 * @spec openspec/changes/chat-fork-and-share-link/specs/session-sharing/spec.md#requirement-the-owner-can-revoke-a-share-at-once-req-sshare-003
	 */
	#[NoAdminRequired]
	public function destroy(string $uuid, string $type, string $id): JSONResponse {
		return $this->respond(
			action: fn (string $caller): array => $this->shares->remove(uuid: $uuid, callerUid: $caller, type: $type, id: $id)
		);
	}//end destroy()

	/**
	 * Run the action as the signed-in user and answer its result or its refusal.
	 *
	 * @param callable(string): array<int, mixed> $action The action, given the caller's uid.
	 *
	 * @return JSONResponse
	 */
	private function respond(callable $action): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['error' => 'Authentication required'], statusCode: 401);
		}

		try {
			return new JSONResponse(data: ['results' => $action($user->getUID())], statusCode: 200);
		} catch (SessionShareException $e) {
			return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: $e->getCode());
		}
	}//end respond()
}//end class

<?php

/**
 * Hermiq action gate.
 *
 * The ADR-023 gate an action-guarded route asks first: the signed-in user, or the
 * refusal — 401 when no one is signed in (before any action check), 403 with the
 * refusal message when the action is not theirs. Built by the controllers that guard
 * routes this way, from their own session and action-authorization service.
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
 * @spec openspec/architecture/adr-023-action-authorization.md
 */

declare(strict_types=1);

namespace OCA\Hermiq\Controller;

use OCA\Hermiq\Service\ActionAuthService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IUser;
use OCP\IUserSession;

/**
 * The signed-in, authorized user of an action-guarded route.
 *
 * @spec openspec/architecture/adr-023-action-authorization.md
 */
final class ActionGate {

	/**
	 * The gate.
	 *
	 * @param IUserSession $userSession Resolves the requesting user.
	 * @param ActionAuthService $actionAuth ADR-023 action authorization.
	 */
	public function __construct(
		private readonly IUserSession $userSession,
		private readonly ActionAuthService $actionAuth,
	) {
	}//end __construct()

	/**
	 * The signed-in user when they may perform the action, otherwise the refusal.
	 *
	 * @param string $action The ADR-023 action name to require.
	 *
	 * @return IUser|JSONResponse
	 *
	 * @spec openspec/architecture/adr-023-action-authorization.md
	 */
	public function authorise(string $action): IUser|JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$this->actionAuth->requireAction(user: $user, action: $action);
		} catch (OCSForbiddenException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
		}

		return $user;
	}//end authorise()
}//end class

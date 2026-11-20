<?php

/**
 * Hermiq SubscriptionLoginController.
 *
 * The session user's subscription sign-in (models-provider-connections D5):
 * which sign-ins are offered, start one, finish one. Every route acts for the
 * session user only, and the finish answers the new credential's id and label,
 * never the token.
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
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Hermiq\Controller;

use OCA\Hermiq\Kernel\AppIdentity;
use OCA\Hermiq\Service\Credential\SubscriptionLoginException;
use OCA\Hermiq\Service\Credential\SubscriptionLoginService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Subscription sign-in for the session user.
 */
class SubscriptionLoginController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest                 $request     The request.
	 * @param SubscriptionLoginService $service     The sign-in flow.
	 * @param IUserSession             $userSession The session user.
	 */
	public function __construct(
		IRequest $request,
		private readonly SubscriptionLoginService $service,
		private readonly IUserSession $userSession,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Which subscription sign-ins are offered.
	 *
	 * @return JSONResponse `{available, providers}`.
	 *
	 * @spec openspec/changes/models-provider-connections
	 */
	#[NoAdminRequired]
	public function index(): JSONResponse {
		$uid = $this->uid();
		if ($uid === null) {
			return new JSONResponse(['message' => 'Unauthorized'], Http::STATUS_UNAUTHORIZED);
		}

		return new JSONResponse($this->service->offered(uid: $uid));
	}//end index()

	/**
	 * Start a sign-in.
	 *
	 * @param string $provider `anthropic` or `openai`.
	 *
	 * @return JSONResponse `{loginId, url, mode, userCode}`.
	 *
	 * @spec openspec/changes/models-provider-connections
	 */
	#[NoAdminRequired]
	public function start(string $provider = ''): JSONResponse {
		$uid = $this->uid();
		if ($uid === null) {
			return new JSONResponse(['message' => 'Unauthorized'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			return new JSONResponse($this->service->start(uid: $uid, provider: $provider));
		} catch (SubscriptionLoginException $e) {
			return $this->refusal(exception: $e);
		}
	}//end start()

	/**
	 * Finish a sign-in with the code the vendor's page showed.
	 *
	 * @param string $id   The login id.
	 * @param string $code The code ('' for a device sign-in).
	 *
	 * @return JSONResponse `{id, label}` of the new personal credential.
	 *
	 * @spec openspec/changes/models-provider-connections
	 */
	#[NoAdminRequired]
	public function code(string $id, string $code = ''): JSONResponse {
		$uid = $this->uid();
		if ($uid === null) {
			return new JSONResponse(['message' => 'Unauthorized'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			return new JSONResponse($this->service->finish(uid: $uid, loginId: $id, code: $code));
		} catch (SubscriptionLoginException $e) {
			return $this->refusal(exception: $e);
		}
	}//end code()

	/**
	 * A refusal with the status the service named.
	 *
	 * @param SubscriptionLoginException $exception The refusal.
	 *
	 * @return JSONResponse The response.
	 */
	private function refusal(SubscriptionLoginException $exception): JSONResponse {
		$status = $exception->getCode();
		if ($status < 400 || $status > 599) {
			$status = Http::STATUS_INTERNAL_SERVER_ERROR;
		}

		return new JSONResponse(['message' => $exception->getMessage()], $status);
	}//end refusal()

	/**
	 * The session user's id.
	 *
	 * @return string|null The uid, or null without a session.
	 */
	private function uid(): ?string {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return null;
		}

		return $user->getUID();
	}//end uid()
}//end class

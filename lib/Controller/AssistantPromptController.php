<?php

/**
 * Hermiq AssistantPromptController.
 *
 * The prompt library: read by anybody who may use the assistant, written only by
 * somebody the action matrix permits (ADR-023). Reading is open because the surface
 * that offers the prompts needs them; writing is gated because the prompt is what
 * the model is told, and what the model is told is not an ordinary user's to change.
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
 * @spec openspec/changes/the-declared-tool-surface-and-the-prompt-library/specs/ai-feature-admin-surface/spec.md#requirement-the-prompts-the-assistant-offers-must-be-administered-objects
 */

declare(strict_types=1);

namespace OCA\Hermiq\Controller;

use OCA\Hermiq\AppInfo\Application;
use OCA\Hermiq\Service\ActionAuthService;
use OCA\Hermiq\Service\Assistant\AssistantPromptLibrary;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Read, administer and switch off the assistant's prompts.
 *
 * @spec openspec/changes/the-declared-tool-surface-and-the-prompt-library/specs/ai-feature-admin-surface/spec.md#requirement-disabling-every-prompt-must-be-one-recorded-act
 */
class AssistantPromptController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request object.
	 * @param AssistantPromptLibrary $library The prompt library.
	 * @param ActionAuthService $actionAuth The ADR-023 action-authorization service.
	 * @param IUserSession $userSession Resolves the requesting user.
	 * @param LoggerInterface $logger PSR-3 logger.
	 */
	public function __construct(
		IRequest $request,
		private readonly AssistantPromptLibrary $library,
		private readonly ActionAuthService $actionAuth,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);

	}//end __construct()

	/**
	 * The library, in the administrator's order. With a `scope` parameter, only the
	 * prompts offered on that record type, which is what a case surface asks for.
	 *
	 * @return JSONResponse The prompts.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/the-declared-tool-surface-and-the-prompt-library/specs/ai-feature-admin-surface/spec.md#scenario-order-is-the-administrators
	 */
	public function index(): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['error' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$scope = $this->request->getParam('scope');

		try {
			if (is_string($scope) === true && trim($scope) !== '') {
				$prompts = $this->library->forScope(usageScope: trim($scope));
			} else {
				$prompts = $this->library->all();
			}
		} catch (Throwable $e) {
			$this->logger->error('Hermiq prompt library read failed: ' . $e->getMessage(), ['exception' => $e]);
			return new JSONResponse(['error' => 'Could not read the prompt library'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new JSONResponse(['results' => $prompts, 'total' => count($prompts)]);
	}//end index()

	/**
	 * Create or update one prompt (action-auth-gated).
	 *
	 * @param string|null $id The prompt uuid, absent when creating.
	 *
	 * @return JSONResponse The stored prompt.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/the-declared-tool-surface-and-the-prompt-library/specs/ai-feature-admin-surface/spec.md#scenario-the-text-that-will-be-sent-is-the-text-on-screen
	 */
	public function save(?string $id = null): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$this->actionAuth->requireAction(user: $user, action: 'assistantprompt.administer');
		} catch (OCSForbiddenException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
		}

		$payload = [];
		foreach (['label', 'prompt', 'usageScope', 'order', 'enabled'] as $field) {
			$value = $this->request->getParam($field);
			if ($value !== null) {
				$payload[$field] = $value;
			}
		}

		try {
			return new JSONResponse($this->library->upsert(id: $id, payload: $payload));
		} catch (Throwable $e) {
			$this->logger->error('Hermiq prompt save failed: ' . $e->getMessage(), ['exception' => $e]);
			return new JSONResponse(['error' => 'Could not save the prompt'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

	}//end save()

	/**
	 * Disable every prompt, wholesale or within one scope, in one act
	 * (action-auth-gated). There is deliberately no counterpart: re-enabling is per
	 * prompt, through `save()`.
	 *
	 * @return JSONResponse How many were switched off.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/the-declared-tool-surface-and-the-prompt-library/specs/ai-feature-admin-surface/spec.md#scenario-everything-stops-in-one-act
	 */
	public function disableAll(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$this->actionAuth->requireAction(user: $user, action: 'assistantprompt.disable-all');
		} catch (OCSForbiddenException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
		}

		$scope = $this->request->getParam('scope');
		$usageScope = null;
		if (is_string($scope) === true && trim($scope) !== '') {
			$usageScope = trim($scope);
		}

		try {
			$disabled = $this->library->disableAll(usageScope: $usageScope, actor: $user->getUID());
		} catch (Throwable $e) {
			$this->logger->error('Hermiq prompt disable-all failed: ' . $e->getMessage(), ['exception' => $e]);
			return new JSONResponse(['error' => 'Could not disable the prompts'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new JSONResponse(['disabled' => $disabled, 'scope' => ($usageScope ?? '*')]);
	}//end disableAll()
}//end class

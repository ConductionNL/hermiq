<?php

/**
 * Hermiq AgentDraftController.
 *
 * `POST /api/agents/draft-check` (agents-plain-language-builder): checks an
 * agent draft from chat before the agent form opens with it. Reads only; the
 * agent is created later by the person's own save through the normal path.
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
 * @spec openspec/changes/agents-plain-language-builder/specs/agent-management-ui/spec.md#requirement-a-draft-opens-in-the-full-agent-form-after-a-check-req-agbuild-002
 */

declare(strict_types=1);

namespace OCA\Hermiq\Controller;

use OCA\Hermiq\AppInfo\Application;
use OCA\Hermiq\Service\Agent\AgentDraftService;
use OCA\Hermiq\Service\Agent\AgentDraftUnreadableException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Check an agent draft from chat.
 *
 * @spec openspec/changes/agents-plain-language-builder/specs/agent-management-ui/spec.md#requirement-a-draft-opens-in-the-full-agent-form-after-a-check-req-agbuild-002
 */
class AgentDraftController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest          $request     The request.
	 * @param AgentDraftService $drafts      The draft check.
	 * @param IUserSession      $userSession The signed-in user.
	 * @param LoggerInterface   $logger      PSR-3 logger.
	 */
	public function __construct(
		IRequest $request,
		private readonly AgentDraftService $drafts,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The draft as the form reads it, with its findings. Body: `draft`, the block's text.
	 *
	 * @return JSONResponse `{draft, findings}`, or 422 when the draft cannot be read.
	 *
	 * @spec openspec/changes/agents-plain-language-builder/specs/agent-management-ui/spec.md#requirement-a-draft-opens-in-the-full-agent-form-after-a-check-req-agbuild-002
	 */
	#[NoAdminRequired]
	public function check(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$text = $this->request->getParam('draft', '');
		if (is_string($text) === false) {
			$text = '';
		}

		try {
			return new JSONResponse($this->drafts->check(text: $text, uid: $user->getUID()));
		} catch (AgentDraftUnreadableException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		} catch (Throwable $e) {
			$this->logger->error('[hermiq] Agent draft check failed: ' . $e->getMessage(), ['exception' => $e]);
			return new JSONResponse(['error' => 'The draft could not be checked'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}//end check()
}//end class

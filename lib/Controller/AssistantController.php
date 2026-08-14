<?php

/**
 * Hermiq AssistantController.
 *
 * The minimal, tool-free conversational surface (case-assistant-surface):
 * `POST /api/assistant/converse`. Deliberately separate from `ChatController`
 * — see `AssistantService`'s docblock and
 * openspec/changes/case-assistant-surface/design.md for why.
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
 * @spec openspec/changes/case-assistant-surface/tasks.md#task-2-1
 */

declare(strict_types=1);

namespace OCA\Hermiq\Controller;

use Exception;
use OCA\Hermiq\AppInfo\Application;
use OCA\Hermiq\Service\Assistant\AssistantService;
use OCA\Hermiq\Service\GuardrailBlockedException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * AssistantController handles the case-assistant-surface conversational endpoint.
 *
 * @spec openspec/changes/case-assistant-surface/tasks.md#task-2-1
 */
class AssistantController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request object.
	 * @param AssistantService $assistantService Turn orchestration (case-assistant-surface).
	 * @param IUserSession $userSession Resolves the requesting user.
	 * @param IL10N $l10n Localization service for translations.
	 * @param LoggerInterface $logger PSR-3 logger.
	 *
	 * @spec openspec/changes/case-assistant-surface/tasks.md#task-2-1
	 */
	public function __construct(
		IRequest $request,
		private readonly AssistantService $assistantService,
		private readonly IUserSession $userSession,
		private readonly IL10N $l10n,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Run one conversational turn against the case-assistant surface.
	 *
	 * @return JSONResponse `{sessionId, reply, usage}` on success, or a mapped error.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/case-assistant-surface/tasks.md#task-2-1
	 */
	public function converse(): JSONResponse {
		try {
			$userId = $this->requireUserId();

			$sessionId = $this->request->getParam('sessionId');
			if (is_string($sessionId) === false || $sessionId === '') {
				$sessionId = null;
			}

			$message = (string)$this->request->getParam('message', '');

			$contextParam = $this->request->getParam('context');
			$context = [];
			if (is_array($contextParam) === true) {
				$context = $contextParam;
			}

			$result = $this->assistantService->converse(
				userId: $userId,
				sessionId: $sessionId,
				message: $message,
				context: $context
			);

			return new JSONResponse(data: $result, statusCode: 200);
		} catch (Exception $e) {
			$statusCode = (int)$e->getCode();
			if ($statusCode < 400 || $statusCode >= 600) {
				$statusCode = 500;
			}

			$this->logConverseFailure(exception: $e, statusCode: $statusCode);

			$errorType = match ($statusCode) {
				400 => $this->l10n->t('Invalid request'),
				401 => $this->l10n->t('Authentication required'),
				403 => $this->l10n->t('Access denied'),
				404 => $this->l10n->t('Session not found'),
				422 => $this->l10n->t('Message blocked by the organisation\'s guardrail policy'),
				503 => $this->l10n->t('AI service not configured'),
				default => $this->l10n->t('Failed to process message'),
			};

			$data = [
				'error' => $errorType,
				'message' => $e->getMessage(),
			];

			if ($e instanceof GuardrailBlockedException) {
				$data['errorCode'] = 'guardrail_blocked';
			}

			return new JSONResponse(data: $data, statusCode: $statusCode);
		}//end try
	}//end converse()

	/**
	 * Run one stateless, structured PII/redaction-span detection call
	 * (woo-llm-anonymisation).
	 *
	 * @return JSONResponse `{spans, usage}` on success, or a mapped error.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/woo-llm-anonymisation/tasks.md#task-2-1
	 */
	public function detectPii(): JSONResponse {
		try {
			$userId = $this->requireUserId();

			$text = (string)$this->request->getParam('text', '');

			$contextParam = $this->request->getParam('context');
			$context = [];
			if (is_array($contextParam) === true) {
				$context = $contextParam;
			}

			$result = $this->assistantService->detectPii(
				userId: $userId,
				text: $text,
				context: $context
			);

			return new JSONResponse(data: $result, statusCode: 200);
		} catch (Exception $e) {
			$statusCode = (int)$e->getCode();
			if ($statusCode < 400 || $statusCode >= 600) {
				$statusCode = 500;
			}

			$this->logConverseFailure(exception: $e, statusCode: $statusCode);

			$errorType = match ($statusCode) {
				400 => $this->l10n->t('Invalid request'),
				401 => $this->l10n->t('Authentication required'),
				422 => $this->l10n->t('Text blocked by the organisation\'s guardrail policy'),
				502 => $this->l10n->t('AI response could not be parsed'),
				503 => $this->l10n->t('AI service not configured'),
				default => $this->l10n->t('Failed to process text'),
			};

			$data = [
				'error' => $errorType,
				'message' => $e->getMessage(),
			];

			if ($e instanceof GuardrailBlockedException) {
				$data['errorCode'] = 'guardrail_blocked';
			}

			return new JSONResponse(data: $data, statusCode: $statusCode);
		}//end try
	}//end detectPii()

	/**
	 * Log a converse() failure at the level matching its severity.
	 *
	 * @param Exception $exception The caught exception.
	 * @param int $statusCode The resolved HTTP status code (already clamped to 400-599).
	 *
	 * @return void
	 *
	 * @spec exclude Log-level-only helper split out of converse() to stay under
	 * the phpmd method-length threshold; no behavioural contract beyond the log level.
	 */
	private function logConverseFailure(Exception $exception, int $statusCode): void {
		if ($statusCode < 500) {
			$this->logger->warning(
				message: '[AssistantController] Rejected message: ' . $exception->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
			return;
		}

		$this->logger->error(
			message: '[AssistantController] Failed to process message',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'error' => $exception->getMessage(),
				'trace' => $exception->getTraceAsString(),
			]
		);
	}//end logConverseFailure()

	/**
	 * Resolve the current user id or throw a 401-coded exception.
	 *
	 * @return string The current user id.
	 *
	 * @throws Exception If no user is authenticated (code 401).
	 *
	 * @spec openspec/changes/case-assistant-surface/tasks.md#task-2-1
	 */
	private function requireUserId(): string {
		$user = $this->userSession->getUser();
		if ($user === null) {
			throw new Exception('Authentication required', 401);
		}

		return $user->getUID();
	}//end requireUserId()
}//end class

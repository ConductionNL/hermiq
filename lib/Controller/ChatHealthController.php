<?php

/**
 * Hermiq ChatHealthController.
 *
 * Lightweight health probe for the in-app AI chat backend, ported route-for-route
 * from OpenRegister's ChatHealthController (agent-engine-port). Allows the
 * nextcloud-vue AI companion widget to detect at mount time whether the chat
 * backend is configured and reachable — without requiring a Nextcloud session
 * (PublicPage). Provider configuration is read from the `hermiq.llm` app-config
 * key via LlmSettingsHandler (the ported equivalent of OR's SettingsService
 * LLM read).
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
 * @spec openspec/changes/agent-engine-port/tasks.md#task-4-1
 */

declare(strict_types=1);

namespace OCA\Hermiq\Controller;

use OCA\Hermiq\AppInfo\Application;
use OCA\Hermiq\Service\Llm\LlmSettingsHandler;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Provides GET /api/chat/health — an unauthenticated endpoint the nextcloud-vue
 * widget probes once at mount time to decide whether to render the AI companion
 * button. Returns HTTP 200 + {status:"ok",capabilities:["chat","stream"]} when at
 * least one LLM chat provider is configured; HTTP 503 + {status:"no_provider"}
 * otherwise; HTTP 503 + {status:"config_error"} when the config read itself fails.
 *
 * @spec openspec/changes/agent-engine-port/tasks.md#task-4-1
 */
class ChatHealthController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request object.
	 * @param LlmSettingsHandler $llmSettings Reads the `hermiq.llm` provider configuration.
	 * @param LoggerInterface $logger PSR-3 logger for surfacing config errors.
	 *
	 * @spec openspec/changes/agent-engine-port/tasks.md#task-4-1
	 */
	public function __construct(
		IRequest $request,
		private readonly LlmSettingsHandler $llmSettings,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Health probe for the AI chat backend.
	 *
	 * Returns 200 when a chat provider is configured, 503 otherwise. Annotated
	 * as PublicPage so the widget can probe without authentication (mirrors
	 * OR's ChatHealthController::health()). A failing config read is reported
	 * as `config_error` (not `no_provider`) so operators can tell a fresh
	 * instance from a broken config service; the widget treats any non-200 as
	 * "no AI" without branching.
	 *
	 * @return JSONResponse 200 or 503 JSON response.
	 *
	 * @PublicPage
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/agent-engine-port/tasks.md#task-4-1
	 */
	public function health(): JSONResponse {
		try {
			$llmConfig = $this->llmSettings->getLLMSettingsOnly();
			$chatProvider = ($llmConfig['chatProvider'] ?? null);

			if (empty($chatProvider) === true) {
				return new JSONResponse(
					data: ['status' => 'no_provider'],
					statusCode: 503
				);
			}

			return new JSONResponse(
				data: [
					'status' => 'ok',
					'capabilities' => ['chat', 'stream'],
				],
				statusCode: 200
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				message: '[ChatHealthController] Health probe failed reading LLM settings',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'exception' => $e,
					'error' => $e->getMessage(),
				]
			);
			return new JSONResponse(
				data: ['status' => 'config_error'],
				statusCode: 503
			);
		}//end try
	}//end health()
}//end class

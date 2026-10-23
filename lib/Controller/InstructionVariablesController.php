<?php

/**
 * Hermiq InstructionVariablesController.
 *
 * The two endpoints of agents-instruction-variables: the owner's preview of an
 * agent's filled-in instructions (POST /api/agents/{id}/prompt-preview) and a
 * person's answers to the agent's start fields for their session
 * (PUT /api/sessions/{uuid}/start-values). Both checks live in
 * InstructionVariablesService; this class maps its outcome to a response.
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
 * @spec openspec/specs/agent-management-ui/spec.md#requirement-placeholders-in-an-agents-instructions-are-filled-in-per-turn-req-agvar-001
 */

declare(strict_types=1);

namespace OCA\Hermiq\Controller;

use OCA\Hermiq\AppInfo\Application;
use OCA\Hermiq\Service\Agent\InstructionVariablesService;
use OCA\Hermiq\Service\Agent\StartValuesRejectedException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Prompt preview and start field answers.
 *
 * @spec openspec/specs/agent-management-ui/spec.md#requirement-placeholders-in-an-agents-instructions-are-filled-in-per-turn-req-agvar-001
 */
class InstructionVariablesController extends Controller {

	/**
	 * Statuses the service answers with on purpose.
	 *
	 * @var array<int, int>
	 */
	private const REFUSALS = [Http::STATUS_NOT_FOUND, Http::STATUS_CONFLICT];

	/**
	 * Constructor.
	 *
	 * @param IRequest                    $request     The request.
	 * @param InstructionVariablesService $variables   The preview and the answers.
	 * @param IUserSession                $userSession The signed-in user.
	 * @param LoggerInterface             $logger      PSR-3 logger.
	 */
	public function __construct(
		IRequest $request,
		private readonly InstructionVariablesService $variables,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The agent's instructions filled in for its owner.
	 *
	 * Body: `prompt` (optional, the form's unsaved text), `startFields`
	 * (optional, the form's unsaved fields) and `sampleValues` (answers per key).
	 *
	 * @param string $id The agent.
	 *
	 * @return JSONResponse `{text, unknown}`, or 404 for anyone but the owner.
	 *
	 * @spec openspec/specs/agent-management-ui/spec.md#requirement-placeholders-in-an-agents-instructions-are-filled-in-per-turn-req-agvar-001
	 */
	#[NoAdminRequired]
	public function preview(string $id): JSONResponse {
		$prompt = $this->request->getParam('prompt');
		if (is_string($prompt) === false) {
			$prompt = null;
		}

		$fields = $this->request->getParam('startFields');
		if (is_array($fields) === false) {
			$fields = null;
		}

		return $this->run(
			call: fn (string $uid): array => $this->variables->preview(
				agentId: $id,
				uid: $uid,
				prompt: $prompt,
				startFields: $fields,
				sampleValues: (array)$this->request->getParam('sampleValues', [])
			)
		);
	}//end preview()

	/**
	 * Store the caller's answers to the agent's start fields on their session.
	 *
	 * Body: `values`, the answers per field key.
	 *
	 * @param string $uuid The session.
	 *
	 * @return JSONResponse `{uuid, startValues}`, 404, 409, or 422 with `problems` per field.
	 *
	 * @spec openspec/specs/agent-management-ui/spec.md#requirement-an-agent-can-ask-for-fields-before-a-conversation-starts-req-agvar-002
	 */
	#[NoAdminRequired]
	public function answer(string $uuid): JSONResponse {
		return $this->run(
			call: function (string $uid) use ($uuid): array {
				$session = $this->variables->answer(
					sessionId: $uuid,
					uid: $uid,
					values: (array)$this->request->getParam('values', [])
				);
				return [
					'uuid' => (string)$session->getUuid(),
					'startValues' => (array)($session->getObject()['startValues'] ?? []),
				];
			}
		);
	}//end answer()

	/**
	 * Run a service call for the signed-in user and map its outcome to a response.
	 *
	 * @param callable $call Takes the uid, returns the response data.
	 *
	 * @return JSONResponse
	 */
	private function run(callable $call): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			return new JSONResponse($call($user->getUID()));
		} catch (StartValuesRejectedException $e) {
			return new JSONResponse(['error' => $e->getMessage(), 'problems' => $e->getProblems()], Http::STATUS_UNPROCESSABLE_ENTITY);
		} catch (RuntimeException $e) {
			if (in_array($e->getCode(), self::REFUSALS, true) === true) {
				return new JSONResponse(['error' => $e->getMessage()], $e->getCode());
			}

			$this->logger->error('[hermiq] Instruction variables failed: ' . $e->getMessage(), ['exception' => $e]);
			return new JSONResponse(['error' => 'Something went wrong'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}//end run()
}//end class

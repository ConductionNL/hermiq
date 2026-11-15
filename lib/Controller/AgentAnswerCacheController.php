<?php

/**
 * Hermiq AgentAnswerCacheController.
 *
 * "Clear cached answers" on an agent (models-prompt-and-answer-cache, task 7):
 * the agent's owner removes every answer the cache holds for it.
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
 * @spec openspec/changes/models-prompt-and-answer-cache/specs/answer-cache/spec.md#requirement-cached-answers-can-be-cleared-and-expire-req-acache-004
 */

declare(strict_types=1);

namespace OCA\Hermiq\Controller;

use OCA\Hermiq\Kernel\AppIdentity;
use OCA\Hermiq\Kernel\Contracts\AgentAccessServiceInterface;
use OCA\Hermiq\Service\AnswerCache\AnswerCacheService;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Clears an agent's cached answers, for its owner only.
 *
 * @spec openspec/changes/models-prompt-and-answer-cache/specs/answer-cache/spec.md#requirement-cached-answers-can-be-cleared-and-expire-req-acache-004
 */
class AgentAnswerCacheController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest                    $request     The request.
	 * @param ObjectService               $objects     Reads the agent.
	 * @param IUserSession                $session     The caller.
	 * @param AgentAccessServiceInterface $agentAccess Decides who may modify the agent.
	 * @param AnswerCacheService          $cache       Removes the entries.
	 * @param LoggerInterface             $logger      PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly ObjectService $objects,
		private readonly IUserSession $session,
		private readonly AgentAccessServiceInterface $agentAccess,
		private readonly AnswerCacheService $cache,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Remove every cached answer of the agent.
	 *
	 * Owner-only, the same guard as editing or deleting the agent: 403 for
	 * anyone else, 404 for an agent the caller cannot read.
	 *
	 * @param string $id The agent's UUID.
	 *
	 * @return JSONResponse `{removed: n}`, or 403 / 404 / 500.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/models-prompt-and-answer-cache/specs/answer-cache/spec.md#requirement-cached-answers-can-be-cleared-and-expire-req-acache-004
	 */
	public function clear(string $id): JSONResponse {
		$userId = $this->session->getUser()?->getUID();
		if ($userId === null) {
			return new JSONResponse(data: ['error' => 'User not authenticated'], statusCode: Http::STATUS_FORBIDDEN);
		}

		try {
			$agent = $this->objects->find(id: $id, options: new ObjectFindOptions(register: AnswerCacheService::REGISTER, schema: 'agent'));
		} catch (Throwable $error) {
			$agent = null;
		}

		if ($agent === null) {
			return new JSONResponse(data: ['error' => 'Agent not found'], statusCode: Http::STATUS_NOT_FOUND);
		}

		if ($this->agentAccess->canUserModifyAgent(agent: $agent, userId: $userId) === false) {
			return new JSONResponse(
				data: ['error' => 'You do not have permission to clear this agent\'s cached answers'],
				statusCode: Http::STATUS_FORBIDDEN
			);
		}

		try {
			$removed = $this->cache->clearForAgent(agentId: (string)$agent->getUuid());
		} catch (Throwable $error) {
			$this->logger->error('[AgentAnswerCacheController] clearing the cached answers failed', ['exception' => $error->getMessage()]);
			return new JSONResponse(data: ['error' => 'Failed to clear the cached answers'], statusCode: Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new JSONResponse(data: ['removed' => $removed]);
	}//end clear()
}//end class

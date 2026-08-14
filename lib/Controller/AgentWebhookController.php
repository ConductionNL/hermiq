<?php

/**
 * Hermiq AgentWebhookController.
 *
 * Session-authenticated, owner-guarded CRUD for an agent's inbound webhook
 * secret (agent-webhook-trigger): create/rotate/revoke/patch/status. Mirrors
 * `RunNowController`'s IDOR guard shape exactly — every method loads the
 * target Agent WITH RBAC and additionally asserts owner identity, returning
 * 404 (never 403) for a non-owner so they cannot even confirm the agent's
 * existence.
 *
 * Deliberately kept separate from `WebhookTriggerController` (the actual
 * public trigger endpoint) — a `#[PublicPage]` method must never sit in the
 * same class as session-authenticated ones (design.md's Nextcloud Integration
 * section; mirrors the existing split between `RunNowController` and
 * `ChatHealthController`-style PublicPage controllers).
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
 * @spec openspec/changes/agent-webhook-trigger/tasks.md#task-2-agentwebhookcontroller-session-authenticated-owner-guarded-crud
 */

declare(strict_types=1);

namespace OCA\Hermiq\Controller;

use OCA\Hermiq\AppInfo\Application;
use OCA\Hermiq\Service\WebhookSecretService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Owner-scoped webhook-secret lifecycle endpoints for one agent.
 *
 * @spec openspec/changes/agent-webhook-trigger/tasks.md#task-2-agentwebhookcontroller-session-authenticated-owner-guarded-crud
 */
class AgentWebhookController extends Controller {

	/**
	 * OpenRegister register slug that holds Hermiq agent-engine objects.
	 *
	 * @var string
	 */
	private const REGISTER_SLUG = 'hermiq';

	/**
	 * Schema slug for agent objects (agent-engine-port).
	 *
	 * @var string
	 */
	private const AGENT_SCHEMA = 'agent';

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request object.
	 * @param ObjectService $objectService OpenRegister object read (agent ownership check).
	 * @param IUserSession $userSession Resolves the requesting user for the owner guard.
	 * @param WebhookSecretService $webhookSecretService The webhook secret lifecycle service.
	 * @param LoggerInterface $logger PSR-3 logger.
	 */
	public function __construct(
		IRequest $request,
		private readonly ObjectService $objectService,
		private readonly IUserSession $userSession,
		private readonly WebhookSecretService $webhookSecretService,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Create a new webhook secret for the given agent. The plaintext secret is
	 * returned ONLY in this response body.
	 *
	 * @param string $id The agent UUID.
	 *
	 * @return JSONResponse 201 with the plaintext secret, 404 for a non-owner/
	 *                      unknown agent, or 409 when a webhook already exists.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/agent-webhook-trigger/specs/agent-webhook-trigger/spec.md#requirement-a-per-agent-webhook-secret-can-be-created-rotated-and-revoked
	 */
	public function create(string $id): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$agent = $this->loadOwnedAgent(agentId: $id, uid: $user->getUID());
		if ($agent === null) {
			return new JSONResponse(['error' => 'Agent not found'], Http::STATUS_NOT_FOUND);
		}

		try {
			$result = $this->webhookSecretService->create(agentId: $id, owner: $user->getUID());
		} catch (RuntimeException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_CONFLICT);
		} catch (Throwable $e) {
			$this->logger->error('Hermiq webhook create failed: ' . $e->getMessage(), ['exception' => $e]);
			return new JSONResponse(['error' => 'Could not create webhook'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new JSONResponse(
			array_merge(
				['secret' => $result['secret']],
				$this->webhookSecretService->status(webhook: $result['object'])
			),
			Http::STATUS_CREATED
		);

	}//end create()

	/**
	 * Rotate the agent's webhook secret, invalidating the previous one immediately.
	 *
	 * @param string $id The agent UUID.
	 *
	 * @return JSONResponse 200 with the new plaintext secret, or 404 for a
	 *                      non-owner/unknown agent/unconfigured webhook.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/agent-webhook-trigger/specs/agent-webhook-trigger/spec.md#requirement-a-per-agent-webhook-secret-can-be-created-rotated-and-revoked
	 */
	public function rotate(string $id): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$agent = $this->loadOwnedAgent(agentId: $id, uid: $user->getUID());
		if ($agent === null) {
			return new JSONResponse(['error' => 'Agent not found'], Http::STATUS_NOT_FOUND);
		}

		$webhook = $this->webhookSecretService->findForAgent(agentId: $id);
		if ($webhook === null) {
			return new JSONResponse(['error' => 'No webhook configured for this agent'], Http::STATUS_NOT_FOUND);
		}

		try {
			$result = $this->webhookSecretService->rotate(webhook: $webhook);
		} catch (Throwable $e) {
			$this->logger->error('Hermiq webhook rotate failed: ' . $e->getMessage(), ['exception' => $e]);
			return new JSONResponse(['error' => 'Could not rotate webhook'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new JSONResponse(
			array_merge(
				['secret' => $result['secret']],
				$this->webhookSecretService->status(webhook: $result['object'])
			)
		);

	}//end rotate()

	/**
	 * Revoke the agent's webhook secret — disables it without deleting its configuration.
	 *
	 * @param string $id The agent UUID.
	 *
	 * @return JSONResponse The status payload (never a secret), or 404 for a
	 *                      non-owner/unknown agent/unconfigured webhook.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/agent-webhook-trigger/specs/agent-webhook-trigger/spec.md#requirement-a-per-agent-webhook-secret-can-be-created-rotated-and-revoked
	 */
	public function revoke(string $id): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$agent = $this->loadOwnedAgent(agentId: $id, uid: $user->getUID());
		if ($agent === null) {
			return new JSONResponse(['error' => 'Agent not found'], Http::STATUS_NOT_FOUND);
		}

		$webhook = $this->webhookSecretService->findForAgent(agentId: $id);
		if ($webhook === null) {
			return new JSONResponse(['error' => 'No webhook configured for this agent'], Http::STATUS_NOT_FOUND);
		}

		try {
			$updated = $this->webhookSecretService->revoke(webhook: $webhook);
		} catch (Throwable $e) {
			$this->logger->error('Hermiq webhook revoke failed: ' . $e->getMessage(), ['exception' => $e]);
			return new JSONResponse(['error' => 'Could not revoke webhook'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new JSONResponse($this->webhookSecretService->status(webhook: $updated));
	}//end revoke()

	/**
	 * Update only the approval-gate fields (requiresApproval/reviewer/reviewerType).
	 *
	 * @param string $id The agent UUID.
	 *
	 * @return JSONResponse The updated status payload, or 404 for a non-owner/
	 *                      unknown agent/unconfigured webhook.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/agent-webhook-trigger/specs/agent-webhook-trigger/spec.md#requirement-a-per-agent-webhook-secret-can-be-created-rotated-and-revoked
	 */
	public function patch(string $id): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$agent = $this->loadOwnedAgent(agentId: $id, uid: $user->getUID());
		if ($agent === null) {
			return new JSONResponse(['error' => 'Agent not found'], Http::STATUS_NOT_FOUND);
		}

		$webhook = $this->webhookSecretService->findForAgent(agentId: $id);
		if ($webhook === null) {
			return new JSONResponse(['error' => 'No webhook configured for this agent'], Http::STATUS_NOT_FOUND);
		}

		$fields = $this->request->getParams();

		try {
			$updated = $this->webhookSecretService->patch(webhook: $webhook, fields: $fields);
		} catch (Throwable $e) {
			$this->logger->error('Hermiq webhook patch failed: ' . $e->getMessage(), ['exception' => $e]);
			return new JSONResponse(['error' => 'Could not update webhook'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new JSONResponse($this->webhookSecretService->status(webhook: $updated));
	}//end patch()

	/**
	 * Read the agent's webhook status. NEVER includes secretHash or the plaintext secret.
	 *
	 * @param string $id The agent UUID.
	 *
	 * @return JSONResponse The status payload ({configured:false} when unconfigured),
	 *                      or 404 for a non-owner/unknown agent.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/agent-webhook-trigger/specs/agent-webhook-trigger/spec.md#requirement-a-per-agent-webhook-secret-can-be-created-rotated-and-revoked
	 */
	public function show(string $id): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$agent = $this->loadOwnedAgent(agentId: $id, uid: $user->getUID());
		if ($agent === null) {
			return new JSONResponse(['error' => 'Agent not found'], Http::STATUS_NOT_FOUND);
		}

		$webhook = $this->webhookSecretService->findForAgent(agentId: $id);

		return new JSONResponse($this->webhookSecretService->status(webhook: $webhook));
	}//end show()

	/**
	 * Load the agent only if the given user owns it (IDOR guard).
	 *
	 * Fetches WITH RBAC enabled and additionally asserts owner identity, so
	 * neither a cross-tenant object nor another user's owned agent is ever
	 * returned — mirrors `RunNowController::loadOwnedSchedule()` exactly.
	 *
	 * @param string $agentId The agent UUID.
	 * @param string $uid The requesting user's UID.
	 *
	 * @return ObjectEntity|null The owned agent, or null when absent/not owned.
	 *
	 * @spec openspec/changes/agent-webhook-trigger/specs/agent-webhook-trigger/spec.md#requirement-a-per-agent-webhook-secret-can-be-created-rotated-and-revoked
	 */
	private function loadOwnedAgent(string $agentId, string $uid): ?ObjectEntity {
		try {
			$agent = $this->objectService->find(
				id: $agentId,
				register: self::REGISTER_SLUG,
				schema: self::AGENT_SCHEMA
			);
		} catch (Throwable $e) {
			// `ObjectService::find()` throws when the object is absent, and every
			// caller invokes this helper OUTSIDE its own try block — so the throw
			// would escape as a framework 500 with a stack trace on a
			// #[NoAdminRequired] route. An agent that cannot be loaded is, to a
			// caller, not owned; null already carries that meaning.
			$this->logger->warning(
				'Hermiq agent lookup failed for ' . $agentId . ': ' . $e->getMessage(),
				['exception' => $e]
			);
			return null;
		}//end try

		if (($agent instanceof ObjectEntity) === false) {
			return null;
		}

		if ((string)($agent->getOwner() ?? '') !== $uid) {
			return null;
		}

		return $agent;
	}//end loadOwnedAgent()
}//end class

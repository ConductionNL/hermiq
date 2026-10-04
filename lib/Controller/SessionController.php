<?php

/**
 * Hermiq SessionController.
 *
 * AI conversation CRUD endpoints, ported route-for-route from OpenRegister's
 * OpenRegister ConversationController (agent-engine-port) and re-pointed at `Session`/
 * `Message`/`Feedback` objects in the `hermiq` OpenRegister register via
 * ObjectService — no OR QBMappers.
 *
 * Adaptations vs the OR ground truth:
 * - Object ids are UUID strings; organisation scoping is inherited from
 *   ObjectService multitenancy (OR filtered by OrganisationService + SQL).
 * - Soft delete (archive) is a payload-level marker (`metadata.deletedAt` /
 *   `metadata.deletedBy`) because the hermiq `conversation` schema declares no
 *   deletedAt column and ObjectService exposes no restore for its own
 *   entity-envelope soft delete. Permanent deletion delegates to
 *   `ObjectService::deleteObject()` (OR's audited delete path) — irreversible
 *   through any Hermiq surface.
 * - The `_deleted` index filter partitions the caller's conversations in PHP
 *   on that marker (bounded fetch); OR filtered in SQL.
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

use Exception;
use OCA\Hermiq\AppInfo\Application;
use OCA\Hermiq\Service\AgentAccessService;
use OCA\Hermiq\Service\Engine\Engine;
use OCA\Hermiq\Service\Engine\SanitizesForSaveTrait;
use OCA\Hermiq\Service\Talk\ConversationParticipation;
use OCA\Hermiq\Service\Talk\TalkSessionRoom;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * CRUD operations for conversations with per-object ownership guards.
 *
 * Every `@NoAdminRequired` method that takes a conversation uuid verifies
 * `userId` ownership on the payload in the method body (gate-7 no-admin-idor);
 * organisation isolation is applied by ObjectService multitenancy on the read
 * itself.
 *
 * @spec openspec/changes/agent-engine-port/tasks.md#task-4-1
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)   Ported conversation surface
 * composes the engine facade (title generation), the OR object read/write path,
 * session and logging.
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Route-for-route port of OR's
 * OpenRegister ConversationController (8 endpoints incl. the archive/restore lifecycle);
 * splitting would break the structural-parity review against the OR original.
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)     Same reason, and the class sat
 * just under the 1000-line threshold before talk-agent-sessions pushed it over.
 * Everything that could honestly leave did leave: creating the session's room,
 * recording it, and deciding whether a rename may touch it all live in
 * TalkSessionRoom, so this class only calls them. What remains is the ported
 * endpoint surface itself, which is exactly what must not be split.
 */
class SessionController extends Controller {
	use SanitizesForSaveTrait;

	/**
	 * OpenRegister register slug that holds Hermiq agent-engine objects.
	 *
	 * @var string
	 */
	private const REGISTER_SLUG = 'hermiq';

	/**
	 * Schema slug for agent objects.
	 *
	 * @var string
	 */
	private const AGENT_SCHEMA = 'agent';

	/**
	 * Schema slug for conversation objects.
	 *
	 * @var string
	 */
	private const CONVERSATION_SCHEMA = 'agentsession';

	/**
	 * Schema slug for message objects.
	 *
	 * @var string
	 */
	private const MESSAGE_SCHEMA = 'agentsessionturn';

	/**
	 * Schema slug for feedback objects.
	 *
	 * @var string
	 */
	private const FEEDBACK_SCHEMA = 'feedback';

	/**
	 * Upper bound on the per-user conversation fetch used by index() to
	 * partition active vs archived threads in PHP (see class docblock
	 * adaptation note).
	 *
	 * @var int
	 */
	private const MAX_CONVERSATION_SCAN = 1000;

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request object.
	 * @param Engine $engine In-app agent engine facade (unique-title generation).
	 * @param ObjectService $objectService OpenRegister object read/write (single write-path).
	 * @param IUserSession $userSession Resolves the requesting user.
	 * @param TalkSessionRoom $sessionRoom Creates and renames the Talk room a session owns.
	 * @param LoggerInterface $logger PSR-3 logger.
	 * @param AgentAccessService $agentAccess Decides whether the caller may use an agent.
	 * @param ConversationParticipation $participation Owner-or-listed-participant read check.
	 *
	 * @spec openspec/changes/agent-engine-port/tasks.md#task-4-1
	 * @spec openspec/specs/session-participants/spec.md#requirement-an-invited-colleague-reads-and-takes-turns-req-spart-002
	 */
	public function __construct(
		IRequest $request,
		private readonly Engine $engine,
		private readonly ObjectService $objectService,
		private readonly IUserSession $userSession,
		private readonly TalkSessionRoom $sessionRoom,
		private readonly LoggerInterface $logger,
		private readonly AgentAccessService $agentAccess,
		private readonly ConversationParticipation $participation = new ConversationParticipation(),
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * List conversations for the current user.
	 *
	 * Supports filtering with query parameters:
	 * - _deleted: boolean (true = archived conversations, false/default = active)
	 * - limit / _limit: int (default: 50)
	 * - offset / _offset: int (default: 0)
	 *
	 * @return JSONResponse JSON response with the list of conversations.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/agent-engine-port/tasks.md#task-4-1
	 */
	public function index(): JSONResponse {
		try {
			$userId = $this->requireUserId();

			// Get query parameters.
			$params = $this->request->getParams();
			$limit = (int)($params['limit'] ?? $params['_limit'] ?? 50);
			$offset = (int)($params['offset'] ?? $params['_offset'] ?? 0);
			$showDeleted = filter_var(($params['_deleted'] ?? false), FILTER_VALIDATE_BOOLEAN);

			// Fetch the caller's conversations (org-scoped by ObjectService
			// multitenancy, user-scoped by the payload filter) and partition
			// on the archive marker.
			$all = $this->listReadableSessions(userId: $userId);

			$matching = [];
			foreach ($all as $conversation) {

				if ($this->isArchived(conversation: $conversation) === $showDeleted) {
					$matching[] = $conversation;
				}
			}

			$total = count($matching);
			$page = array_slice($matching, $offset, $limit);

			return new JSONResponse(
				data: [
					'results' => array_map(
						fn (ObjectEntity $conv): array => $this->serializeConversation(conversation: $conv, userId: $userId),
						$page
					),
					'total' => $total,
					'limit' => $limit,
					'offset' => $offset,
				],
				statusCode: 200
			);
		} catch (Exception $e) {
			$this->logger->error(
				message: '[SessionController] Failed to list conversations',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'error' => $e->getMessage(),
					'trace' => $e->getTraceAsString(),
				]
			);

			return new JSONResponse(
				data: [
					'error' => 'Failed to fetch conversations',
					'message' => $e->getMessage(),
				],
				statusCode: 500
			);
		}//end try
	}//end index()

	/**
	 * Get a single conversation (without messages).
	 *
	 * @param string $uuid Conversation UUID.
	 *
	 * @return JSONResponse JSON response with conversation details.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/agent-engine-port/tasks.md#task-4-1
	 */
	public function show(string $uuid): JSONResponse {
		try {
			$userId = $this->requireUserId();
			$conversation = $this->objectService->find(
				id: $uuid,
				register: self::REGISTER_SLUG,
				schema: self::CONVERSATION_SCHEMA
			);
			if ($conversation === null) {
				return $this->notFoundResponse();
			}

			// Ownership guard (gate-7): only the owner or a listed participant may read the thread.
			if ($this->participation->mayTakeTurn(conversationData: $conversation->getObject(), userId: $userId) === false) {
				return $this->accessDeniedResponse(action: 'access');
			}

			// Build response without messages; message count fetched separately.
			$response = $this->serializeConversation(conversation: $conversation, userId: $userId);

			$response['messageCount'] = $this->countMessages(conversationId: $uuid);

			return new JSONResponse(data: $response, statusCode: 200);
		} catch (Exception $e) {
			$this->logger->error(
				message: '[SessionController] Failed to get conversation',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'uuid' => $uuid,
					'error' => $e->getMessage(),
					'trace' => $e->getTraceAsString(),
				]
			);

			return new JSONResponse(
				data: [
					'error' => 'Failed to fetch conversation',
					'message' => $e->getMessage(),
				],
				statusCode: 500
			);
		}//end try
	}//end show()

	/**
	 * Get messages for a conversation.
	 *
	 * @param string $uuid Conversation UUID.
	 *
	 * @return JSONResponse JSON response with conversation messages.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/agent-engine-port/tasks.md#task-4-1
	 */
	public function messages(string $uuid): JSONResponse {
		try {
			$userId = $this->requireUserId();
			$conversation = $this->objectService->find(
				id: $uuid,
				register: self::REGISTER_SLUG,
				schema: self::CONVERSATION_SCHEMA
			);
			if ($conversation === null) {
				return $this->notFoundResponse();
			}

			// Ownership guard (gate-7): only the owner or a listed participant may read the thread.
			if ($this->participation->mayTakeTurn(conversationData: $conversation->getObject(), userId: $userId) === false) {
				return $this->accessDeniedResponse(action: 'access');
			}

			// Get query parameters for pagination.
			$params = $this->request->getParams();
			$limit = (int)($params['limit'] ?? $params['_limit'] ?? 50);
			$offset = (int)($params['offset'] ?? $params['_offset'] ?? 0);

			// Get messages with pagination (oldest-first, mirroring OR).
			// _rbac false: a SessionTurn is readable by its owner only (hermiq#976), and
			// in a shared session the other participants' turns are owned by them. The
			// ownership guard above already decided this caller may read the thread.
			$messages = $this->objectService
				->setRegister(self::REGISTER_SLUG)
				->setSchema(self::MESSAGE_SCHEMA)
				->findAll(
					config: [
						'filters' => ['sessionId' => $uuid],
						'sort' => ['created' => 'ASC'],
						'limit' => $limit,
						'offset' => $offset,
					],
					_rbac: false
				);
			$messages = array_values(array_filter($messages, static fn ($msg): bool => $msg instanceof ObjectEntity));

			return new JSONResponse(
				data: [
					'results' => array_map(
						fn (ObjectEntity $msg): array => $this->serializeMessage(message: $msg),
						$messages
					),
					'total' => $this->countMessages(conversationId: $uuid),
					'limit' => $limit,
					'offset' => $offset,
				],
				statusCode: 200
			);
		} catch (Exception $e) {
			$this->logger->error(
				message: '[SessionController] Failed to get messages',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'uuid' => $uuid,
					'error' => $e->getMessage(),
					'trace' => $e->getTraceAsString(),
				]
			);

			return new JSONResponse(
				data: [
					'error' => 'Failed to fetch messages',
					'message' => $e->getMessage(),
				],
				statusCode: 500
			);
		}//end try
	}//end messages()

	/**
	 * Create a new conversation.
	 *
	 * Mirrors OR ConversationController::create(); accepts `agentId` or
	 * `agentUuid` (both are the agent object UUID in hermiq). The organisation
	 * is assigned by ObjectService multitenancy on save.
	 *
	 * 🔑 WHO MAY CREATE IS DECIDED HERE, NOT IN THE REGISTER (hermiq#1086). The
	 * Session schema grants an owner-scoped `read` and lists no `create`, on
	 * purpose (hermiq#319, PrivateSchemaReadRulesTest::testWriteActionsStayOmitted):
	 * a `create` grant would let any signed-in user POST a session with somebody
	 * else's `userId` through the object API. OpenRegister therefore refuses the
	 * default `_rbac: true` save to every non-admin, which made chat admin-only.
	 * This method is the guard instead: the caller is resolved, the agent must be
	 * one the caller may use, and `userId` is always the caller. The save then runs
	 * with `_rbac: false`, as GoalService::set() does for the owner-scoped Goal
	 * schema. OpenRegister still stamps `_owner` from the user session, so the
	 * owner-only read and the owner admit on later updates keep working.
	 *
	 * @return JSONResponse Created conversation.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/agent-engine-port/tasks.md#task-4-1
	 */
	public function create(): JSONResponse {
		try {
			$userId = $this->requireUserId();

			// Get request data.
			$data = $this->request->getParams();

			$requested = (string)($data['agentId'] ?? $data['agentUuid'] ?? '');
			if ($requested === '') {
				return new JSONResponse(
					data: [
						'error' => 'Agent required',
						'message' => 'Choose an agent to start a session with.',
					],
					statusCode: 400
				);
			}

			// A private agent the caller may not use answers exactly like a missing
			// one, so this path cannot confirm that it exists (ADR-005 Rule 3).
			$agent = $this->agentAccess->loadAccessibleAgent(agentId: $requested, userId: $userId);
			if ($agent === null) {
				return new JSONResponse(
					data: [
						'error' => 'Agent not found',
						'message' => 'This agent does not exist or is not shared with you.',
					],
					statusCode: 404
				);
			}

			$agentId = (string)$agent->getUuid();

			// Generate unique title if not provided.
			$title = ($data['title'] ?? null);
			if ($title === null) {
				$title = $this->engine->ensureUniqueTitle(
					baseTitle: 'New Conversation',
					userId: $userId,
					agentId: $agentId
				);
			}

			// Create the new conversation object (uuid/timestamps/organisation
			// are assigned by ObjectService). `_rbac: false`: see the docblock.
			$conversation = $this->objectService->saveObject(
				object: $this->sanitizeForSave(
					data: [
						'userId' => $userId,
						'agentId' => $agentId,
						'title' => $title,
						'metadata' => ($data['metadata'] ?? []),
					]
				),
				register: self::REGISTER_SLUG,
				schema: self::CONVERSATION_SCHEMA,
				_rbac: false
			);

			$conversation = $this->sessionRoom->attachToSession(
				conversation: $conversation,
				title: (string)$title,
				ownerUid: $userId,
				agentId: $agentId
			);

			$this->logger->info(
				message: '[SessionController] Conversation created',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'uuid' => $conversation->getUuid(),
					'userId' => $userId,
					'organisation' => $conversation->getOrganisation(),
				]
			);

			return new JSONResponse(data: $this->serializeConversation(conversation: $conversation), statusCode: 201);
		} catch (Exception $e) {
			$this->logger->error(
				message: '[SessionController] Failed to create conversation',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'error' => $e->getMessage(),
					'trace' => $e->getTraceAsString(),
				]
			);

			return new JSONResponse(
				data: [
					'error' => 'Failed to create conversation',
					'message' => $e->getMessage(),
				],
				statusCode: $this->failureStatus(error: $e)
			);
		}//end try
	}//end create()

	/**
	 * The HTTP status a failed write answers with.
	 *
	 * An exception that carries a 4xx code (401 from requireUserId(), a 403 refusal,
	 * a 422 validation failure) keeps it, so the client can tell "you may not" and
	 * "this input is wrong" from a server fault. Anything else is a 500.
	 *
	 * @param Exception $error The failure.
	 *
	 * @return int The status code.
	 *
	 * @spec openspec/changes/agent-engine-port/tasks.md#task-4-1
	 */
	private function failureStatus(Exception $error): int {
		$code = (int)$error->getCode();
		if ($code >= 400 && $code < 500) {
			return $code;
		}

		return 500;
	}//end failureStatus()

	/**
	 * Update a conversation (e.g., rename).
	 *
	 * SECURITY: only `title` and `metadata` are writable; immutable fields
	 * (userId, agentId, and the entity-level owner/organisation, which live
	 * outside the payload entirely) are never taken from the request.
	 *
	 * @param string $uuid Conversation UUID.
	 *
	 * @return JSONResponse JSON response with the updated conversation.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/agent-engine-port/tasks.md#task-4-1
	 */
	public function update(string $uuid): JSONResponse {
		try {
			$userId = $this->requireUserId();
			$conversation = $this->objectService->find(
				id: $uuid,
				register: self::REGISTER_SLUG,
				schema: self::CONVERSATION_SCHEMA
			);
			if ($conversation === null) {
				return $this->notFoundResponse();
			}

			// Modify guard (gate-7): only the owning user may modify.
			if (($conversation->getObject()['userId'] ?? null) !== $userId) {
				return $this->accessDeniedResponse(action: 'modify');
			}

			// Get request data.
			$data = $this->request->getParams();

			$payload = $conversation->getObject();
			if (($data['title'] ?? null) !== null) {
				$payload['title'] = $data['title'];
			}

			if (($data['metadata'] ?? null) !== null) {
				$payload['metadata'] = $data['metadata'];
			}

			$updated = $this->objectService->saveObject(
				object: $this->sanitizeForSave(data: $payload),
				register: self::REGISTER_SLUG,
				schema: self::CONVERSATION_SCHEMA,
				uuid: $uuid
			);

			if (($data['title'] ?? null) !== null) {
				$this->sessionRoom->renameIfOwned(session: $payload);
			}

			$this->logger->info(
				message: '[SessionController] Conversation updated',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'uuid' => $uuid,
				]
			);

			return new JSONResponse(data: $this->serializeConversation(conversation: $updated), statusCode: 200);
		} catch (Exception $e) {
			$this->logger->error(
				message: '[SessionController] Failed to update conversation',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'uuid' => $uuid,
					'error' => $e->getMessage(),
					'trace' => $e->getTraceAsString(),
				]
			);

			return new JSONResponse(
				data: [
					'error' => 'Failed to update conversation',
					'message' => $e->getMessage(),
				],
				statusCode: 500
			);
		}//end try
	}//end update()

	/**
	 * Soft delete a conversation (archive); a second delete on an already
	 * archived conversation deletes it permanently — mirroring OR's
	 * two-step destroy() semantics.
	 *
	 * @param string $uuid Conversation UUID.
	 *
	 * @return JSONResponse JSON response confirming the deletion.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
	 *
	 * @spec openspec/changes/agent-engine-port/tasks.md#task-4-1
	 */
	public function destroy(string $uuid): JSONResponse {
		try {
			$userId = $this->requireUserId();
			$conversation = $this->objectService->find(
				id: $uuid,
				register: self::REGISTER_SLUG,
				schema: self::CONVERSATION_SCHEMA
			);
			if ($conversation === null) {
				return $this->notFoundResponse();
			}

			// Modify guard (gate-7): only the owning user may delete.
			if (($conversation->getObject()['userId'] ?? null) !== $userId) {
				return $this->accessDeniedResponse(action: 'delete');
			}

			// Check if already archived.
			if ($this->isArchived(conversation: $conversation) === true) {
				// Already archived - perform permanent delete.
				$this->logger->info(
					message: '[SessionController] Permanently deleting archived conversation',
					context: [
						'file' => __FILE__,
						'line' => __LINE__,
						'uuid' => $uuid,
					]
				);

				// Delete feedback first, then messages, then the conversation
				// (OR's ordering).
				$this->deleteRelatedObjects(schema: self::FEEDBACK_SCHEMA, conversationId: $uuid);
				$this->deleteRelatedObjects(schema: self::MESSAGE_SCHEMA, conversationId: $uuid);
				$this->objectService->deleteObject(
					uuid: $uuid,
					register: self::REGISTER_SLUG,
					schema: self::CONVERSATION_SCHEMA
				);

				$this->logger->info(
					message: '[SessionController] Conversation permanently deleted',
					context: [
						'file' => __FILE__,
						'line' => __LINE__,
						'uuid' => $uuid,
					]
				);

				return new JSONResponse(
					data: [
						'message' => 'Conversation permanently deleted',
						'uuid' => $uuid,
					],
					statusCode: 200
				);
			}//end if

			// First delete - perform soft delete (archive marker on the payload).
			$this->setArchiveMarker(conversation: $conversation, deletedBy: $userId);

			$this->logger->info(
				message: '[SessionController] Conversation archived (soft deleted)',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'uuid' => $uuid,
				]
			);

			return new JSONResponse(
				data: [
					'message' => 'Conversation archived successfully',
					'uuid' => $uuid,
					'archived' => true,
				],
				statusCode: 200
			);
		} catch (Exception $e) {
			$this->logger->error(
				message: '[SessionController] Failed to delete conversation',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'uuid' => $uuid,
					'error' => $e->getMessage(),
					'trace' => $e->getTraceAsString(),
				]
			);

			return new JSONResponse(
				data: [
					'error' => 'Failed to delete conversation',
					'message' => $e->getMessage(),
				],
				statusCode: 500
			);
		}//end try
	}//end destroy()

	/**
	 * Restore a soft-deleted (archived) conversation.
	 *
	 * @param string $uuid Conversation UUID.
	 *
	 * @return JSONResponse JSON response with the restored conversation.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/agent-engine-port/tasks.md#task-4-1
	 */
	public function restore(string $uuid): JSONResponse {
		try {
			$userId = $this->requireUserId();
			$conversation = $this->objectService->find(
				id: $uuid,
				register: self::REGISTER_SLUG,
				schema: self::CONVERSATION_SCHEMA
			);
			if ($conversation === null) {
				return $this->notFoundResponse();
			}

			// Modify guard (gate-7): only the owning user may restore.
			if (($conversation->getObject()['userId'] ?? null) !== $userId) {
				return $this->accessDeniedResponse(action: 'restore');
			}

			// Clear the archive marker.
			$data = $conversation->getObject();
			$metadata = ($data['metadata'] ?? []);
			if (is_array($metadata) === false) {
				$metadata = [];
			}

			unset($metadata['deletedAt'], $metadata['deletedBy']);
			$data['metadata'] = $metadata;
			if ($metadata === []) {
				$data['metadata'] = null;
			}

			$restored = $this->objectService->saveObject(
				object: $this->sanitizeForSave(data: $data),
				register: self::REGISTER_SLUG,
				schema: self::CONVERSATION_SCHEMA,
				uuid: $uuid
			);

			$this->logger->info(
				message: '[SessionController] Conversation restored',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'uuid' => $uuid,
				]
			);

			return new JSONResponse(data: $this->serializeConversation(conversation: $restored), statusCode: 200);
		} catch (Exception $e) {
			$this->logger->error(
				message: '[SessionController] Failed to restore conversation',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'uuid' => $uuid,
					'error' => $e->getMessage(),
					'trace' => $e->getTraceAsString(),
				]
			);

			return new JSONResponse(
				data: [
					'error' => 'Failed to restore conversation',
					'message' => $e->getMessage(),
				],
				statusCode: 500
			);
		}//end try
	}//end restore()

	/**
	 * Hard delete a conversation permanently.
	 *
	 * Mirrors OR ConversationController::destroyPermanent(): deletes the
	 * conversation's messages first, then the conversation itself.
	 *
	 * @param string $uuid Conversation UUID.
	 *
	 * @return JSONResponse JSON response confirming permanent deletion.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/agent-engine-port/tasks.md#task-4-1
	 */
	public function destroyPermanent(string $uuid): JSONResponse {
		try {
			$userId = $this->requireUserId();
			$conversation = $this->objectService->find(
				id: $uuid,
				register: self::REGISTER_SLUG,
				schema: self::CONVERSATION_SCHEMA
			);
			if ($conversation === null) {
				return $this->notFoundResponse();
			}

			// Modify guard (gate-7): only the owning user may delete.
			if (($conversation->getObject()['userId'] ?? null) !== $userId) {
				return $this->accessDeniedResponse(action: 'delete');
			}

			// Delete messages first, then the conversation (OR's ordering).
			$this->deleteRelatedObjects(schema: self::MESSAGE_SCHEMA, conversationId: $uuid);
			$this->objectService->deleteObject(
				uuid: $uuid,
				register: self::REGISTER_SLUG,
				schema: self::CONVERSATION_SCHEMA
			);

			$this->logger->info(
				message: '[SessionController] Conversation permanently deleted',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'uuid' => $uuid,
				]
			);

			return new JSONResponse(
				data: [
					'message' => 'Conversation permanently deleted',
					'uuid' => $uuid,
				],
				statusCode: 200
			);
		} catch (Exception $e) {
			$this->logger->error(
				message: '[SessionController] Failed to permanently delete conversation',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'uuid' => $uuid,
					'error' => $e->getMessage(),
					'trace' => $e->getTraceAsString(),
				]
			);

			return new JSONResponse(
				data: [
					'error' => 'Failed to permanently delete conversation',
					'message' => $e->getMessage(),
				],
				statusCode: 500
			);
		}//end try
	}//end destroyPermanent()

	/**
	 * Whether a conversation carries the payload-level archive marker.
	 *
	 * @param ObjectEntity $conversation The conversation object.
	 *
	 * @return bool True when archived.
	 *
	 * @spec openspec/changes/agent-engine-port/tasks.md#task-4-1
	 */
	private function isArchived(ObjectEntity $conversation): bool {
		$metadata = ($conversation->getObject()['metadata'] ?? null);
		if (is_array($metadata) === false) {
			return false;
		}

		return empty($metadata['deletedAt']) === false;
	}//end isArchived()

	/**
	 * Write the payload-level archive marker onto a conversation.
	 *
	 * @param ObjectEntity $conversation The conversation to archive.
	 * @param string $deletedBy The archiving user id.
	 *
	 * @return void
	 *
	 * @throws Exception If the archive marker could not be persisted
	 *                   (`ObjectService::saveObject()` documents
	 *                   `@throws Exception If there is an error during save`).
	 *                   Propagation is deliberate: the sole caller, `destroy()`,
	 *                   invokes this INSIDE its own `try { … } catch (Exception
	 *                   $e)` and translates the failure into a logged error
	 *                   envelope. Catching it here would instead report
	 *                   "Conversation archived successfully" for a conversation
	 *                   that still carries no marker — and, because `destroy()`
	 *                   permanently deletes an already-archived conversation on
	 *                   the next call, a silently-missing marker also means the
	 *                   second delete never escalates.
	 *
	 * @spec openspec/changes/agent-engine-port/tasks.md#task-4-1
	 */
	private function setArchiveMarker(ObjectEntity $conversation, string $deletedBy): void {
		$data = $conversation->getObject();
		$metadata = ($data['metadata'] ?? []);
		if (is_array($metadata) === false) {
			$metadata = [];
		}

		$metadata['deletedAt'] = gmdate('c');
		$metadata['deletedBy'] = $deletedBy;
		$data['metadata'] = $metadata;

		$this->objectService->saveObject(
			object: $this->sanitizeForSave(data: $data),
			register: self::REGISTER_SLUG,
			schema: self::CONVERSATION_SCHEMA,
			uuid: (string)$conversation->getUuid()
		);
	}//end setArchiveMarker()

	/**
	 * Delete every object of a schema bound to a conversation (bounded scan).
	 *
	 * @param string $schema Schema slug (message or feedback).
	 * @param string $conversationId Conversation UUID.
	 *
	 * @return void
	 *
	 * @throws Exception If a related object could not be deleted
	 *                   (`ObjectService::deleteObject()` documents
	 *                   `@throws \Exception If user does not have delete
	 *                   permission`, plus `DoesNotExistException`).
	 *                   Propagation is deliberate: both callers, `destroy()` and
	 *                   `destroyPermanent()`, invoke this INSIDE their own
	 *                   `try { … } catch (Exception $e)` and translate the
	 *                   failure into a logged error envelope. Catching it here
	 *                   would report a permanent delete as complete while
	 *                   messages or feedback survive it — the conversation row
	 *                   is deleted on the very next line, so those rows would be
	 *                   orphaned with no remaining handle to reach them.
	 *
	 * @spec openspec/changes/agent-engine-port/tasks.md#task-4-1
	 */
	private function deleteRelatedObjects(string $schema, string $conversationId): void {
		// 🔴 THE TWO SCHEMAS NAME THE PARENT DIFFERENTLY, and this method serves both.
		// A turn points at its session through `sessionId`; Feedback still carries
		// `conversationId`, because feedback objects are not part of the session rename.
		// Filtering both on one key silently matches nothing for one of them, which here
		// would mean deleting a session and leaving its turns behind as orphans with no
		// remaining handle to reach them.
		$parentKey = 'sessionId';
		if ($schema === self::FEEDBACK_SCHEMA) {
			$parentKey = 'conversationId';
		}

		$related = $this->objectService
			->setRegister(self::REGISTER_SLUG)
			->setSchema($schema)
			->findAll(
				config: [
					'filters' => [$parentKey => $conversationId],
					'limit' => self::MAX_CONVERSATION_SCAN,
				]
			);

		foreach ($related as $object) {
			if (($object instanceof ObjectEntity) === false) {
				continue;
			}

			$this->objectService->deleteObject(
				uuid: (string)$object->getUuid(),
				register: self::REGISTER_SLUG,
				schema: $schema
			);
		}
	}//end deleteRelatedObjects()

	/**
	 * Count the messages in a conversation via the paginated search total.
	 *
	 * @param string $conversationId Conversation UUID.
	 *
	 * @return int Message count.
	 *
	 * @spec openspec/changes/agent-engine-port/tasks.md#task-4-1
	 */
	private function countMessages(string $conversationId): int {
		$paginated = $this->objectService
			->setRegister(self::REGISTER_SLUG)
			->setSchema(self::MESSAGE_SCHEMA)
			->searchObjectsPaginated(
				query: [
					'sessionId' => $conversationId,
					'_limit' => 1,
				],
				_rbac: false
			);

		return (int)($paginated['total'] ?? 0);
	}//end countMessages()

	/**
	 * Serialize a conversation object to the OR-compatible response shape.
	 *
	 * @param ObjectEntity $conversation The conversation object.
	 * @param string       $userId       The caller, to tell owner from participant.
	 *
	 * @return array<string, mixed> Serialized conversation.
	 *
	 * @spec openspec/changes/agent-engine-port/tasks.md#task-4-1
	 */
	private function serializeConversation(ObjectEntity $conversation, string $userId = ''): array {
		$data = $conversation->getObject();
		$deletedAt = null;
		$metadata = ($data['metadata'] ?? null);
		if (is_array($metadata) === true) {
			$deletedAt = ($metadata['deletedAt'] ?? null);
		}

		return [
			'id' => $conversation->getUuid(),
			'uuid' => $conversation->getUuid(),
			'title' => ($data['title'] ?? null),
			'userId' => ($data['userId'] ?? null),
			'organisation' => $conversation->getOrganisation(),
			'agentId' => ($data['agentId'] ?? null),
			// What started this session: `human`, `cron`, `event` or `flow`. The
			// session list splits on it, so a session that omits the property must
			// still land in a group rather than vanishing from both — every session
			// predating the property was started by a person, which is what the
			// `human` fallback records.
			'triggerOrigin' => ($data['triggerOrigin'] ?? 'human'),
			// Chat-work-together-in-one-session: who else is in it, and whether the
			// caller owns it or was invited, so /chat can group "Shared with me".
			'participants' => $this->participation->roster(conversationData: $data),
			'role' => $this->roleOf(data: $data, userId: $userId),
			'talkRoomToken' => ($data['talkRoomToken'] ?? null),
			'metadata' => $metadata,
			// Agents-instruction-variables: the answers to the agent's start fields,
			// shown in the session header.
			'startValues' => (array)($data['startValues'] ?? []),
			'deletedAt' => $deletedAt,
			'created' => $conversation->getCreated()?->format('c'),
			'updated' => $conversation->getUpdated()?->format('c'),
		];
	}//end serializeConversation()

	/**
	 * Every session the user may read: the ones they own, then the ones they are listed
	 * in, de-duplicated, newest first. The participants filter is a JSON-array contains
	 * match in OpenRegister; the permission is re-checked here, so a wider match can
	 * never list a session the user may not read.
	 *
	 * @param string $userId The caller.
	 *
	 * @return ObjectEntity[] The sessions.
	 *
	 * @spec openspec/specs/session-participants/spec.md#requirement-an-invited-colleague-reads-and-takes-turns-req-spart-002
	 */
	private function listReadableSessions(string $userId): array {
		$sessions = [];
		foreach (['userId', 'participants'] as $field) {
			$found = $this->objectService
				->setRegister(self::REGISTER_SLUG)
				->setSchema(self::CONVERSATION_SCHEMA)
				->findAll(
					config: [
						'filters' => [$field => $userId],
						'sort' => ['updated' => 'DESC'],
						'limit' => self::MAX_CONVERSATION_SCAN,
					]
				);
			foreach ($found as $session) {
				if (($session instanceof ObjectEntity) === true
					&& $this->participation->mayTakeTurn(conversationData: $session->getObject(), userId: $userId) === true
				) {
					$sessions[(string)$session->getUuid()] = $session;
				}
			}
		}

		$sessions = array_values($sessions);
		usort(
			$sessions,
			static fn (ObjectEntity $a, ObjectEntity $b): int => ($b->getUpdated()?->getTimestamp() ?? 0) <=> ($a->getUpdated()?->getTimestamp() ?? 0)
		);

		return $sessions;
	}//end listReadableSessions()

	/**
	 * Whether the caller owns a session or was invited into it.
	 *
	 * @param array<string, mixed> $data The session payload.
	 * @param string $userId The caller ('' when unknown).
	 *
	 * @return string `owner` or `participant`.
	 *
	 * @spec openspec/specs/session-participants/spec.md#requirement-an-invited-colleague-reads-and-takes-turns-req-spart-002
	 */
	private function roleOf(array $data, string $userId): string {
		if ($userId !== '' && ($data['userId'] ?? null) !== $userId) {
			return 'participant';
		}

		return 'owner';
	}//end roleOf()

	/**
	 * Serialize a message object to the OR-compatible response shape.
	 *
	 * @param ObjectEntity $message The message object.
	 *
	 * @return array<string, mixed> Serialized message.
	 *
	 * @spec openspec/changes/agent-engine-port/tasks.md#task-4-1
	 * @spec openspec/changes/chat-attachments-and-images/specs/chat-attachments/spec.md#requirement-a-person-can-attach-a-file-they-already-have-in-files-req-catt-002
	 */
	private function serializeMessage(ObjectEntity $message): array {
		$data = $message->getObject();

		return [
			'id' => $message->getUuid(),
			'uuid' => $message->getUuid(),
			// 🔴 THE STORED FIELD IS `sessionId`; THE RESPONSE KEY STAYS `conversationId`
			// FOR NOW. session-api-rename moves the backend and explicitly does NOT move
			// the frontend, which is the next spec — so reading the new field while still
			// emitting the old key is what keeps the Chat page working unchanged through
			// this deploy. `sessionId` is emitted alongside it so the frontend has
			// something to move ONTO, and `conversationId` is dropped there.
			// Falling back to the old field keeps a turn written before the migration
			// readable.
			'sessionId' => ($data['sessionId'] ?? $data['conversationId'] ?? null),
			'conversationId' => ($data['sessionId'] ?? $data['conversationId'] ?? null),
			'role' => ($data['role'] ?? null),
			'content' => ($data['content'] ?? null),
			'sources' => ($data['sources'] ?? []),
			// Who asked this turn (null on the agent's own turns), so a shared session shows it.
			'authorId' => ($data['authorId'] ?? null),
			'authorDisplayName' => ($data['authorDisplayName'] ?? null),
			// The files attached to this turn, as references (chat-attachments-and-images).
			'attachments' => ($data['attachments'] ?? []),
			'created' => $message->getCreated()?->format('c'),
		];
	}//end serializeMessage()

	/**
	 * The 404 "conversation not found" response.
	 *
	 * @return JSONResponse 404 response.
	 *
	 * @spec openspec/changes/agent-engine-port/tasks.md#task-4-1
	 */
	private function notFoundResponse(): JSONResponse {
		return new JSONResponse(
			data: [
				'error' => 'Conversation not found',
				'message' => 'The requested conversation does not exist',
			],
			statusCode: 404
		);
	}//end notFoundResponse()

	/**
	 * The 403 "access denied" response.
	 *
	 * @param string $action The refused action (for the message).
	 *
	 * @return JSONResponse 403 response.
	 *
	 * @spec openspec/changes/agent-engine-port/tasks.md#task-4-1
	 */
	private function accessDeniedResponse(string $action): JSONResponse {
		$message = 'You do not have access to this conversation';
		if ($action !== 'access') {
			$message = 'You do not have permission to ' . $action . ' this conversation';
		}

		return new JSONResponse(
			data: [
				'error' => 'Access denied',
				'message' => $message,
			],
			statusCode: 403
		);
	}//end accessDeniedResponse()

	/**
	 * Resolve the current user id or throw a 401-coded exception.
	 *
	 * @return string The current user id.
	 *
	 * @throws Exception If no user is authenticated (code 401).
	 *
	 * @spec openspec/changes/agent-engine-port/tasks.md#task-4-1
	 */
	private function requireUserId(): string {
		$this->noteDeprecatedAlias();

		$user = $this->userSession->getUser();
		if ($user === null) {
			throw new Exception('Authentication required', 401);
		}

		return $user->getUID();
	}//end requireUserId()

	/**
	 * Record that a caller reached this controller through a deprecated path.
	 *
	 * 🔑 HERE, IN `requireUserId()`, RATHER THAN IN EACH ENDPOINT. Every endpoint on this
	 * controller calls it, so the alias cannot be forgotten when an endpoint is added, and
	 * there is exactly one line to delete when the old family is finally retired.
	 *
	 * Retirement should be decided on this signal rather than on optimism: `/api/sessions`
	 * being available says nothing about whether anything still calls
	 * `/api/conversations`, and removing it while an integration depends on it 404s that
	 * integration with no warning.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/session-api-rename/tasks.md#3-add-the-new-routes-and-keep-the-old-ones-as-aliases
	 */
	private function noteDeprecatedAlias(): void {
		$path = (string) $this->request->getPathInfo();
		if (str_contains($path, '/api/conversations') === false) {
			return;
		}

		$this->logger->info(
			message: '[SessionController] deprecated /api/conversations path used',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'path' => $path,
				'replacement' => str_replace('/api/conversations', '/api/sessions', $path),
			]
		);
	}//end noteDeprecatedAlias()
}//end class

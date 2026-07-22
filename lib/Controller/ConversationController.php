<?php

/**
 * Hermiq ConversationController.
 *
 * AI conversation CRUD endpoints, ported route-for-route from OpenRegister's
 * ConversationController (agent-engine-port) and re-pointed at `Conversation`/
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
use OCA\Hermiq\Service\Engine\Engine;
use OCA\Hermiq\Service\Engine\SanitizesForSaveTrait;
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
 * ConversationController (8 endpoints incl. the archive/restore lifecycle);
 * splitting would break the structural-parity review against the OR original.
 */
class ConversationController extends Controller
{
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
    private const CONVERSATION_SCHEMA = 'conversation';

    /**
     * Schema slug for message objects.
     *
     * @var string
     */
    private const MESSAGE_SCHEMA = 'message';

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
     * @param IRequest        $request       The request object.
     * @param Engine          $engine        In-app agent engine facade (unique-title generation).
     * @param ObjectService   $objectService OpenRegister object read/write (single write-path).
     * @param IUserSession    $userSession   Resolves the requesting user.
     * @param LoggerInterface $logger        PSR-3 logger.
     *
     * @spec openspec/changes/agent-engine-port/tasks.md#task-4-1
     */
    public function __construct(
        IRequest $request,
        private readonly Engine $engine,
        private readonly ObjectService $objectService,
        private readonly IUserSession $userSession,
        private readonly LoggerInterface $logger,
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
    public function index(): JSONResponse
    {
        try {
            $userId = $this->requireUserId();

            // Get query parameters.
            $params      = $this->request->getParams();
            $limit       = (int) ($params['limit'] ?? $params['_limit'] ?? 50);
            $offset      = (int) ($params['offset'] ?? $params['_offset'] ?? 0);
            $showDeleted = filter_var(($params['_deleted'] ?? false), FILTER_VALIDATE_BOOLEAN);

            // Fetch the caller's conversations (org-scoped by ObjectService
            // multitenancy, user-scoped by the payload filter) and partition
            // on the archive marker.
            $all = $this->objectService
                ->setRegister(self::REGISTER_SLUG)
                ->setSchema(self::CONVERSATION_SCHEMA)
                ->findAll(
                    config: [
                        'filters' => ['userId' => $userId],
                        'sort'    => ['updated' => 'DESC'],
                        'limit'   => self::MAX_CONVERSATION_SCAN,
                    ]
                );

            $matching = [];
            foreach ($all as $conversation) {
                if (($conversation instanceof ObjectEntity) === false) {
                    continue;
                }

                if ($this->isArchived(conversation: $conversation) === $showDeleted) {
                    $matching[] = $conversation;
                }
            }

            $total = count($matching);
            $page  = array_slice($matching, $offset, $limit);

            return new JSONResponse(
                data: [
                    'results' => array_map(
                        fn (ObjectEntity $conv): array => $this->serializeConversation(conversation: $conv),
                        $page
                    ),
                    'total'   => $total,
                    'limit'   => $limit,
                    'offset'  => $offset,
                ],
                statusCode: 200
            );
        } catch (Exception $e) {
            $this->logger->error(
                message: '[ConversationController] Failed to list conversations',
                context: [
                    'file'  => __FILE__,
                    'line'  => __LINE__,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]
            );

            return new JSONResponse(
                data: [
                    'error'   => 'Failed to fetch conversations',
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
    public function show(string $uuid): JSONResponse
    {
        try {
            $userId       = $this->requireUserId();
            $conversation = $this->objectService->find(
                id: $uuid,
                register: self::REGISTER_SLUG,
                schema: self::CONVERSATION_SCHEMA
            );
            if ($conversation === null) {
                return $this->notFoundResponse();
            }

            // Ownership guard (gate-7): only the owning user may read the thread.
            if (($conversation->getObject()['userId'] ?? null) !== $userId) {
                return $this->accessDeniedResponse(action: 'access');
            }

            // Build response without messages; message count fetched separately.
            $response = $this->serializeConversation(conversation: $conversation);

            $response['messageCount'] = $this->countMessages(conversationId: $uuid);

            return new JSONResponse(data: $response, statusCode: 200);
        } catch (Exception $e) {
            $this->logger->error(
                message: '[ConversationController] Failed to get conversation',
                context: [
                    'file'  => __FILE__,
                    'line'  => __LINE__,
                    'uuid'  => $uuid,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]
            );

            return new JSONResponse(
                data: [
                    'error'   => 'Failed to fetch conversation',
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
    public function messages(string $uuid): JSONResponse
    {
        try {
            $userId       = $this->requireUserId();
            $conversation = $this->objectService->find(
                id: $uuid,
                register: self::REGISTER_SLUG,
                schema: self::CONVERSATION_SCHEMA
            );
            if ($conversation === null) {
                return $this->notFoundResponse();
            }

            // Ownership guard (gate-7): only the owning user may read the thread.
            if (($conversation->getObject()['userId'] ?? null) !== $userId) {
                return $this->accessDeniedResponse(action: 'access');
            }

            // Get query parameters for pagination.
            $params = $this->request->getParams();
            $limit  = (int) ($params['limit'] ?? $params['_limit'] ?? 50);
            $offset = (int) ($params['offset'] ?? $params['_offset'] ?? 0);

            // Get messages with pagination (oldest-first, mirroring OR).
            $messages = $this->objectService
                ->setRegister(self::REGISTER_SLUG)
                ->setSchema(self::MESSAGE_SCHEMA)
                ->findAll(
                    config: [
                        'filters' => ['conversationId' => $uuid],
                        'sort'    => ['created' => 'ASC'],
                        'limit'   => $limit,
                        'offset'  => $offset,
                    ]
                );
            $messages = array_values(array_filter($messages, static fn ($msg): bool => $msg instanceof ObjectEntity));

            return new JSONResponse(
                data: [
                    'results' => array_map(
                        fn (ObjectEntity $msg): array => $this->serializeMessage(message: $msg),
                        $messages
                    ),
                    'total'   => $this->countMessages(conversationId: $uuid),
                    'limit'   => $limit,
                    'offset'  => $offset,
                ],
                statusCode: 200
            );
        } catch (Exception $e) {
            $this->logger->error(
                message: '[ConversationController] Failed to get messages',
                context: [
                    'file'  => __FILE__,
                    'line'  => __LINE__,
                    'uuid'  => $uuid,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]
            );

            return new JSONResponse(
                data: [
                    'error'   => 'Failed to fetch messages',
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
     * is assigned by ObjectService multitenancy on save. NOTE: the hermiq
     * `conversation` schema declares `agentId` required, so creating a
     * conversation without a resolvable agent fails schema validation (OR
     * allowed a null agentId) — surfaced as the standard 500 error envelope.
     *
     * @return JSONResponse Created conversation.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/agent-engine-port/tasks.md#task-4-1
     */
    public function create(): JSONResponse
    {
        try {
            $userId = $this->requireUserId();

            // Get request data.
            $data = $this->request->getParams();

            // Get agent UUID (handle both agentId and agentUuid).
            $agentId = null;
            if (($data['agentId'] ?? null) !== null) {
                $agentId = (string) $data['agentId'];
            } else if (($data['agentUuid'] ?? null) !== null) {
                // Look up the agent to confirm it exists; log and continue
                // with null when absent (mirrors OR's warn-and-continue).
                $agent = $this->objectService->find(
                    id: (string) $data['agentUuid'],
                    register: self::REGISTER_SLUG,
                    schema: self::AGENT_SCHEMA
                );
                if ($agent !== null) {
                    $agentId = (string) $agent->getUuid();
                }

                if ($agent === null) {
                    $this->logger->warning(
                        message: '[ConversationController] Agent UUID not found',
                        context: [
                            'file'      => __FILE__,
                            'line'      => __LINE__,
                            'agentUuid' => $data['agentUuid'],
                        ]
                    );
                }
            }//end if

            // Generate unique title if not provided.
            $title = ($data['title'] ?? null);
            if ($title === null && $agentId !== null) {
                $title = $this->engine->ensureUniqueTitle(
                    baseTitle: 'New Conversation',
                    userId: $userId,
                    agentId: $agentId
                );
            }

            // Create the new conversation object (uuid/timestamps/organisation
            // are assigned by ObjectService).
            $conversation = $this->objectService->saveObject(
                object: $this->sanitizeForSave(
                    data: [
                        'userId'   => $userId,
                        'agentId'  => $agentId,
                        'title'    => $title,
                        'metadata' => ($data['metadata'] ?? []),
                    ]
                ),
                register: self::REGISTER_SLUG,
                schema: self::CONVERSATION_SCHEMA
            );

            $this->logger->info(
                message: '[ConversationController] Conversation created',
                context: [
                    'file'         => __FILE__,
                    'line'         => __LINE__,
                    'uuid'         => $conversation->getUuid(),
                    'userId'       => $userId,
                    'organisation' => $conversation->getOrganisation(),
                ]
            );

            return new JSONResponse(data: $this->serializeConversation(conversation: $conversation), statusCode: 201);
        } catch (Exception $e) {
            $this->logger->error(
                message: '[ConversationController] Failed to create conversation',
                context: [
                    'file'  => __FILE__,
                    'line'  => __LINE__,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]
            );

            return new JSONResponse(
                data: [
                    'error'   => 'Failed to create conversation',
                    'message' => $e->getMessage(),
                ],
                statusCode: 500
            );
        }//end try
    }//end create()

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
    public function update(string $uuid): JSONResponse
    {
        try {
            $userId       = $this->requireUserId();
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

            $this->logger->info(
                message: '[ConversationController] Conversation updated',
                context: [
                    'file' => __FILE__,
                    'line' => __LINE__,
                    'uuid' => $uuid,
                ]
            );

            return new JSONResponse(data: $this->serializeConversation(conversation: $updated), statusCode: 200);
        } catch (Exception $e) {
            $this->logger->error(
                message: '[ConversationController] Failed to update conversation',
                context: [
                    'file'  => __FILE__,
                    'line'  => __LINE__,
                    'uuid'  => $uuid,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]
            );

            return new JSONResponse(
                data: [
                    'error'   => 'Failed to update conversation',
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
    public function destroy(string $uuid): JSONResponse
    {
        try {
            $userId       = $this->requireUserId();
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
                    message: '[ConversationController] Permanently deleting archived conversation',
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
                    message: '[ConversationController] Conversation permanently deleted',
                    context: [
                        'file' => __FILE__,
                        'line' => __LINE__,
                        'uuid' => $uuid,
                    ]
                );

                return new JSONResponse(
                    data: [
                        'message' => 'Conversation permanently deleted',
                        'uuid'    => $uuid,
                    ],
                    statusCode: 200
                );
            }//end if

            // First delete - perform soft delete (archive marker on the payload).
            $this->setArchiveMarker(conversation: $conversation, deletedBy: $userId);

            $this->logger->info(
                message: '[ConversationController] Conversation archived (soft deleted)',
                context: [
                    'file' => __FILE__,
                    'line' => __LINE__,
                    'uuid' => $uuid,
                ]
            );

            return new JSONResponse(
                data: [
                    'message'  => 'Conversation archived successfully',
                    'uuid'     => $uuid,
                    'archived' => true,
                ],
                statusCode: 200
            );
        } catch (Exception $e) {
            $this->logger->error(
                message: '[ConversationController] Failed to delete conversation',
                context: [
                    'file'  => __FILE__,
                    'line'  => __LINE__,
                    'uuid'  => $uuid,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]
            );

            return new JSONResponse(
                data: [
                    'error'   => 'Failed to delete conversation',
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
    public function restore(string $uuid): JSONResponse
    {
        try {
            $userId       = $this->requireUserId();
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
            $data     = $conversation->getObject();
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
                message: '[ConversationController] Conversation restored',
                context: [
                    'file' => __FILE__,
                    'line' => __LINE__,
                    'uuid' => $uuid,
                ]
            );

            return new JSONResponse(data: $this->serializeConversation(conversation: $restored), statusCode: 200);
        } catch (Exception $e) {
            $this->logger->error(
                message: '[ConversationController] Failed to restore conversation',
                context: [
                    'file'  => __FILE__,
                    'line'  => __LINE__,
                    'uuid'  => $uuid,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]
            );

            return new JSONResponse(
                data: [
                    'error'   => 'Failed to restore conversation',
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
    public function destroyPermanent(string $uuid): JSONResponse
    {
        try {
            $userId       = $this->requireUserId();
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
                message: '[ConversationController] Conversation permanently deleted',
                context: [
                    'file' => __FILE__,
                    'line' => __LINE__,
                    'uuid' => $uuid,
                ]
            );

            return new JSONResponse(
                data: [
                    'message' => 'Conversation permanently deleted',
                    'uuid'    => $uuid,
                ],
                statusCode: 200
            );
        } catch (Exception $e) {
            $this->logger->error(
                message: '[ConversationController] Failed to permanently delete conversation',
                context: [
                    'file'  => __FILE__,
                    'line'  => __LINE__,
                    'uuid'  => $uuid,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]
            );

            return new JSONResponse(
                data: [
                    'error'   => 'Failed to permanently delete conversation',
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
    private function isArchived(ObjectEntity $conversation): bool
    {
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
     * @param string       $deletedBy    The archiving user id.
     *
     * @return void
     *
     * @spec openspec/changes/agent-engine-port/tasks.md#task-4-1
     */
    private function setArchiveMarker(ObjectEntity $conversation, string $deletedBy): void
    {
        $data     = $conversation->getObject();
        $metadata = ($data['metadata'] ?? []);
        if (is_array($metadata) === false) {
            $metadata = [];
        }

        $metadata['deletedAt'] = gmdate('c');
        $metadata['deletedBy'] = $deletedBy;
        $data['metadata']      = $metadata;

        $this->objectService->saveObject(
            object: $this->sanitizeForSave(data: $data),
            register: self::REGISTER_SLUG,
            schema: self::CONVERSATION_SCHEMA,
            uuid: (string) $conversation->getUuid()
        );
    }//end setArchiveMarker()

    /**
     * Delete every object of a schema bound to a conversation (bounded scan).
     *
     * @param string $schema         Schema slug (message or feedback).
     * @param string $conversationId Conversation UUID.
     *
     * @return void
     *
     * @spec openspec/changes/agent-engine-port/tasks.md#task-4-1
     */
    private function deleteRelatedObjects(string $schema, string $conversationId): void
    {
        $related = $this->objectService
            ->setRegister(self::REGISTER_SLUG)
            ->setSchema($schema)
            ->findAll(
                config: [
                    'filters' => ['conversationId' => $conversationId],
                    'limit'   => self::MAX_CONVERSATION_SCAN,
                ]
            );

        foreach ($related as $object) {
            if (($object instanceof ObjectEntity) === false) {
                continue;
            }

            $this->objectService->deleteObject(
                uuid: (string) $object->getUuid(),
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
    private function countMessages(string $conversationId): int
    {
        $paginated = $this->objectService
            ->setRegister(self::REGISTER_SLUG)
            ->setSchema(self::MESSAGE_SCHEMA)
            ->searchObjectsPaginated(
                query: [
                    'conversationId' => $conversationId,
                    '_limit'         => 1,
                ]
            );

        return (int) ($paginated['total'] ?? 0);
    }//end countMessages()

    /**
     * Serialize a conversation object to the OR-compatible response shape.
     *
     * @param ObjectEntity $conversation The conversation object.
     *
     * @return array<string, mixed> Serialized conversation.
     *
     * @spec openspec/changes/agent-engine-port/tasks.md#task-4-1
     */
    private function serializeConversation(ObjectEntity $conversation): array
    {
        $data      = $conversation->getObject();
        $deletedAt = null;
        $metadata  = ($data['metadata'] ?? null);
        if (is_array($metadata) === true) {
            $deletedAt = ($metadata['deletedAt'] ?? null);
        }

        return [
            'id'           => $conversation->getUuid(),
            'uuid'         => $conversation->getUuid(),
            'title'        => ($data['title'] ?? null),
            'userId'       => ($data['userId'] ?? null),
            'organisation' => $conversation->getOrganisation(),
            'agentId'      => ($data['agentId'] ?? null),
            'metadata'     => $metadata,
            'deletedAt'    => $deletedAt,
            'created'      => $conversation->getCreated()?->format('c'),
            'updated'      => $conversation->getUpdated()?->format('c'),
        ];
    }//end serializeConversation()

    /**
     * Serialize a message object to the OR-compatible response shape.
     *
     * @param ObjectEntity $message The message object.
     *
     * @return array<string, mixed> Serialized message.
     *
     * @spec openspec/changes/agent-engine-port/tasks.md#task-4-1
     */
    private function serializeMessage(ObjectEntity $message): array
    {
        $data = $message->getObject();

        return [
            'id'             => $message->getUuid(),
            'uuid'           => $message->getUuid(),
            'conversationId' => ($data['conversationId'] ?? null),
            'role'           => ($data['role'] ?? null),
            'content'        => ($data['content'] ?? null),
            'sources'        => ($data['sources'] ?? []),
            'created'        => $message->getCreated()?->format('c'),
        ];
    }//end serializeMessage()

    /**
     * The 404 "conversation not found" response.
     *
     * @return JSONResponse 404 response.
     *
     * @spec openspec/changes/agent-engine-port/tasks.md#task-4-1
     */
    private function notFoundResponse(): JSONResponse
    {
        return new JSONResponse(
            data: [
                'error'   => 'Conversation not found',
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
    private function accessDeniedResponse(string $action): JSONResponse
    {
        $message = 'You do not have access to this conversation';
        if ($action !== 'access') {
            $message = 'You do not have permission to '.$action.' this conversation';
        }

        return new JSONResponse(
            data: [
                'error'   => 'Access denied',
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
    private function requireUserId(): string
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            throw new Exception('Authentication required', 401);
        }

        return $user->getUID();
    }//end requireUserId()
}//end class

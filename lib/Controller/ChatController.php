<?php

/**
 * Hermiq ChatController.
 *
 * AI chat API endpoints (send message, history, feedback, stats), ported
 * route-for-route from OpenRegister's ChatController (agent-engine-port) and
 * re-pointed at the in-app Engine facade plus `Agent`/`Conversation`/`Message`/
 * `Feedback` objects in the `hermiq` OpenRegister register via ObjectService —
 * no OR QBMappers, no raw openregister_* table access.
 *
 * Adaptations vs the OR ground truth (documented per method):
 * - Object ids are UUID strings (ObjectEntity), not ints.
 * - Organisation scoping is inherited from ObjectService multitenancy (every
 *   read/write already runs org-scoped), replacing OR's explicit
 *   OrganisationService + SQL organisation filters.
 * - "Soft delete" of a conversation is an archive marker on the object payload
 *   (`metadata.deletedAt`), because the hermiq `conversation` schema has no
 *   deletedAt column and ObjectService exposes no restore for its own
 *   entity-envelope soft delete.
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
use OCA\Hermiq\Service\Engine\RunStepBus;
use OCA\Hermiq\Service\Engine\RunTraceCollector;
use OCA\Hermiq\Service\Llm\ProviderFactory;
use OCA\Hermiq\Service\ToolAccessRequestService;
use OCA\Hermiq\Service\Engine\SanitizesForSaveTrait;
use OCA\Hermiq\Service\GuardrailBlockedException;
use OCA\Hermiq\Service\Talk\ConversationParticipation;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * ChatController handles the AI chat API endpoints against the in-app engine.
 *
 * Every `@NoAdminRequired` method that touches a conversation/message guards
 * per-object ownership (`userId` on the conversation payload) in the method
 * body — gate-7 no-admin-idor.
 *
 * @spec openspec/changes/agent-engine-port/tasks.md#task-4-1
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)   Ported chat surface composes the
 * engine facade, the OR object read/write path, session, l10n and logging; each is
 * a separate concern of the ported contract.
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Route-for-route port of OR's
 * ChatController (5 endpoints, each with its ported guard/error paths); splitting
 * would break the structural-parity review against the OR original.
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)     The same ground, measured on
 * length instead of branches. The endpoints and their OpenRegister read/write
 * helpers are the port; moving the helpers into a service would put this class
 * and its mirror out of correspondence, which is the one property the parity
 * review depends on. ⚠️ It is 1126 lines against a 1000 threshold and the margin
 * only goes one way — when the port no longer needs to mirror the original,
 * extracting the seven object-access helpers is the split to make, not another
 * line raised here.
 * @SuppressWarnings(PHPMD.ExcessiveParameterList)   Constructor DI: each parameter is a
 * distinct injected collaborator, not a logic-bearing argument list. Same reason
 * carried by BudgetController, McpRunController and AgentTemplateController.
 */
class ChatController extends Controller {
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
	 * Constructor.
	 *
	 * @param IRequest $request The request object.
	 * @param Engine $engine In-app agent engine facade (ported ChatService).
	 * @param ObjectService $objectService OpenRegister object read/write (single write-path).
	 * @param IUserSession $userSession Resolves the requesting user.
	 * @param IL10N $l10n Localization service for translations.
	 * @param RunStepBus $runStepBus Publishes run steps to whichever surface is watching.
	 * @param ProviderFactory $providerFactory Pre-warms an agent's pooled CLI process.
	 * @param ToolAccessRequestService $accessRequests Raises and resolves an agent's
	 *                                                 requests for tools it lacks.
	 * @param LoggerInterface $logger PSR-3 logger.
	 * @param ConversationParticipation $participation Owner-or-listed-participant guard
	 *                                                 (talk-shared-sessions). Defaulted so every
	 *                                                 existing caller constructs unchanged.
	 *
	 * @spec openspec/changes/agent-engine-port/tasks.md#task-4-1
	 */
	public function __construct(
		IRequest $request,
		private readonly Engine $engine,
		private readonly ObjectService $objectService,
		private readonly IUserSession $userSession,
		private readonly IL10N $l10n,
		private readonly RunStepBus $runStepBus,
		private readonly ProviderFactory $providerFactory,
		private readonly ToolAccessRequestService $accessRequests,
		private readonly LoggerInterface $logger,
		private readonly ConversationParticipation $participation = new ConversationParticipation(),
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Pre-warm an agent's pooled CLI process for a conversation.
	 *
	 * Called when the chat OPENS or an agent is picked — not when the first
	 * question is asked, which is too late to help it. Measured on this
	 * instance, the first turn of a conversation costs 11.2s for a trivial
	 * prompt against 3.2s once the process exists; this moves that difference
	 * to while the user is still typing.
	 *
	 * Answers 200 whatever happens. A warm-up is an optimisation the following
	 * turn does not depend on, so a failure here must be invisible rather than
	 * something the chat has to handle.
	 *
	 * @return JSONResponse `{warmed: bool}`.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/warm-start-and-cli-step-visibility/specs/warm-start-and-cli-step-visibility/spec.md#requirement-a-failed-warm-up-is-invisible-to-the-chat
	 */
	public function warm(): JSONResponse {
		try {
			$userId = $this->requireUserId();
			$agentUuid = (string)$this->request->getParam('agentUuid');
			$conversationUuid = (string)$this->request->getParam('conversation');
			if ($agentUuid === '' || $conversationUuid === '') {
				return new JSONResponse(data: ['warmed' => false], statusCode: 200);
			}

			// ⚠️ No null check: `findConversation()` returns a non-nullable
			// ObjectEntity and THROWS a 404 when the conversation is absent. The
			// catch below already turns that into the same `warmed: false` this
			// branch was returning, so the check could never run — and reading
			// like a handled case is worse than not being there, because the next
			// person trusts it.
			$conversation = $this->findConversation(uuid: $conversationUuid);

			$this->verifyConversationAccess(conversation: $conversation, userId: $userId);

			$agent = $this->objectService->find(
				id: $agentUuid,
				register: self::REGISTER_SLUG,
				schema: 'agent'
			);
			if ($agent === null) {
				return new JSONResponse(data: ['warmed' => false], statusCode: 200);
			}

			$agentData = $agent->getObject();

			// Only the `cli` transport has a process to warm. Every other
			// provider is a plain HTTP call with nothing to pre-start.
			$llmConfig = $this->providerFactory->getLlmConfig();
			$model = $agentData['model'] ?? null;
			if (is_string($model) === false) {
				$model = null;
			}

			$driver = $this->providerFactory->createChatDriver(
				llmConfig: $llmConfig,
				agentModel: $model,
				organisation: null
			);
			if ($driver->provider !== 'anthropic' || $driver->executionMode !== 'cli') {
				return new JSONResponse(data: ['warmed' => false, 'reason' => 'not a cli agent'], statusCode: 200);
			}

			// Governed matches whether the agent actually holds tools, so the
			// warmed process carries the same argv the first turn will need.
			$tools = ($agentData['tools'] ?? []);
			$governed = (is_array($tools) === true && $tools !== []);

			$warmed = $this->providerFactory->warmAnthropicCli(
				credentialId: (string)$driver->credentialId,
				model: (string)$driver->model,
				agentId: $agentUuid,
				conversationId: $conversationUuid,
				governed: $governed
			);

			return new JSONResponse(data: ['warmed' => $warmed], statusCode: 200);
		} catch (Exception $e) {
			$this->logger->debug(
				message: '[ChatController] Warm-up skipped',
				context: ['reason' => $e->getMessage()]
			);

			return new JSONResponse(data: ['warmed' => false], statusCode: 200);
		}//end try

	}//end warm()

	/**
	 * Send a chat message in a conversation and get the AI response.
	 *
	 * Mirrors OR ChatController::sendMessage(): resolves the conversation
	 * (existing by UUID, or new against an agent UUID), verifies ownership,
	 * and delegates to Engine::processMessage(). The conversation UUID is
	 * echoed back on the result for the frontend.
	 *
	 * @return JSONResponse JSON response with AI response or error.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/agent-engine-port/tasks.md#task-4-1
	 */
	public function sendMessage(): JSONResponse {
		try {
			$userId = $this->requireUserId();
			$params = $this->extractMessageRequestParams();

			// Validate message is not empty.
			if (empty($params['message']) === true) {
				return new JSONResponse(
					data: [
						'error' => $this->l10n->t('Missing message'),
						'message' => $this->l10n->t('message content is required'),
					],
					statusCode: 400
				);
			}

			$this->logger->info(
				message: '[ChatController] Received message with settings',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'views' => count($params['selectedViews']),
					'tools' => count($params['selectedTools']),
					'ragSettings' => $params['ragSettings'],
				]
			);

			// Resolve conversation (load existing or create new).
			$conversation = $this->resolveConversation(
				conversationUuid: $params['conversationUuid'],
				agentUuid: $params['agentUuid'],
				userId: $userId
			);

			// Verify user has access to conversation (gate-7 ownership guard).
			$this->verifyConversationAccess(conversation: $conversation, userId: $userId);

			// Process message through the in-app Engine (conversation id is the UUID).
			// Collect the run's step timeline so the chat can SHOW its work.
			//
			// `Engine::process()` has always taken a collector and always
			// returned `steps`, but the chat path never supplied one — so the
			// field was `[]` on every chat response by construction, and a turn
			// that made five tool calls looked from the outside like one opaque
			// pause. The user sees "Thinking…" for a minute with no indication
			// that anything is happening, which reads as a hang rather than as
			// work.
			//
			// The collector is per-request and is already defensive about
			// unknown or double-ended tokens, so a step bug cannot fail a turn.
			$trace = new RunTraceCollector();

			// Start from an empty bucket so a previous turn's steps cannot be
			// shown against this one.
			$this->runStepBus->clear(conversationId: (string)$conversation->getUuid());

			$result = $this->engine->processMessage(
				conversationId: (string)$conversation->getUuid(),
				userId: $userId,
				userMessage: $params['message'],
				selectedViews: $params['selectedViews'],
				selectedTools: $params['selectedTools'],
				ragSettings: $params['ragSettings'],
				context: $params['context'],
				trace: $trace
			);

			// Add conversation UUID to result for frontend.
			$result['conversation'] = $conversation->getUuid();

			// The tool calls this turn made over the governed MCP transport.
			// `CnAiMessageList` already renders `message.toolCalls` with
			// expand/collapse — it was simply never given any, because those
			// calls happen in a different request than this one.
			$result['toolCalls'] = $this->runStepBus->drain(
				conversationId: (string)$conversation->getUuid()
			);

			// Anything the agent is now waiting on the owner to decide. Sent on
			// every turn so an approval raised mid-conversation is actionable
			// where the user already is, instead of on an oversight screen they
			// would have to know about and go and find.
			$result['pendingApprovals'] = $this->accessRequests->pendingApprovals(
				agentId: $params['agentUuid']
			);

			return new JSONResponse(data: $result, statusCode: 200);
		} catch (Exception $e) {
			return $this->sendMessageFailureResponse(exception: $e);
		}//end try
	}//end sendMessage()

	/**
	 * Shape a failed `sendMessage()` into the response the frontend expects.
	 *
	 * Split out of `sendMessage()` because it is a second job: the try block
	 * produces a turn, this produces an apology, and only one of them is what
	 * the method is named after.
	 *
	 * @param Exception $exception The failure.
	 *
	 * @return JSONResponse The error response.
	 *
	 * @spec openspec/changes/agent-engine-port/tasks.md#task-4-1
	 */
	private function sendMessageFailureResponse(Exception $exception): JSONResponse {
		// An exception code outside the HTTP range is not a status — a library
		// throwing code 0 or 23 would otherwise become a nonsense response.
		$statusCode = (int)$exception->getCode();
		if ($statusCode < 400 || $statusCode >= 600) {
			$statusCode = 500;
		}

		$this->logSendMessageFailure(exception: $exception, statusCode: $statusCode);

		$data = [
			'error' => match ($statusCode) {
				400 => $this->l10n->t('Missing conversation or agentUuid'),
				401 => $this->l10n->t('Authentication required'),
				403 => $this->l10n->t('Access denied'),
				404 => $this->l10n->t('Conversation not found'),
				422 => $this->l10n->t('Message blocked by the organisation\'s guardrail policy'),
				503 => $this->l10n->t('AI service not configured'),
				default => $this->l10n->t('Failed to process message'),
			},
			'message' => $exception->getMessage(),
		];

		// A stable errorCode lets the frontend key a translated message off the
		// case rather than matching on exception message text
		// (agent-guardrails, GuardrailBlockedException docblock).
		if ($exception instanceof GuardrailBlockedException) {
			$data['errorCode'] = 'guardrail_blocked';
		}

		return new JSONResponse(data: $data, statusCode: $statusCode);
	}//end sendMessageFailureResponse()

	/**
	 * Log a sendMessage() failure at the level matching its severity.
	 *
	 * Client input-validation failures (missing/invalid conversation or
	 * agentUuid, unknown conversation, ownership mismatch, …) are expected 4xx
	 * user error, not a server-side fault — logged at WARNING without a stack
	 * trace. Genuine 5xx / unexpected exceptions stay at ERROR with the full
	 * trace attached.
	 *
	 * @param Exception $exception The caught exception.
	 * @param int $statusCode The resolved HTTP status code (already clamped to 400-599).
	 *
	 * @return void
	 *
	 * @spec exclude Log-level-only helper split out of sendMessage() to stay under
	 * the phpmd method-length threshold; no behavioural contract beyond the log level.
	 */
	private function logSendMessageFailure(Exception $exception, int $statusCode): void {
		if ($statusCode < 500) {
			$this->logger->warning(
				message: '[ChatController] Rejected message: ' . $exception->getMessage(),
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
				]
			);
			return;
		}

		$this->logger->error(
			message: '[ChatController] Failed to send message',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'error' => $exception->getMessage(),
				'trace' => $exception->getTraceAsString(),
			]
		);
	}//end logSendMessageFailure()

	/**
	 * Get conversation history (messages).
	 *
	 * Mirrors OR ChatController::getHistory(); the `conversationId` request
	 * parameter is the conversation object UUID here (OR used the int row id).
	 *
	 * @return JSONResponse A JSON response with conversation history or error.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/agent-engine-port/tasks.md#task-4-1
	 */
	public function getHistory(): JSONResponse {
		try {
			$userId = $this->requireUserId();
			$conversationId = (string)$this->request->getParam('conversationId');

			if (empty($conversationId) === true) {
				return new JSONResponse(
					data: [
						'error' => $this->l10n->t('Missing conversationId'),
						'message' => $this->l10n->t('conversationId is required'),
					],
					statusCode: 400
				);
			}

			// Get conversation and verify ownership (gate-7 ownership guard).
			$conversation = $this->findConversation(uuid: $conversationId);
			if ($this->participation->mayTakeTurn(conversationData: $conversation->getObject(), userId: $userId) === false) {
				return new JSONResponse(
					data: [
						'error' => $this->l10n->t('Access denied'),
						'message' => $this->l10n->t('You do not have access to this conversation'),
					],
					statusCode: 403
				);
			}

			// Get messages.
			$limit = (int)($this->request->getParam('limit') ?? 100);
			$offset = (int)($this->request->getParam('offset') ?? 0);

			$messages = $this->findMessages(conversationId: $conversationId, limit: $limit, offset: $offset);

			$serializedMessages = array_map(
				fn (ObjectEntity $msg): array => $this->serializeMessage(message: $msg),
				$messages
			);

			return new JSONResponse(
				data: [
					'messages' => $serializedMessages,
					'total' => $this->countMessages(conversationId: $conversationId),
					'conversationId' => $conversationId,
				],
				statusCode: 200
			);
		} catch (Exception $e) {
			$this->logger->error(
				message: '[ChatController] Failed to get history',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'error' => $e->getMessage(),
					'trace' => $e->getTraceAsString(),
				]
			);

			return new JSONResponse(
				data: [
					'error' => $this->l10n->t('Failed to fetch conversation history'),
					'message' => $e->getMessage(),
				],
				statusCode: 500
			);
		}//end try
	}//end getHistory()

	/**
	 * Clear conversation history (soft delete).
	 *
	 * Mirrors OR ChatController::clearHistory(). Adaptation: OR soft-deletes
	 * via ConversationMapper::softDelete(); here the archive marker is written
	 * onto the conversation payload (`metadata.deletedAt`), the same marker
	 * ConversationController::destroy()/restore() use.
	 *
	 * @return JSONResponse A JSON response confirming conversation clearing or error.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/agent-engine-port/tasks.md#task-4-1
	 */
	public function clearHistory(): JSONResponse {
		try {
			$userId = $this->requireUserId();
			$conversationId = (string)$this->request->getParam('conversationId');

			if (empty($conversationId) === true) {
				return new JSONResponse(
					data: [
						'error' => $this->l10n->t('Missing conversationId'),
						'message' => $this->l10n->t('conversationId is required'),
					],
					statusCode: 400
				);
			}

			// Get conversation and verify ownership (gate-7 ownership guard).
			$conversation = $this->findConversation(uuid: $conversationId);
			if ($this->participation->mayTakeTurn(conversationData: $conversation->getObject(), userId: $userId) === false) {
				return new JSONResponse(
					data: [
						'error' => $this->l10n->t('Access denied'),
						'message' => $this->l10n->t('You do not have access to this conversation'),
					],
					statusCode: 403
				);
			}

			// Soft delete conversation (payload-level archive marker).
			$this->archiveConversation(conversation: $conversation, userId: $userId);

			$this->logger->info(
				message: '[ChatController] Conversation cleared (soft deleted)',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'conversationId' => $conversationId,
					'userId' => $userId,
				]
			);

			return new JSONResponse(
				data: [
					'message' => $this->l10n->t('Conversation cleared successfully'),
					'conversationId' => $conversationId,
				],
				statusCode: 200
			);
		} catch (Exception $e) {
			$this->logger->error(
				message: '[ChatController] Failed to clear history',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'error' => $e->getMessage(),
					'trace' => $e->getTraceAsString(),
				]
			);

			return new JSONResponse(
				data: [
					'error' => $this->l10n->t('Failed to clear conversation'),
					'message' => $e->getMessage(),
				],
				statusCode: 500
			);
		}//end try
	}//end clearHistory()

	/**
	 * Submit or update feedback on a message.
	 *
	 * Endpoint: POST /api/conversations/{conversationUuid}/messages/{messageId}/feedback
	 *
	 * Mirrors OR ChatController::sendFeedback(); `messageId` is the message
	 * object UUID here (OR used the int row id). The feedback's organisation
	 * is assigned by ObjectService multitenancy on save.
	 *
	 * @param string $conversationUuid Conversation UUID.
	 * @param string $messageId Message UUID.
	 *
	 * @return JSONResponse JSON response with feedback confirmation or error.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
	 *
	 * @spec openspec/changes/agent-engine-port/tasks.md#task-4-1
	 */
	public function sendFeedback(string $conversationUuid, string $messageId): JSONResponse {
		try {
			$userId = $this->requireUserId();

			// Get request parameters.
			$type = (string)$this->request->getParam('type');
			$comment = (string)$this->request->getParam('comment', '');

			// Validate feedback type.
			if (in_array($type, ['positive', 'negative'], true) === false) {
				return new JSONResponse(
					data: [
						'error' => $this->l10n->t('Invalid feedback type'),
						'message' => $this->l10n->t('type must be "positive" or "negative"'),
					],
					statusCode: 400
				);
			}

			// Get conversation by UUID and verify ownership (gate-7 ownership guard).
			$conversation = $this->findConversation(uuid: $conversationUuid);
			if ($this->participation->mayTakeTurn(conversationData: $conversation->getObject(), userId: $userId) === false) {
				return new JSONResponse(
					data: [
						'error' => $this->l10n->t('Access denied'),
						'message' => $this->l10n->t('You do not have access to this conversation'),
					],
					statusCode: 403
				);
			}

			// Get message and verify it belongs to this conversation.
			$message = $this->objectService->find(
				id: $messageId,
				register: self::REGISTER_SLUG,
				schema: self::MESSAGE_SCHEMA
			);
			if ($message === null
				|| ($message->getObject()['conversationId'] ?? null) !== $conversationUuid
			) {
				return new JSONResponse(
					data: [
						'error' => $this->l10n->t('Message not found'),
						'message' => $this->l10n->t('Message does not belong to this conversation'),
					],
					statusCode: 404
				);
			}

			// Check if feedback already exists for this message by this user.
			$existingFeedback = $this->findExistingFeedback(messageId: $messageId, userId: $userId);

			$payload = [
				'messageId' => $messageId,
				'conversationId' => $conversationUuid,
				'agentId' => (string)($conversation->getObject()['agentId'] ?? ''),
				'userId' => $userId,
				'type' => $type,
				'comment' => $comment,
			];

			$feedbackUuid = null;
			if ($existingFeedback !== null) {
				// Update existing feedback in place (keep its identity).
				$payload = array_merge($existingFeedback->getObject(), ['type' => $type, 'comment' => $comment]);
				$feedbackUuid = $existingFeedback->getUuid();
			}

			$feedback = $this->objectService->saveObject(
				object: $this->sanitizeForSave(data: $payload),
				register: self::REGISTER_SLUG,
				schema: self::FEEDBACK_SCHEMA,
				uuid: $feedbackUuid
			);

			$this->logger->info(
				message: '[ChatController] Message feedback saved',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'feedbackId' => $feedback->getUuid(),
					'messageId' => $messageId,
					'type' => $type,
					'updated' => $existingFeedback !== null,
					'hasComment' => empty($comment) === false,
				]
			);

			return new JSONResponse(data: $this->serializeFeedback(feedback: $feedback), statusCode: 200);
		} catch (Exception $e) {
			$this->logger->error(
				message: '[ChatController] Failed to save feedback',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'conversationUuid' => $conversationUuid,
					'messageId' => $messageId,
					'error' => $e->getMessage(),
					'trace' => $e->getTraceAsString(),
				]
			);

			return new JSONResponse(
				data: [
					'error' => $this->l10n->t('Failed to save feedback'),
					'message' => $e->getMessage(),
				],
				statusCode: 500
			);
		}//end try
	}//end sendFeedback()

	/**
	 * Get chat statistics.
	 *
	 * Mirrors OR ChatController::getChatStats(). Adaptation: OR issued raw
	 * COUNT queries against openregister_* tables filtered by the active
	 * organisation; here every count is an ObjectService paginated total
	 * (`searchObjectsPaginated()['total']`), which is inherently scoped to
	 * the caller's active organisation by ObjectService multitenancy — one
	 * tenant never sees another tenant's counts.
	 *
	 * @return JSONResponse Chat statistics.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/agent-engine-port/tasks.md#task-4-1
	 */
	public function getChatStats(): JSONResponse {
		try {
			// Explicit in-body authentication guard (gate-7 no-admin-idor):
			// #[NoAdminRequired] alone only requires an NC login, not a domain
			// check — the org-scoping below comes from ObjectService, but every
			// other endpoint in this class resolves the caller first too, so
			// this keeps the convention consistent and the guard visible in the
			// method body rather than implicit in the framework annotation.
			$this->requireUserId();

			return new JSONResponse(
				data: [
					'total_agents' => $this->countObjects(schema: self::AGENT_SCHEMA),
					'total_conversations' => $this->countObjects(schema: self::CONVERSATION_SCHEMA),
					'total_messages' => $this->countObjects(schema: self::MESSAGE_SCHEMA),
				],
				statusCode: 200
			);
		} catch (Exception $e) {
			$this->logger->error(
				message: '[ChatController] Failed to get chat stats',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'error' => $e->getMessage(),
				]
			);

			return new JSONResponse(
				data: [
					'error' => $this->l10n->t('Failed to get chat statistics'),
					'message' => $e->getMessage(),
				],
				statusCode: 500
			);
		}//end try
	}//end getChatStats()

	/**
	 * Extract and normalize request parameters for sending a message.
	 *
	 * Ported verbatim from OR ChatController::extractMessageRequestParams().
	 *
	 * @return array Normalized request parameters.
	 *
	 * @psalm-return array{conversationUuid: string, agentUuid: string,
	 *     message: string, selectedViews: array, selectedTools: array,
	 *     ragSettings: array{includeObjects: bool|mixed, includeFiles: bool|mixed,
	 *     numSourcesFiles: int|mixed, numSourcesObjects: int|mixed}, context: array}
	 *
	 * @spec openspec/changes/agent-engine-port/tasks.md#task-4-1
	 */
	private function extractMessageRequestParams(): array {
		// Extract basic parameters.
		$conversationUuid = (string)$this->request->getParam('conversation');
		$agentUuid = (string)$this->request->getParam('agentUuid');
		$message = (string)$this->request->getParam('message');

		// Extract selectedViews array.
		$viewsParam = $this->request->getParam('views');
		$selectedViews = [];
		if ($viewsParam !== null && is_array($viewsParam) === true) {
			$selectedViews = $viewsParam;
		}

		// Extract selectedTools array.
		$toolsParam = $this->request->getParam('tools');
		$selectedTools = [];
		if ($toolsParam !== null && is_array($toolsParam) === true) {
			$selectedTools = $toolsParam;
		}

		// Extract RAG configuration settings.
		$ragSettings = [
			'includeObjects' => ($this->request->getParam('includeObjects') ?? true),
			'includeFiles' => ($this->request->getParam('includeFiles') ?? true),
			'numSourcesFiles' => ($this->request->getParam('numSourcesFiles') ?? 5),
			'numSourcesObjects' => ($this->request->getParam('numSourcesObjects') ?? 5),
		];

		// Extract optional CnAiContext snapshot; anything that is not an
		// associative array is silently dropped (missing context is fine).
		$contextParam = $this->request->getParam('context');
		$context = [];
		if (is_array($contextParam) === true) {
			$context = $contextParam;
		}

		return [
			'conversationUuid' => $conversationUuid,
			'agentUuid' => $agentUuid,
			'message' => $message,
			'selectedViews' => $selectedViews,
			'selectedTools' => $selectedTools,
			'ragSettings' => $ragSettings,
			'context' => $context,
		];
	}//end extractMessageRequestParams()

	/**
	 * Resolve conversation from UUID or create a new one with the agent.
	 *
	 * @param string $conversationUuid Conversation UUID (empty if creating new).
	 * @param string $agentUuid Agent UUID (empty if using existing conversation).
	 * @param string $userId Current user id.
	 *
	 * @return ObjectEntity The resolved or created conversation.
	 *
	 * @throws Exception If both parameters are empty or if entities are not found.
	 *
	 * @spec openspec/changes/agent-engine-port/tasks.md#task-4-1
	 */
	private function resolveConversation(string $conversationUuid, string $agentUuid, string $userId): ObjectEntity {
		// Load existing conversation by UUID.
		if (empty($conversationUuid) === false) {
			return $this->findConversation(uuid: $conversationUuid);
		}

		// Create new conversation with specified agent.
		if (empty($agentUuid) === false) {
			return $this->createNewConversation(agentUuid: $agentUuid, userId: $userId);
		}

		// Neither parameter provided.
		throw new Exception('Either conversation or agentUuid is required', 400);
	}//end resolveConversation()

	/**
	 * Load an existing conversation by UUID or throw a 404-coded exception.
	 *
	 * @param string $uuid Conversation UUID.
	 *
	 * @return ObjectEntity The conversation object.
	 *
	 * @throws Exception If the conversation is not found (code 404).
	 *
	 * @spec openspec/changes/agent-engine-port/tasks.md#task-4-1
	 */
	private function findConversation(string $uuid): ObjectEntity {
		$conversation = $this->objectService->find(
			id: $uuid,
			register: self::REGISTER_SLUG,
			schema: self::CONVERSATION_SCHEMA
		);
		if ($conversation === null) {
			throw new Exception('The conversation with UUID ' . $uuid . ' does not exist', 404);
		}

		return $conversation;
	}//end findConversation()

	/**
	 * Create a new conversation bound to the given agent.
	 *
	 * Mirrors OR ChatController::createNewConversation(); the organisation is
	 * assigned by ObjectService multitenancy on save (OR set it explicitly).
	 *
	 * @param string $agentUuid Agent UUID.
	 * @param string $userId Current user id.
	 *
	 * @return ObjectEntity The newly created conversation.
	 *
	 * @throws Exception If the agent is not found (code 404).
	 *
	 * @spec openspec/changes/agent-engine-port/tasks.md#task-4-1
	 */
	private function createNewConversation(string $agentUuid, string $userId): ObjectEntity {
		// Look up agent by UUID.
		$agent = $this->objectService->find(
			id: $agentUuid,
			register: self::REGISTER_SLUG,
			schema: self::AGENT_SCHEMA
		);
		if ($agent === null) {
			throw new Exception('The agent with UUID ' . $agentUuid . ' does not exist', 404);
		}

		// Generate unique default title.
		$defaultTitle = $this->engine->ensureUniqueTitle(
			baseTitle: 'New Conversation',
			userId: $userId,
			agentId: (string)$agent->getUuid()
		);

		// Create and persist the new conversation object.
		$conversation = $this->objectService->saveObject(
			object: $this->sanitizeForSave(
				data: [
					'userId' => $userId,
					'agentId' => (string)$agent->getUuid(),
					'title' => $defaultTitle,
				]
			),
			register: self::REGISTER_SLUG,
			schema: self::CONVERSATION_SCHEMA
		);

		$this->logger->info(
			message: '[ChatController] New conversation created',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'uuid' => $conversation->getUuid(),
				'userId' => $userId,
				'agentId' => $agent->getUuid(),
				'title' => $defaultTitle,
			]
		);

		return $conversation;
	}//end createNewConversation()

	/**
	 * Verify that the current user may take a turn in the conversation.
	 *
	 * Owner-or-listed-participant since talk-shared-sessions: the `userId`
	 * owner is implicitly permitted, and an empty roster therefore means
	 * owner-only — the behaviour every conversation had before.
	 *
	 * @param ObjectEntity $conversation The conversation to check.
	 * @param string $userId Current user id.
	 *
	 * @return void
	 *
	 * @throws Exception If the user does not have access (code 403).
	 *
	 * @spec openspec/changes/agent-engine-port/tasks.md#task-4-1
	 * @spec openspec/changes/talk-chat-bridge/specs/talk-shared-sessions/spec.md#requirement-a-session-may-be-taken-up-by-its-owner-or-a-listed-participant
	 */
	private function verifyConversationAccess(ObjectEntity $conversation, string $userId): void {
		if ($this->participation->mayTakeTurn(conversationData: $conversation->getObject(), userId: $userId) === false) {
			throw new Exception('You do not have access to this conversation', 403);
		}
	}//end verifyConversationAccess()

	/**
	 * Write the payload-level archive marker onto a conversation.
	 *
	 * @param ObjectEntity $conversation The conversation to archive.
	 * @param string $userId The archiving user id.
	 *
	 * @return void
	 *
	 * @throws Exception If the archive marker could not be persisted
	 *                   (`ObjectService::saveObject()` documents
	 *                   `@throws Exception If there is an error during save`).
	 *                   Propagation is deliberate: the sole caller,
	 *                   `clearHistory()`, invokes this INSIDE its own
	 *                   `try { … } catch (Exception $e)` and translates the
	 *                   failure into a logged 500 envelope. Catching it here
	 *                   would instead report "Conversation cleared
	 *                   successfully" for a conversation that was never
	 *                   archived.
	 *
	 * @spec openspec/changes/agent-engine-port/tasks.md#task-4-1
	 */
	private function archiveConversation(ObjectEntity $conversation, string $userId): void {
		$data = $conversation->getObject();
		$metadata = ($data['metadata'] ?? []);
		if (is_array($metadata) === false) {
			$metadata = [];
		}

		$metadata['deletedAt'] = gmdate('c');
		$metadata['deletedBy'] = $userId;
		$data['metadata'] = $metadata;

		$this->objectService->saveObject(
			object: $this->sanitizeForSave(data: $data),
			register: self::REGISTER_SLUG,
			schema: self::CONVERSATION_SCHEMA,
			uuid: (string)$conversation->getUuid()
		);
	}//end archiveConversation()

	/**
	 * Fetch a page of messages for a conversation, oldest-first.
	 *
	 * @param string $conversationId Conversation UUID.
	 * @param int $limit Page size.
	 * @param int $offset Page offset.
	 *
	 * @return array<int, ObjectEntity> Message objects.
	 *
	 * @spec openspec/changes/agent-engine-port/tasks.md#task-4-1
	 */
	private function findMessages(string $conversationId, int $limit, int $offset): array {
		$messages = $this->objectService
			->setRegister(self::REGISTER_SLUG)
			->setSchema(self::MESSAGE_SCHEMA)
			->findAll(
				config: [
					'filters' => ['conversationId' => $conversationId],
					'sort' => ['created' => 'ASC'],
					'limit' => $limit,
					'offset' => $offset,
				]
			);

		return array_values(array_filter($messages, static fn ($msg): bool => $msg instanceof ObjectEntity));
	}//end findMessages()

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
					'conversationId' => $conversationId,
					'_limit' => 1,
				]
			);

		return (int)($paginated['total'] ?? 0);
	}//end countMessages()

	/**
	 * Count the objects of a schema via the paginated search total
	 * (org-scoped by ObjectService multitenancy).
	 *
	 * @param string $schema Schema slug.
	 *
	 * @return int Object count.
	 *
	 * @spec openspec/changes/agent-engine-port/tasks.md#task-4-1
	 */
	private function countObjects(string $schema): int {
		$paginated = $this->objectService
			->setRegister(self::REGISTER_SLUG)
			->setSchema($schema)
			->searchObjectsPaginated(query: ['_limit' => 1]);

		return (int)($paginated['total'] ?? 0);
	}//end countObjects()

	/**
	 * Find the caller's existing feedback object for a message, if any.
	 *
	 * @param string $messageId Message UUID.
	 * @param string $userId Current user id.
	 *
	 * @return ObjectEntity|null The existing feedback object, or null.
	 *
	 * @spec openspec/changes/agent-engine-port/tasks.md#task-4-1
	 */
	private function findExistingFeedback(string $messageId, string $userId): ?ObjectEntity {
		$existing = $this->objectService
			->setRegister(self::REGISTER_SLUG)
			->setSchema(self::FEEDBACK_SCHEMA)
			->findAll(
				config: [
					'filters' => [
						'messageId' => $messageId,
						'userId' => $userId,
					],
					'limit' => 1,
				]
			);

		foreach ($existing as $candidate) {
			if ($candidate instanceof ObjectEntity) {
				return $candidate;
			}
		}

		return null;
	}//end findExistingFeedback()

	/**
	 * Serialize a message object to the OR-compatible response shape.
	 *
	 * @param ObjectEntity $message The message object.
	 *
	 * @return array<string, mixed> Serialized message.
	 *
	 * @spec openspec/changes/agent-engine-port/tasks.md#task-4-1
	 */
	private function serializeMessage(ObjectEntity $message): array {
		$data = $message->getObject();

		return [
			'id' => $message->getUuid(),
			'uuid' => $message->getUuid(),
			'conversationId' => ($data['conversationId'] ?? null),
			'role' => ($data['role'] ?? null),
			'content' => ($data['content'] ?? null),
			'sources' => ($data['sources'] ?? []),
			'created' => $message->getCreated()?->format('c'),
		];
	}//end serializeMessage()

	/**
	 * Serialize a feedback object to the OR-compatible response shape.
	 *
	 * @param ObjectEntity $feedback The feedback object.
	 *
	 * @return array<string, mixed> Serialized feedback.
	 *
	 * @spec openspec/changes/agent-engine-port/tasks.md#task-4-1
	 */
	private function serializeFeedback(ObjectEntity $feedback): array {
		$data = $feedback->getObject();

		return [
			'id' => $feedback->getUuid(),
			'uuid' => $feedback->getUuid(),
			'messageId' => ($data['messageId'] ?? null),
			'conversationId' => ($data['conversationId'] ?? null),
			'agentId' => ($data['agentId'] ?? null),
			'userId' => ($data['userId'] ?? null),
			'type' => ($data['type'] ?? null),
			'comment' => ($data['comment'] ?? null),
			'created' => $feedback->getCreated()?->format('c'),
		];
	}//end serializeFeedback()

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
		$user = $this->userSession->getUser();
		if ($user === null) {
			throw new Exception('Authentication required', 401);
		}

		return $user->getUID();
	}//end requireUserId()
}//end class

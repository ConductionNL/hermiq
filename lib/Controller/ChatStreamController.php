<?php

/**
 * Hermiq ChatStreamController.
 *
 * SSE streaming endpoint for the AI chat companion widget, ported bit-for-bit
 * from OpenRegister's ChatStreamController (agent-engine-port task 4.2). Wraps
 * the in-app Engine::processMessage pipeline (RAG + tool loop + LLM) and emits
 * a Server-Sent Events stream conformant to the six-event envelope defined in
 * hydra ADR-034 Decision 6: `token` / `tool_call` / `tool_result` / `heartbeat`
 * (15s interleave) / `final` / `error` — exactly one terminal `final` OR
 * `error` per request; every `tool_call` is followed by a `tool_result` before
 * `final`; non-streaming providers degrade to zero `token` events plus one
 * `final` carrying the full text.
 *
 * Persistence adaptation: conversation resolution/creation runs against the
 * `conversation` object in the `hermiq` OpenRegister register via ObjectService
 * (UUID ids), replacing OR's ConversationMapper/AgentMapper.
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
 * @spec openspec/changes/archive/2026-09-06-agent-engine-port/tasks.md#task-4-2
 */

declare(strict_types=1);

namespace OCA\Hermiq\Controller;

use OCA\Hermiq\Kernel\AppIdentity;
use OCA\Hermiq\Kernel\Contracts\AgentAccessServiceInterface;
use OCA\Hermiq\Kernel\Contracts\EngineInterface;
use OCA\Hermiq\Kernel\Contracts\LiteracyRequirementInterface;
use OCA\Hermiq\Kernel\Contracts\RunStepBusInterface;
use OCA\Hermiq\Kernel\Contracts\TalkTurnMirrorInterface;
use OCA\Hermiq\Kernel\Contracts\ToolAccessRequestServiceInterface;
use OCA\Hermiq\Service\AppAssistantResolver;
use OCA\Hermiq\Service\Chat\ChatRefusalFrame;
use OCA\Hermiq\Service\Chat\SessionSubject;
use OCA\Hermiq\Service\Chat\TurnScope;
use OCA\Hermiq\Service\Chat\TurnScopeException;
use OCA\Hermiq\Service\Engine\RunTraceCollector;
use OCA\Hermiq\Service\Engine\SanitizesForSaveTrait;
use OCA\Hermiq\Service\Engine\StreamYieldChannel;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Capability\ToolGrantResolutionException;
use OCA\OpenRegister\Service\Capability\ToolGrantResolver;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Response;
use OCP\IDBConnection;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Implements POST /api/chat/stream — Server-Sent Events streaming of an AI
 * response. Emits the contractual envelope; when the active provider supports
 * LLPhant streaming the Engine drives the StreamYieldChannel, otherwise the
 * synchronous fallback (zero `token` events + one `final`) applies — both
 * shapes are part of the contract, so clients never branch.
 *
 * @spec openspec/changes/archive/2026-09-06-agent-engine-port/tasks.md#task-4-2
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) SSE controller composes Engine +
 * ObjectService + IUserSession + IDBConnection + LoggerInterface; each handles a
 * separate concern of the streaming pipeline (auth, DB commit safety, conversation
 * routing, message processing) and cannot be removed without losing functionality.
 */
class ChatStreamController extends Controller {
	use SanitizesForSaveTrait;

	/**
	 * Forbidden error sentinel used by resolveConversation() when the
	 * caller does not own the requested conversation.
	 *
	 * @var string
	 */
	private const ERROR_FORBIDDEN = 'cn_chat_stream_forbidden';

	/**
	 * Generic public-facing error message. We deliberately never leak
	 * exception messages over the SSE wire — they can contain DB
	 * connection strings, API key fragments or internal paths. The
	 * full $e->getMessage() is recorded in the logger instead.
	 *
	 * @var string
	 */
	private const PUBLIC_ERROR_MESSAGE = 'An internal error occurred.';

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
	 * Wall-clock timestamp (microtime float) of the most recently emitted
	 * SSE frame of any type. Used by the heartbeat-interleave pre-check
	 * inside the channel callbacks: when `now() - $lastEventAt >= 15.0`
	 * we emit a `heartbeat` frame before forwarding the next event.
	 *
	 * @var float
	 */
	private float $lastEventAt = 0.0;

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request object.
	 * @param EngineInterface $engine In-app agent engine facade (ported ChatService).
	 * @param ObjectService $objectService OpenRegister object read/write (single write-path).
	 * @param IUserSession $userSession Resolves the requesting user.
	 * @param IDBConnection $db Database connection, used to finalise any open
	 *                          transaction before the SSE handler bypasses the
	 *                          framework via exit; (connection-leak mitigation).
	 * @param LoggerInterface $logger PSR-3 logger.
	 * @param IL10N $l10n Renders the one error this controller shows the user
	 *                    verbatim (an unresolved tool grant) in their language
	 *                    — the message is built HERE, from the exception's
	 *                    structured data, because a service throwing it has no
	 *                    business knowing the reader's locale (ADR-007).
	 * @param RunStepBusInterface $runStepBus Publishes run steps to whichever surface is watching.
	 * @param ToolAccessRequestServiceInterface $accessRequests Raises and resolves an agent's
	 *                                                 requests for tools it lacks.
	 * @param AgentAccessServiceInterface $agentAccess The one per-agent access predicate.
	 * @param LiteracyRequirementInterface|null $literacy The course requirement (compliance-ai-literacy).
	 * @param AppAssistantResolver|null $assistants The agent for an app when a chat names none (agents-bound-to-their-app).
	 * @param TalkTurnMirrorInterface|null $mirror Mirrors a turn into the session's Talk room.
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) Constructor DI: each parameter is a
	 *   distinct injected collaborator, not a logic-bearing argument list.
	 *
	 * @spec openspec/changes/archive/2026-09-06-agent-engine-port/tasks.md#task-4-2
	 */
	public function __construct(
		IRequest $request,
		private readonly EngineInterface $engine,
		private readonly ObjectService $objectService,
		private readonly IUserSession $userSession,
		private readonly IDBConnection $db,
		private readonly LoggerInterface $logger,
		private readonly IL10N $l10n,
		private readonly RunStepBusInterface $runStepBus,
		private readonly ToolAccessRequestServiceInterface $accessRequests,
		private readonly AgentAccessServiceInterface $agentAccess,
		private readonly ?LiteracyRequirementInterface $literacy = null,
		private readonly ?AppAssistantResolver $assistants = null,
		private readonly ?TalkTurnMirrorInterface $mirror = null,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * SSE streaming chat endpoint.
	 *
	 * Emits text/event-stream with the contractual envelope. The method always
	 * terminates with exit; — it bypasses the NC response pipeline because the
	 * SSE framing must reach the wire before that pipeline would buffer it.
	 * The declared `: Response` return type is a formality required by the
	 * framework; cleanup before exit; is funnelled through emitAndExit() so DB
	 * transactions never leak under PHP-FPM.
	 *
	 * CSRF protection is REQUIRED (no @NoCSRFRequired), exactly as in OR: the
	 * endpoint is an authenticated POST that creates/updates Conversation +
	 * Message objects, and the client invokes it via `fetch()` (POST with a
	 * JSON body, requesttoken header attached), not EventSource.
	 *
	 * @return Response Never returned — emitAndExit() always terminates.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/chat-streaming-and-feedback/specs/agent-engine-port/spec.md#requirement-the-chat-page-streams-the-answer-as-the-agent-writes-it-req-chstrm-001
	 */
	public function stream(): Response {
		// Clear every output buffer layer (PHP, NC framework, any plugin) so the
		// first echo is flushed immediately. Its own method so unit tests can skip
		// it: closing PHPUnit's output buffer trips its "risky" detector.
		$this->clearOutputBuffers();

		$this->emitSseHeaders();

		try {
			$user = $this->userSession->getUser();
			if ($user === null) {
				$this->emitAndExit(
					eventType: 'error',
					payload: ['code' => 'unauthenticated', 'message' => 'Authentication required']
				);
			}

			// The identity is the session's, never a request value.
			$turn = $this->readTurn(userId: $user->getUID());
			$turn['agentUuid'] = $this->turnAgent(turn: $turn);
			$conversation = $this->turnConversation(turn: $turn);
			$turn['conversationId'] = (string)$conversation->getUuid();

			// Emit a heartbeat right after headers so the client knows we're alive
			// (the synchronous LLM call may take >15s on cold-load).
			$this->emitSseEvent(eventType: 'heartbeat', payload: ['ts' => gmdate('c')]);
			$this->lastEventAt = $this->now();

			$channel = $this->streamChannel();
			// Start from an empty bucket so a previous turn's tool calls are
			// never replayed against this one.
			$this->runStepBus->clear(conversationId: $turn['conversationId']);

			// Compliance-ai-literacy: an organisation may require the course first.
			$this->literacy?->assertMayUseAgents(uid: $turn['userId']);

			$result = $this->engine->processMessage(
				conversationId: $turn['conversationId'],
				userId: $turn['userId'],
				userMessage: $turn['message'],
				selectedViews: [],
				selectedTools: [],
				ragSettings: $turn['ragSettings'],
				context: $turn['contextArr'],
				channel: $channel,
				// Chat-how-an-answer-came-about D1: a trace on this path too, so a
				// streamed answer gets the same provenance record as a sent one.
				trace: new RunTraceCollector(),
				attachments: $turn['attachments'],
				turnChannel: 'hermiq'
			);

			$this->replayRunSteps(conversationId: $turn['conversationId']);
			// A session with a Talk room gets this turn in the room too, queued so
			// the stream never waits on Talk (chat-carry-a-conversation-across-channels D2).
			$this->mirror?->enqueue(
				session: (array)$conversation->getObject(),
				sessionId: $turn['conversationId'],
				userId: $turn['userId'],
				question: (string)$turn['message'],
				answer: (string)($result['response'] ?? ($result['message'] ?? ''))
			);
			$this->emitAndExit(eventType: 'final', payload: $this->finalPayload(result: $result, turn: $turn));
		} catch (ToolGrantResolutionException $e) {
			$this->emitToolGrantsUnresolved(exception: $e);
		} catch (Throwable $e) {
			$this->emitFailure(exception: $e);
		}//end try

		// Unreachable — emitAndExit() never returns. Present so the declared
		// `: Response` return type (required by the framework) is satisfied.
		// The SSE framing is already written to the wire by the time we get
		// anywhere near this line.
		// @phpstan-ignore-next-line deadCode.unreachable — deliberate, see above.
		return new Response();
	}//end stream()

	/**
	 * The turn the request body asks for, for the session user; a turn with no
	 * message ends the stream with `missing_message`.
	 *
	 * The raw `context` snapshot is kept to echo back on the `final` frame
	 * unchanged; `contextArr` is that snapshot when it is an array, else empty,
	 * because the user Message stores it and the agent fallback reads the app
	 * out of it. `attachments` are the files the companion attached or the Chat
	 * page picked; the engine reads each one as the speaker.
	 *
	 * @param string $userId The session user.
	 *
	 * @return array{userId: string, message: string, agentUuid: string, conversationUuid: string, context: mixed,
	 *     contextArr: array, attachments: array, ragSettings: array<string, mixed>}
	 *
	 * @spec openspec/changes/archive/2026-09-06-agent-engine-port/tasks.md#task-4-2
	 */
	private function readTurn(string $userId): array {
		$rawBody = $this->readRequestBody();
		if ($rawBody === '') {
			$rawBody = '[]';
		}

		$body = (json_decode($rawBody, associative: true) ?? []);

		$userMessage = trim((string)($body['message'] ?? ''));
		if ($userMessage === '') {
			$this->emitAndExit(
				eventType: 'error',
				payload: ['code' => 'missing_message', 'message' => 'message content is required']
			);
		}

		$turn = [
			'userId' => $userId,
			'message' => $userMessage,
			'agentUuid' => (string)($body['agentUuid'] ?? ''),
			'conversationUuid' => (string)($body['conversationUuid'] ?? ''),
			'context' => ($body['context'] ?? null),
			'contextArr' => [],
			'attachments' => [],
		];
		if (is_array($turn['context']) === true) {
			$turn['contextArr'] = $turn['context'];
		}

		if (is_array($body['attachments'] ?? null) === true) {
			$turn['attachments'] = array_values($body['attachments']);
		}

		$turn['ragSettings'] = $this->readScope(body: $body);

		return $turn;
	}//end readTurn()

	/**
	 * The RAG settings a turn's optional scope gives: one file or folder.
	 *
	 * Any other shape ends the stream before anything is stored.
	 *
	 * @param array<string, mixed> $body The decoded request body.
	 *
	 * @return array<string, mixed> The RAG settings, with `scope` when one was given.
	 *
	 * @spec openspec/changes/chat-about-a-document-cites-its-sources/specs/document-chat/spec.md#requirement-a-turn-can-be-about-one-document-req-dchat-001
	 */
	private function readScope(array $body): array {
		$ragSettings = [];
		try {
			$scope = (new TurnScope())->parse(value: ($body['scope'] ?? null));
		} catch (TurnScopeException $e) {
			$this->emitAndExit(eventType: 'error', payload: ['code' => $e->errorCode(), 'message' => $e->getMessage()]);
		}

		if ($scope !== null) {
			$ragSettings['scope'] = $scope;
		}

		return $ragSettings;
	}//end readScope()

	/**
	 * The turn's agent: the one named, or for a fresh chat that names none an
	 * agent the session user can access, preferring the app the caller sits in
	 * (`context.appId`). With neither, the stream ends with `missing_agent`.
	 *
	 * Never the first agent in the register: that would cross tenant and user
	 * boundaries, and an agent from another app answers with tools for a
	 * product the person is not looking at.
	 *
	 * @param array<string, mixed> $turn The turn (see readTurn()).
	 *
	 * @return string
	 *
	 * @spec openspec/changes/archive/2026-09-06-agent-engine-port/tasks.md#task-4-2
	 */
	private function turnAgent(array $turn): string {
		if ($turn['conversationUuid'] !== '' || $turn['agentUuid'] !== '') {
			return $turn['agentUuid'];
		}

		$agentUuid = $this->pickFallbackAgentForUser(
			userId: $turn['userId'],
			applicationSlug: trim((string)($turn['contextArr']['appId'] ?? ''))
		);
		if ($agentUuid === '') {
			$this->emitAndExit(
				eventType: 'error',
				payload: [
					'code' => 'missing_agent',
					'message' => 'No agent available; configure one in Hermiq settings.',
				]
			);
		}

		return $agentUuid;
	}//end turnAgent()

	/**
	 * The turn's conversation, loaded for its owner or created; a conversation
	 * the session user does not own ends the stream with `forbidden`.
	 *
	 * @param array<string, mixed> $turn The turn (see readTurn()), with its agent.
	 *
	 * @return ObjectEntity
	 *
	 * @spec openspec/changes/archive/2026-09-06-agent-engine-port/tasks.md#task-4-2
	 */
	private function turnConversation(array $turn): ObjectEntity {
		try {
			return $this->resolveConversation(turn: $turn);
		} catch (RuntimeException $e) {
			if ($e->getMessage() === self::ERROR_FORBIDDEN) {
				$this->emitAndExit(
					eventType: 'error',
					payload: ['code' => 'forbidden', 'message' => 'Forbidden']
				);
			}

			throw $e;
		}
	}//end turnConversation()

	/**
	 * The streaming channel, forwarding tokens, tool calls and tool results.
	 *
	 * Each callback goes through `forwardWithHeartbeat()`, which interleaves a
	 * `heartbeat` frame when more than 15s have passed since the last emit
	 * (ADR-034 design D3). When the provider does not stream the channel stays
	 * idle, the synchronous fallback the SSE contract allows. There is no
	 * `onHeartbeat`: see the note at the foot of StreamYieldChannel.
	 *
	 * @return StreamYieldChannel
	 *
	 * @spec openspec/changes/archive/2026-09-06-agent-engine-port/tasks.md#task-4-2
	 */
	private function streamChannel(): StreamYieldChannel {
		$channel = new StreamYieldChannel();
		$channel->onToken(
			callback: fn (string $delta) => $this->forwardWithHeartbeat(
				eventType: 'token',
				payload: ['delta' => $delta]
			)
		);
		$channel->onToolCall(
			callback: fn (array $payload) => $this->forwardWithHeartbeat(
				eventType: 'tool_call',
				payload: $payload
			)
		);
		$channel->onToolResult(
			callback: fn (array $payload) => $this->forwardWithHeartbeat(
				eventType: 'tool_result',
				payload: $payload
			)
		);

		return $channel;
	}//end streamChannel()

	/**
	 * Replay the tool calls the turn made over the governed MCP transport, as the
	 * same `tool_call` / `tool_result` events the in-process path emits.
	 *
	 * On `executionMode: cli` every tool runs in a separate request, so the
	 * channel never fires. The steps arrive after the work rather than during it,
	 * because the bucket can only be read once the turn returns.
	 *
	 * @param string $conversationId The conversation.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-09-06-agent-engine-port/tasks.md#task-4-2
	 */
	private function replayRunSteps(string $conversationId): void {
		foreach ($this->runStepBus->drain(conversationId: $conversationId) as $step) {
			$this->forwardWithHeartbeat(
				eventType: 'tool_call',
				payload: [
					'toolId' => (string)($step['toolId'] ?? ''),
					'name' => (string)($step['name'] ?? ''),
					'arguments' => ($step['arguments'] ?? []),
				]
			);
			$this->forwardWithHeartbeat(
				eventType: 'tool_result',
				payload: [
					'toolId' => (string)($step['toolId'] ?? ''),
					'result' => (string)($step['result'] ?? ''),
					'outcome' => (string)($step['outcome'] ?? 'ok'),
					'durationMs' => (int)($step['durationMs'] ?? 0),
				]
			);
		}
	}//end replayRunSteps()

	/**
	 * The `final` frame: the assistant text, the context echoed back, the
	 * approvals awaiting the owner, and the answer's attachment notices and
	 * attachments.
	 *
	 * The `response`/`message` fallbacks are kept from the OR ground truth so a
	 * future engine return-shape change can never silently break the wire
	 * contract (hence the phpstan ignores on the shape-narrowed reads).
	 *
	 * @param array<string, mixed> $result The engine's envelope.
	 * @param array<string, mixed> $turn   The turn, with its agent and conversation.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/archive/2026-09-06-agent-engine-port/tasks.md#task-4-2
	 * @spec openspec/changes/chat-how-an-answer-came-about/specs/answer-transparency/spec.md#scenario-a-streamed-answer-gets-the-same-record-as-a-sent-one
	 */
	private function finalPayload(array $result, array $turn): array {
		return [
			// @phpstan-ignore-next-line -- deliberate defensive fallback, see above.
			'messageId' => (string)($result['messageId'] ?? ''),
			'conversationUuid' => $turn['conversationId'],
			// @phpstan-ignore-next-line -- deliberate defensive fallback, see above.
			'fullText' => (string)($result['response'] ?? $result['message'] ?? ''),
			'context' => $turn['context'],
			// Anything now awaiting the owner's decision, so it can be acted on in
			// the chat rather than on an oversight screen.
			'pendingApprovals' => $this->accessRequests->pendingApprovals(
				agentId: $turn['agentUuid']
			),
			// Chat-attachments-and-images D6: a notice per attachment the model could
			// not read natively. On `final`, so no new SSE event type is added
			// (hydra ADR-034 Decision 6).
			'attachmentNotices' => array_values(array_map('strval', (array)($result['attachmentNotices'] ?? []))),
			// Chat-attachments-and-images D8: the answer's attachments, such as an
			// image the agent created, so a client can show them without a reload.
			'attachments' => array_values((array)($result['attachments'] ?? [])),
			// Chat-about-a-document: the answer's sources in citation order, so
			// marker [n] is sources[n-1] without a reload.
			'sources' => array_values((array)($result['sources'] ?? [])),
			// Chat-how-an-answer-came-about: how the answer came about, as stored on it.
			'provenance' => ($result['provenance'] ?? null),
		];
	}//end finalPayload()

	/**
	 * End the stream for tool grants that resolve to no tools, unmasked.
	 *
	 * This message is safe by construction: it carries the agent's own grant
	 * ids, configuration its owner already reads in the tool-grant editor, and
	 * never a secret, path or connection string. Masking it would leave the
	 * owner with "an internal error occurred" for a misconfiguration only they
	 * can fix.
	 *
	 * @param ToolGrantResolutionException $exception The resolution failure.
	 *
	 * @return never
	 *
	 * @spec openspec/changes/archive/2026-09-06-agent-engine-port/tasks.md#task-4-2
	 */
	private function emitToolGrantsUnresolved(ToolGrantResolutionException $exception): never {
		$this->logger->error(
			message: '[ChatStreamController] Agent tool grants resolved to no tools',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'exception' => $exception,
				'grants' => $exception->getGrants(),
			]
		);
		$this->emitAndExit(
			eventType: 'error',
			payload: [
				'code' => 'tool_grants_unresolved',
				// Rendered here rather than reusing $exception->getMessage(): the
				// exception's own message is for the log (English, always), while
				// this one is read by a person. The grant ids are interpolated,
				// never translated: they are identifiers.
				// The concatenation is only to satisfy the line-length limit:
				// the assembled string MUST stay byte-identical to the key in
				// l10n/*.json, or the lookup misses and every locale silently
				// falls back to English.
				'message' => $this->l10n->t(
					'This agent\'s tool grants resolve to no tools: %1$s. Check the ids against the tool '
					. 'catalog — an agent with no tools on purpose should be granted "%2$s" instead.',
					[
						implode(', ', $exception->getGrants()),
						ToolGrantResolver::NO_TOOLS_SENTINEL,
					]
				),
			]
		);
	}//end emitToolGrantsUnresolved()

	/**
	 * End the stream for a failed turn: a refusal the person can act on is told
	 * as it is, everything else is logged and masked as `stream_failed`.
	 *
	 * @param Throwable $exception The failure.
	 *
	 * @return never
	 *
	 * @spec openspec/changes/archive/2026-09-06-agent-engine-port/tasks.md#task-4-2
	 */
	private function emitFailure(Throwable $exception): never {
		$frames = new ChatRefusalFrame(l10n: $this->l10n);
		for ($cause = $exception; $cause !== null; $cause = $cause->getPrevious()) {
			$refusal = $frames->forCause(cause: $cause);
			if ($refusal !== null) {
				$this->emitAndExit(eventType: 'error', payload: $refusal);
			}
		}

		$this->logger->error(
			message: '[ChatStreamController] Stream failed',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'exception' => $exception,
				'error' => $exception->getMessage(),
			]
		);
		$this->emitAndExit(
			eventType: 'error',
			payload: [
				'code' => 'stream_failed',
				'message' => self::PUBLIC_ERROR_MESSAGE,
			]
		);
	}//end emitFailure()

	/**
	 * Emit a single SSE frame, release any open DB transaction, then exit.
	 *
	 * Centralising every termination point through this helper ensures that
	 * the connection-leak risk of bypassing NC's response pipeline ("exit;
	 * under PHP-FPM") is mitigated: any in-flight transaction is finalised
	 * before the process is torn down. The error path rolls back; only the
	 * successful `final` path commits — this is what makes `final`/`error`
	 * mutually exclusive terminals (exactly one per request).
	 *
	 * @param string $eventType Event type. When 'error', any
	 *                          open transaction is rolled back
	 *                          instead of committed — partial
	 *                          writes from a failed
	 *                          Engine::processMessage() call
	 *                          must not be persisted.
	 * @param array<string, mixed> $payload JSON-encodable payload.
	 *
	 * @return never
	 *
	 * @SuppressWarnings(PHPMD.ExitExpression) SSE streaming contract requires process termination after
	 * the final frame; exit cannot be replaced by a return because the calling flow in stream() must
	 * stop unconditionally.
	 *
	 * @spec openspec/changes/archive/2026-09-06-agent-engine-port/tasks.md#task-4-2
	 */
	protected function emitAndExit(string $eventType, array $payload): never {
		$this->emitSseEvent(eventType: $eventType, payload: $payload);
		$this->safeShutdown(rollback: $eventType === 'error');
		exit;
	}//end emitAndExit()

	/**
	 * Finalise any open DB transaction so the connection can be released
	 * cleanly when PHP shuts the process down after exit;.
	 *
	 * On the error path callers pass $rollback=true so partial writes left
	 * behind by a failed service call are discarded. The success path
	 * (the 'final' SSE frame) commits. Best-effort: swallow any exception so
	 * cleanup never masks the user-visible SSE frame we just emitted.
	 *
	 * @param bool $rollback True to roll back, false to commit.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-09-06-agent-engine-port/tasks.md#task-4-2
	 */
	private function safeShutdown(bool $rollback): void {
		try {
			if ($this->db->inTransaction() === true) {
				if ($rollback === true) {
					$this->db->rollBack();
					return;
				}

				$this->db->commit();
			}
		} catch (Throwable $e) {
			$this->logger->warning(
				message: '[ChatStreamController] Shutdown finalisation failed',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'rollback' => $rollback,
					'error' => $e->getMessage(),
				]
			);
		}
	}//end safeShutdown()

	/**
	 * Emit a single SSE frame and flush.
	 *
	 * @param string $eventType Event type (token / tool_call /
	 *                          tool_result / heartbeat / final /
	 *                          error).
	 * @param array<string, mixed> $payload JSON-encodable payload.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-09-06-agent-engine-port/tasks.md#task-4-2
	 */
	protected function emitSseEvent(string $eventType, array $payload): void {
		echo 'event: ' . $eventType . "\n";
		echo 'data: ' . json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n\n";
		flush();
	}//end emitSseEvent()

	/**
	 * Current wall-clock seconds as a float. Production returns
	 * `microtime(true)`; tests override to drive a fake clock.
	 *
	 * @return float Seconds since the Unix epoch.
	 *
	 * @spec openspec/changes/archive/2026-09-06-agent-engine-port/tasks.md#task-4-2
	 */
	protected function now(): float {
		return microtime(true);
	}//end now()

	/**
	 * Read the raw request body. Production reads `php://input`; tests
	 * override to inject a JSON body (the stream is not seedable under
	 * PHPUnit).
	 *
	 * @return string The raw request body ('' when unreadable).
	 *
	 * @spec openspec/changes/archive/2026-09-06-agent-engine-port/tasks.md#task-4-2
	 */
	protected function readRequestBody(): string {
		$rawBody = file_get_contents('php://input');
		if ($rawBody === false) {
			return '';
		}

		return $rawBody;
	}//end readRequestBody()

	/**
	 * Emit a payload as an SSE frame, interleaving a `heartbeat` frame
	 * first whenever ≥15s have elapsed since the last emit. Both the
	 * heartbeat (when emitted) and the incoming frame update
	 * `$this->lastEventAt` so back-to-back frames within the same yield
	 * cycle do not retrigger the interleave.
	 *
	 * The 15-second threshold is fixed per ADR-034 design D3. Pre-emit
	 * interleaving (rather than post-emit scheduling) keeps the contract
	 * exactly: "heartbeat appears immediately before the next outgoing
	 * frame when the wall-clock interval since the last event exceeds
	 * 15.0s".
	 *
	 * @param string $eventType Frame type to emit after the
	 *                          optional heartbeat.
	 * @param array<string, mixed> $payload Frame payload.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-09-06-agent-engine-port/tasks.md#task-4-2
	 */
	protected function forwardWithHeartbeat(string $eventType, array $payload): void {
		$now = $this->now();
		if (($now - $this->lastEventAt) >= 15.0) {
			$this->emitSseEvent(eventType: 'heartbeat', payload: ['ts' => gmdate('c')]);
			$this->lastEventAt = $now;
		}

		$this->emitSseEvent(eventType: $eventType, payload: $payload);
		$this->lastEventAt = $this->now();
	}//end forwardWithHeartbeat()

	/**
	 * Clear every output buffer layer (PHP, NC framework, any plugin) so
	 * the first SSE echo is flushed immediately. Extracted from stream()
	 * so unit tests can override and skip — closing PHPUnit's output
	 * buffer trips its "risky" detector.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-09-06-agent-engine-port/tasks.md#task-4-2
	 */
	protected function clearOutputBuffers(): void {
		while (ob_get_level() > 0) {
			ob_end_clean();
		}
	}//end clearOutputBuffers()

	/**
	 * Emit the three SSE response headers. Extracted so tests can skip
	 * (header() emits a "headers already sent" warning under PHPUnit
	 * because the test runner has already written output).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-09-06-agent-engine-port/tasks.md#task-4-2
	 */
	protected function emitSseHeaders(): void {
		header('Content-Type: text/event-stream');
		header('Cache-Control: no-cache');
		header('X-Accel-Buffering: no');
	}//end emitSseHeaders()

	/**
	 * Find the agent the current user starts a conversation with when the
	 * request names none: the app's assistant, else an agent of that app, else
	 * the first agent the user may use (AppAssistantResolver, shared with
	 * ChatController). Never "the first agent regardless of owner": that caused
	 * cross-user data exposure in multi-user deployments.
	 *
	 * @param string $userId Nextcloud user id.
	 * @param string $applicationSlug The app the caller is sitting in, or '' when unknown.
	 *
	 * @return string The accessible agent uuid, or '' when none is found.
	 *
	 * @spec openspec/changes/archive/2026-09-06-agent-engine-port/tasks.md#task-4-2
	 * @spec openspec/specs/agent-management-ui/spec.md#requirement-an-organisation-admin-picks-the-agent-that-answers-in-an-app-req-appag-002
	 */
	private function pickFallbackAgentForUser(string $userId, string $applicationSlug = ''): string {
		$resolver = $this->assistants ?? new AppAssistantResolver(
			objectService: $this->objectService,
			agentAccess: $this->agentAccess,
			logger: $this->logger
		);

		return $resolver->resolve(userId: $userId, appId: $applicationSlug);
	}//end pickFallbackAgentForUser()

	/**
	 * Resolve (load or create) the conversation referenced by the request.
	 *
	 * Mirrors ChatController::resolveConversation but kept local so this
	 * controller does not couple to the chat controller's private helpers.
	 * When a conversationUuid is supplied, ownership is verified against the
	 * calling user — preventing the IDOR where any authed user could
	 * read/append to another user's thread by guessing the uuid (gate-7).
	 * A new session records the record its page context names as its
	 * subject, when the caller can read that record.
	 *
	 * @param array<string, mixed> $turn The turn (see readTurn()): its
	 *                                   conversationUuid ('' to create new),
	 *                                   agentUuid (required when creating new),
	 *                                   userId and contextArr.
	 *
	 * @return ObjectEntity Resolved conversation.
	 *
	 * @throws RuntimeException When the caller does not own the conversation
	 *                          (message === self::ERROR_FORBIDDEN; translated
	 *                          into the `forbidden` SSE error by stream()),
	 *                          or when the conversation/agent does not exist
	 *                          (translated into `stream_failed` by the outer
	 *                          catch, matching OR's DoesNotExist behavior).
	 *
	 * @spec openspec/changes/archive/2026-09-06-agent-engine-port/tasks.md#task-4-2
	 * @spec openspec/specs/session-surface/spec.md#requirement-a-session-may-name-the-record-it-is-about
	 */
	private function resolveConversation(array $turn): ObjectEntity {
		$conversationUuid = $turn['conversationUuid'];
		$agentUuid = $turn['agentUuid'];
		$userId = $turn['userId'];
		if ($conversationUuid !== '') {
			$conversation = $this->objectService->find(
				id: $conversationUuid,
				register: self::REGISTER_SLUG,
				schema: self::CONVERSATION_SCHEMA
			);
			if ($conversation === null) {
				throw new RuntimeException('Conversation not found: ' . $conversationUuid);
			}

			// IDOR guard: find() does not scope by user, so we MUST verify the
			// conversation belongs to the caller. Without this check any authed
			// user could supply any conversationUuid and hijack another user's
			// thread.
			if (($conversation->getObject()['userId'] ?? null) !== $userId) {
				throw new RuntimeException(self::ERROR_FORBIDDEN);
			}

			return $conversation;
		}

		// Need an agent to create a new conversation.
		$agent = $this->objectService->find(
			id: $agentUuid,
			register: self::REGISTER_SLUG,
			schema: self::AGENT_SCHEMA
		);
		// A private agent the caller may not use answers exactly like a missing
		// one, so the stream cannot confirm it exists (hermiq#976).
		if ($agent === null || $this->agentAccess->canUserAccessAgent(agent: $agent, userId: $userId) === false) {
			throw new RuntimeException('Agent not found: ' . $agentUuid);
		}

		// `_rbac: false`: the Session schema lists no `create` grant on purpose
		// (hermiq#319), so the default save refuses every non-admin (hermiq#1086).
		// The agent access check above is the guard, and the session is always
		// the caller's. OpenRegister still stamps `_owner` from the user session.
		$subject = new SessionSubject(objectService: $this->objectService);

		return $this->objectService->saveObject(
			object: $this->sanitizeForSave(
				data: $subject->stamp(
					session: [
						'userId' => $userId,
						'agentId' => (string)$agent->getUuid(),
						'title' => 'New session',
					],
					context: $turn['contextArr']
				)
			),
			register: self::REGISTER_SLUG,
			schema: self::CONVERSATION_SCHEMA,
			_rbac: false
		);
	}//end resolveConversation()
}//end class

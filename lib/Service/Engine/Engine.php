<?php

/**
 * Hermiq Agent Engine facade.
 *
 * NAMING DECISION (binding for later chunks): the ported `ChatService` facade is
 * named `Engine` (FQCN `OCA\Hermiq\Service\Engine\Engine`) — matching the
 * proposal/design language ("the new in-app `Engine` facade") that chunk 2's
 * routes/controllers and the ScheduleService pivot are written against. The
 * "process a chat turn" entry point keeps the ported name and shape:
 *
 *   processMessage(
 *       string $conversationId,   // Conversation object UUID (was int id in OR)
 *       string $userId,
 *       string $userMessage,
 *       array  $selectedViews = [],
 *       array  $selectedTools = [],
 *       array  $ragSettings   = [],
 *       array  $context       = [],
 *       ?StreamYieldChannel $channel = null,
 *       ?RunTraceCollector $trace = null
 *   ): array{message: string, messageId: string, sources: list<array>,
 *            timings: array{context: string, history: string, llm: string, total: string},
 *            usage: array<string, int|float>,
 *            steps: array<int, array<string, mixed>>}
 *
 * The `usage` key (from `ResponseGenerationHandler::$lastUsage`) is load-bearing:
 * ScheduleService's `lastRunUsage` / run-analytics reads it (design.md risk) —
 * it MUST survive any future refactor of this return shape.
 *
 * The `steps` key (run-trace-observability) is the optional `$trace` collector's
 * ordered step timeline (`context`/`history`/`llm`/`tool`), empty when no
 * collector is supplied — existing callers that omit `$trace` see zero behavior
 * change beyond the new, always-empty-or-populated `steps` key.
 *
 * Ported from `OCA\OpenRegister\Mcp\Service\ChatService`: thin facade that
 * orchestrates specialized handlers, re-pointed at `Agent`/`Conversation`/
 * `Message` OR objects in the `hermiq` register (agent-engine-schemas) via
 * `ObjectService` instead of OR's Conversation/Message/Agent QBMappers.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Engine
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/2026-09-06-agent-engine-port/tasks.md#1-port-the-chat-engine
 * @spec openspec/changes/archive/2026-09-06-agent-engine-port/tasks.md#1-port-the-chat-engine
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Engine;

use Exception;
use OCA\Hermiq\BackgroundJob\ConversationTitleJob;
use OCA\Hermiq\Kernel\Contracts\EngineInterface;
use OCA\Hermiq\Service\Agent\AgentAvailability;
use OCA\Hermiq\Service\AiFeature\RedactionRequiredException;
use OCA\Hermiq\Service\Chat\AttachmentRefusedException;
use OCA\Hermiq\Service\Chat\ImageGenerationService;
use OCA\Hermiq\Service\Chat\TurnAttachmentResolver;
use OCA\Hermiq\Service\GuardrailPolicyService;
use OCA\Hermiq\Service\Talk\ConversationParticipation;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\BackgroundJob\IJobList;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Engine
 *
 * Thin facade that orchestrates chat operations across specialized handlers.
 *
 * Handlers:
 * - ContextRetrievalHandler: RAG context retrieval
 * - ResponseGenerationHandler: LLM calls (via Llm\ProviderFactory)
 * - ConversationManagementHandler: titles, summaries
 * - MessageHistoryHandler: message storage and history
 * - ToolLoop: tool/function calling via the OR ToolRegistryFacade
 * - ContextAssembler: resolves an agent's attached Context objects into a budgeted
 *   system-prompt preamble (agent-context-system, distinct from ContextRetrievalHandler's
 *   per-turn RAG search)
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Engine
 *
 * @spec openspec/changes/archive/2026-09-06-agent-engine-port/tasks.md#1-port-the-chat-engine
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Facade orchestrates the handler set by design.
 * @SuppressWarnings(PHPMD.LongVariable)           `$guardrailPolicyService` is a promoted
 *   constructor collaborator named after its class (GuardrailPolicyService) —
 *   shortening it would obscure which service is injected.
 */
class Engine implements EngineInterface {
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
	 * Constructor.
	 *
	 * @param ObjectService $objectService OpenRegister object read/write.
	 * @param ContextRetrievalHandler $contextHandler RAG context handler.
	 * @param ResponseGenerationHandler $responseHandler LLM response handler.
	 * @param ConversationManagementHandler $conversationHandler Title/summary handler.
	 * @param MessageHistoryHandler $historyHandler Message storage/history handler.
	 * @param ContextAssembler $contextAssembler Resolves an agent's attached
	 *                                           Context objects into a
	 *                                           system-prompt preamble
	 *                                           (agent-context-system).
	 * @param LoggerInterface $logger Logger.
	 * @param GuardrailPolicyService|null $guardrailPolicyService Resolves + applies the effective
	 *                                                            GuardrailPolicy's input/output
	 *                                                            filters (agent-guardrails).
	 *                                                            Nullable/optional purely so
	 *                                                            existing test callers that omit
	 *                                                            it see zero behavior change
	 *                                                            (equivalent to "no policy
	 *                                                            configured" — fail-open,
	 *                                                            design.md Decision 1); real DI
	 *                                                            always provides it.
	 * @param IJobList|null $jobList Queues the conversation-title job
	 *                               so naming never sits on the reply's
	 *                               critical path
	 *                               (session-context-performance).
	 *                               Nullable/defaulted for the same
	 *                               backward-compat reason; a null list
	 *                               simply means no title is queued —
	 *                               the reply is unaffected, which is
	 *                               the point of deferring it.
	 * @param ConversationParticipation $participation The owner-or-listed-participant
	 *                                                 guard (talk-shared-sessions).
	 *                                                 Defaulted so every existing
	 *                                                 caller constructs unchanged; the
	 *                                                 class is dependency-free.
	 * @param IUserManager|null $userManager Resolves a speaker's display name for a
	 *                                       shared session's turn.
	 * @param PromptVariableResolver|null $promptVariables Fills the placeholders in
	 *                                                     Agent.prompt for the acting
	 *                                                     person (agents-instruction-variables);
	 *                                                     null leaves the prompt as written.
	 * @param TurnAttachmentResolver|null $attachmentResolver Reads a turn's attachments as the
	 *                                                        person who sent it
	 *                                                        (chat-attachments-and-images); null
	 *                                                        refuses any turn that carries one.
	 * @param ImageGenerationService|null $images Hands over the images the agent created in the
	 *                                            turn, for the answer (chat-attachments-and-images D8).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-09-06-agent-engine-port/tasks.md#1-port-the-chat-engine
	 * @spec openspec/changes/archive/2026-09-06-agent-context-system/tasks.md#3-engine-wiring
	 * @spec openspec/changes/archive/2026-07-13-agent-guardrails/tasks.md#task-3-wire-input-output-filters-into-engine-processmessage
	 * @spec openspec/specs/agent-engine-port/spec.md
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) Constructor DI: each parameter is a
	 *   distinct collaborator, not a logic-bearing argument list.
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly ContextRetrievalHandler $contextHandler,
		private readonly ResponseGenerationHandler $responseHandler,
		private readonly ConversationManagementHandler $conversationHandler,
		private readonly MessageHistoryHandler $historyHandler,
		private readonly ContextAssembler $contextAssembler,
		private readonly LoggerInterface $logger,
		private readonly ?GuardrailPolicyService $guardrailPolicyService = null,
		private readonly ?IJobList $jobList = null,
		private readonly ConversationParticipation $participation = new ConversationParticipation(),
		private readonly ?IUserManager $userManager = null,
		private readonly ?PromptVariableResolver $promptVariables = null,
		private readonly ?TurnAttachmentResolver $attachmentResolver = null,
		private readonly ?ImageGenerationService $images = null,
	) {
	}//end __construct()

	/**
	 * Process a chat message and generate the AI response.
	 *
	 * Main orchestration method that coordinates all handlers. See the class
	 * docblock for the binding signature/return-shape contract.
	 *
	 * @param string $conversationId Conversation object UUID.
	 * @param string $userId User id (must own the conversation).
	 * @param string $userMessage User message text.
	 * @param array $selectedViews View filters for multitenancy (optional).
	 * @param array $selectedTools Tool registry ids to use (optional).
	 * @param array $ragSettings RAG configuration overrides (optional).
	 * @param array $context AI Chat Companion context snapshot the
	 *                       frontend sent. Persisted on the
	 *                       user-authored Message when non-empty.
	 * @param StreamYieldChannel|null $channel Streaming channel forwarded to the response
	 *                                         handler so SSE consumers can interleave
	 *                                         `token`/`tool_call`/`tool_result` frames as
	 *                                         the LLM yields. Null for blocking callers.
	 * @param RunTraceCollector|null $trace Optional run-trace collector
	 *                                      (run-trace-observability);
	 *                                      when supplied,
	 *                                      context/history/llm/tool
	 *                                      steps are timed into it and
	 *                                      returned as the envelope's
	 *                                      `steps` key. Null for
	 *                                      callers that do not need a
	 *                                      step timeline (zero behavior
	 *                                      change).
	 * @param bool $dryRun Whether this turn is a dry-run preview
	 *                     (run-replay-and-dry-run); threaded
	 *                     onto
	 *                     `ResponseGenerationHandler::generateResponse()`
	 *                     so a side-effecting tool call is
	 *                     neutralised instead of actually
	 *                     invoked. False (every pre-existing
	 *                     caller) is byte-for-byte unchanged
	 *                     behavior.
	 * @param array|null $skillSetOverride Per-run effective-skill-set override
	 *                                     (skill uuids) for the run-loop
	 *                                     skill-exposure seam (skill-evals): a
	 *                                     paired eval half varies exactly this
	 *                                     set. Null (every non-eval caller)
	 *                                     exposes the agent's stored
	 *                                     `skillInstalls`.
	 * @param string|null $authorId Uid of the human who produced this turn,
	 *                              persisted on the user-authored Message
	 *                              so a shared session records who spoke
	 *                              (talk-shared-sessions). Null for
	 *                              single-speaker callers — byte-for-byte
	 *                              unchanged behavior.
	 * @param string|null $authorDisplayName That human's display name AT SEND TIME,
	 *                                       captured deliberately and never re-resolved
	 *                                       so a transcript stays legible after a
	 *                                       rename or a deleted account (ADR-004).
	 * @param array $attachments The files the turn names, as the request sent them
	 *                           ({fileId} or {path}). Resolved in the speaker's Files
	 *                           before the turn is stored or any model is called, and
	 *                           kept on the user turn as references only.
	 *
	 * @return array The result envelope.
	 *
	 * @throws \Exception If processing fails (unknown conversation, access denied,
	 *                    provider/LLM failure).
	 *
	 * @psalm-return array{message: string, messageId: string, sources: list<array>,
	 *     timings: array{context: string, history: string, llm: string, total: string},
	 *     usage: array<string, int|float>, steps: array<int, array<string, mixed>>,
	 *     skillsUsed: array<int, string>}
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) Each parameter is a distinct, independently
	 *   optional input (run-trace-observability adds one more to an already-wide, long-established
	 *   list) — grouping them would obscure, not simplify, the call site.
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)    Dry-run preview (run-replay-and-dry-run)
	 *   is a cross-cutting mode threaded through the engine as a flag by design.
	 *
	 * @spec openspec/changes/archive/2026-09-06-agent-engine-port/tasks.md#1-port-the-chat-engine
	 * @spec openspec/changes/archive/2026-09-06-agent-engine-port/tasks.md#1-port-the-chat-engine
	 * @spec openspec/changes/archive/2026-07-12-run-trace-observability/tasks.md#task-2-thread-the-collector-through-engine-toolloop-facadetoolinvoker
	 * @spec openspec/changes/archive/2026-07-13-run-replay-and-dry-run/tasks.md#task-3-thread-dryrun-through-toolloop-engine-and-responsegenerationhandler
	 * @spec openspec/specs/agent-evals/spec.md#requirement-the-engine-run-loop-exposes-the-effective-skill-set-to-a-run
	 * @spec openspec/changes/chat-attachments-and-images/specs/chat-attachments/spec.md#requirement-an-attachment-is-read-as-the-person-who-sent-it-req-catt-003
	 * @spec openspec/specs/agent-engine-port/spec.md#requirement-the-model-receives-the-users-turn-once
	 */
	public function processMessage(
		string $conversationId,
		string $userId,
		string $userMessage,
		array $selectedViews = [],
		array $selectedTools = [],
		array $ragSettings = [],
		array $context = [],
		?StreamYieldChannel $channel = null,
		?RunTraceCollector $trace = null,
		bool $dryRun = false,
		?array $skillSetOverride = null,
		?string $authorId = null,
		?string $authorDisplayName = null,
		array $attachments = [],
	): array {
		$this->logger->info(
			message: '[Engine] Processing message',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'conversationId' => $conversationId,
				'userId' => $userId,
				'messageLength' => strlen($userMessage),
			]
		);

		try {
			$turn = $this->admitTurn(
				request: [
					'conversationId' => $conversationId,
					'userId' => $userId,
					'authorId' => $authorId,
					'authorDisplayName' => $authorDisplayName,
					'attachments' => $attachments,
					'ragSettings' => $ragSettings,
				]
			);
			$turn += [
				'selectedViews' => $selectedViews,
				'selectedTools' => $selectedTools,
				'ragSettings' => $ragSettings,
				// The CnAiContext snapshot, kept apart from the RAG context of the turn.
				'cnAiContext' => $context,
				'channel' => $channel,
				'trace' => $trace,
				'dryRun' => $dryRun,
			];

			// The agent's attached Context objects (agent-context-system) and the
			// effective skill set's content (skill-evals), ahead of the RAG block.
			$turn += $this->turnPreamble(turn: $turn, skillSetOverride: $skillSetOverride);

			// Agent-guardrails: the policy is resolved once for the turn, and the input
			// filter runs BEFORE the user Message is stored and BEFORE any model call.
			// A block throws; a redaction replaces the text that is stored and sent.
			$turn['organisation'] = (string)($turn['conversation']->getOrganisation() ?? '');
			$guardrail = new TurnGuardrail(policies: $this->guardrailPolicyService, trace: $trace);
			$guardrail->resolve(organisation: $turn['organisation']);
			$guardrail->screenToolDetails(channel: $channel);
			$turn['userMessage'] = $guardrail->screenInput(text: $userMessage);

			$turn['userTurn'] = $this->storeUserTurn(turn: $turn);

			$timings = [];
			$ragContext = $this->turnContext(turn: $turn, timings: $timings);
			$turnHistory = $this->turnHistory(turn: $turn, timings: $timings);
			$aiResponse = $this->reviewedResponse(
				turn: $turn + ['ragContext' => $ragContext, 'turnHistory' => $turnHistory, 'guardrail' => $guardrail],
				timings: $timings
			);

			// Chat-attachments-and-images D8: an image the agent created in this turn
			// is stored on the answer, so the thread shows it where it was asked for.
			$turn += [
				'aiResponse' => $aiResponse,
				'answerAttachments' => ($this->images?->takeCreated() ?? []),
				'sources' => $ragContext['sources'],
			];
			// Chat-how-an-answer-came-about D1: identities and outcomes, never content.
			$turn['provenance'] = (new AnswerProvenance())->build(trace: $trace, sources: $turn['sources']);
			$turn['assistantStored'] = $this->storeAnswer(turn: $turn);

			return $this->turnEnvelope(turn: $turn, timings: $timings);
		} catch (Exception $e) {
			$this->logger->error(
				message: '[Engine] Message processing failed',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'error' => $e->getMessage(),
				]
			);
			throw $e;
		}//end try
	}//end processMessage()

	/**
	 * Admit a turn: the conversation exists, the caller may take a turn in it,
	 * the speaker is named, the attachments are read as the speaker, and the
	 * session's agent is loaded and switched on. Each refusal throws before
	 * anything is stored or any model is called.
	 *
	 * The owner-or-listed-participant check is NOT redundant with
	 * ChatController's: the Talk bridge reaches the engine from a background
	 * job without passing through the controller, so this is the only check on
	 * that path.
	 *
	 * @param array<string, mixed> $request The conversation id, caller, author and attachments.
	 *
	 * @return array<string, mixed> The request plus the conversation, its data, the agent and the resolved attachments.
	 *
	 * @throws Exception When the conversation is missing or the caller may not take a turn.
	 *
	 * @spec openspec/specs/talk-shared-sessions/spec.md#requirement-a-session-may-be-taken-up-by-its-owner-or-a-listed-participant
	 */
	private function admitTurn(array $request): array {
		$turn = $request;
		$turn['conversation'] = $this->objectService->find(
			id: $request['conversationId'],
			register: self::REGISTER_SLUG,
			schema: self::CONVERSATION_SCHEMA
		);
		if ($turn['conversation'] === null) {
			throw new Exception('Conversation not found: ' . $request['conversationId']);
		}

		$turn['conversationData'] = $turn['conversation']->getObject();
		if ($this->participation->mayTakeTurn(conversationData: $turn['conversationData'], userId: $request['userId']) === false) {
			throw new Exception('Access denied to conversation');
		}

		// Chat-work-together-in-one-session: in a session with participants every
		// human turn names its speaker, whichever entry point sent it. A
		// single-speaker session stays unchanged.
		if ($request['authorId'] === null && $this->participation->roster(conversationData: $turn['conversationData']) !== []) {
			$turn['authorId'] = $request['userId'];
			$turn['authorDisplayName'] = ($this->userManager?->get($request['userId'])?->getDisplayName() ?? $request['userId']);
		}

		// Chat-attachments-and-images: read every attached file as the person who
		// sent the turn, never as the owner or the agent's acting user.
		$turn['resolvedAttachments'] = $this->resolveAttachments(
			attachments: $request['attachments'],
			speaker: ($turn['authorId'] ?? $request['userId'])
		);

		// Chat-about-a-document: a turn scoped to a file or folder the speaker
		// cannot open is refused (404), read as the speaker like an attachment.
		$this->contextHandler->assertScopeReadable(
			ragSettings: $request['ragSettings'],
			speaker: ($turn['authorId'] ?? $request['userId'])
		);

		$turn['agent'] = null;
		$agentId = $turn['conversationData']['agentId'] ?? null;
		if (is_string($agentId) === true && $agentId !== '') {
			// _rbac false: the Agent read rule (hermiq#976) admits the owner, invited
			// users and group members, but a participant the session owner added may
			// take a turn with the session's agent without being any of those. The
			// owner-or-participant check above is the gate for this turn; tenancy
			// still applies.
			$turn['agent'] = $this->objectService->find(
				id: $agentId,
				register: self::REGISTER_SLUG,
				schema: self::AGENT_SCHEMA,
				_rbac: false
			);
		}

		// Agents-switch-off-and-stop: a switched-off agent starts no turn, on any
		// path that reaches the engine (chat, stream, Talk, ContextAgent, schedules).
		(new AgentAvailability())->assertRunnable(agent: $turn['agent']);

		return $turn;
	}//end admitTurn()

	/**
	 * The system-prompt preamble of the turn: the agent's attached Context
	 * objects, then the effective skill set's content (a per-run override, else
	 * the agent's stored skillInstalls), and the skill uuids exposed.
	 *
	 * A partial skill bundle (a test double or a future assembler swap) degrades
	 * to the no-skills case instead of warning.
	 *
	 * @param array<string, mixed> $turn             The admitted turn (its agent and caller).
	 * @param array|null           $skillSetOverride The per-run skill set, or null.
	 *
	 * @return array{contextPreamble: string, skillsUsed: mixed}
	 *
	 * @spec openspec/specs/agent-evals/spec.md#requirement-the-engine-run-loop-exposes-the-effective-skill-set-to-a-run
	 */
	private function turnPreamble(array $turn, ?array $skillSetOverride): array {
		$contextPreamble = $this->contextAssembler->assembleForAgent(agent: $turn['agent'], actingUserId: $turn['userId']);
		$skillBundle = $this->contextAssembler->assembleSkillsForRun(agent: $turn['agent'], skillSetOverride: $skillSetOverride);
		$skillText = (string)($skillBundle['text'] ?? '');

		if ($skillText !== '') {
			$contextPreamble = ltrim($contextPreamble . "\n\n" . $skillText, "\n");
		}

		return [
			'contextPreamble' => $contextPreamble,
			'skillsUsed' => ($skillBundle['skillsUsed'] ?? []),
		];
	}//end turnPreamble()

	/**
	 * Retrieve the turn's RAG context, timed as a `context` step when a trace is attached.
	 *
	 * @param array<string, mixed> $turn    The turn.
	 * @param array<string, float> $timings The turn's timings, filled in place.
	 *
	 * @return array The RAG context `{text, sources}`.
	 *
	 * @spec openspec/changes/archive/2026-07-12-run-trace-observability/tasks.md#task-2-thread-the-collector-through-engine-toolloop-facadetoolinvoker
	 */
	private function turnContext(array $turn, array &$timings): array {
		$token = $turn['trace']?->startStep(type: 'context', name: 'Context retrieval');
		$startTime = microtime(true);
		$context = $this->contextHandler->retrieveContext(
			query: $turn['userMessage'],
			agent: $turn['agent'],
			selectedViews: $turn['selectedViews'],
			ragSettings: $turn['ragSettings'],
			speaker: $this->scopeSpeaker(turn: $turn)
		);
		$timings['context'] = microtime(true) - $startTime;
		if ($token !== null) {
			$turn['trace']?->endStep(token: $token, outcome: 'ok');
		}

		return $context;
	}//end turnContext()

	/**
	 * The uid a scoped turn's files are read as: the speaker, never the owner.
	 * An unscoped turn reads no Files, so it passes none.
	 *
	 * @param array<string, mixed> $turn The turn.
	 *
	 * @return string The speaker's uid, or ''.
	 *
	 * @spec openspec/changes/chat-about-a-document-cites-its-sources/specs/document-chat/spec.md#requirement-a-turn-can-be-about-one-document-req-dchat-001
	 */
	private function scopeSpeaker(array $turn): string {
		if (is_array($turn['ragSettings']['scope'] ?? null) === false) {
			return '';
		}

		return (string)($turn['authorId'] ?? $turn['userId']);
	}//end scopeSpeaker()

	/**
	 * Build the turn's history of earlier turns, timed as a `history` step.
	 *
	 * The response handler appends this turn itself, so the stored copy is left
	 * out and the model reads it once. `turnText` is the turn as the history
	 * would have shown it, speaker-labelled in a shared session.
	 *
	 * @param array<string, mixed> $turn    The turn, with its stored user Message.
	 * @param array<string, float> $timings The turn's timings, filled in place.
	 *
	 * @return array{history: array, turnText: string}
	 *
	 * @spec openspec/specs/agent-engine-port/spec.md#requirement-the-model-receives-the-users-turn-once
	 */
	private function turnHistory(array $turn, array &$timings): array {
		$token = $turn['trace']?->startStep(type: 'history', name: 'History build');
		$startTime = microtime(true);
		$turnHistory = $this->historyHandler->buildTurnHistory(
			conversationId: $turn['conversationId'],
			currentTurn: $turn['userTurn'],
			turnText: $turn['userMessage']
		);
		$timings['history'] = microtime(true) - $startTime;
		if ($token !== null) {
			$turn['trace']?->endStep(token: $token, outcome: 'ok');
		}

		return $turnHistory;
	}//end turnHistory()

	/**
	 * The model's answer as the output guardrail lets it be stored and sent.
	 *
	 * Agent-guardrails: the output filter runs BEFORE the assistant Message is
	 * stored or returned; a block replaces the answer with the placeholder. A
	 * streamed answer that the filter may still change is held on the channel
	 * and released in its reviewed form.
	 *
	 * @param array<string, mixed> $turn    The turn, with its RAG context, history and guardrail.
	 * @param array<string, float> $timings The turn's timings, filled in place.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/agent-guardrails/spec.md#requirement-output-is-filtered-before-persistence-and-before-delivery
	 */
	private function reviewedResponse(array $turn, array &$timings): string {
		$guardrail = $turn['guardrail'];
		$channel = $turn['channel'];
		if ($channel !== null && $guardrail->screensOutput() === true) {
			$channel->holdTokens();
		}

		$aiResponse = $guardrail->screenOutput(text: $this->turnResponse(turn: $turn, timings: $timings));
		$channel?->releaseTokens(reviewed: $aiResponse);

		return $aiResponse;
	}//end reviewedResponse()

	/**
	 * Generate the model's answer, timed as an `llm` step that wraps every nested
	 * tool round trip. A file the feature's checks refused is told by its name.
	 *
	 * @param array<string, mixed> $turn    The turn, with its RAG context and history.
	 * @param array<string, float> $timings The turn's timings, filled in place.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/archive/2026-09-06-agent-engine-port/tasks.md#1-port-the-chat-engine
	 * @spec openspec/changes/archive/2026-07-13-run-replay-and-dry-run/tasks.md#task-3-thread-dryrun-through-toolloop-engine-and-responsegenerationhandler
	 */
	private function turnResponse(array $turn, array &$timings): string {
		$token = $turn['trace']?->startStep(type: 'llm', name: 'LLM generation');
		$startTime = microtime(true);
		try {
			$aiResponse = $this->responseHandler->generateResponse(
				userMessage: $turn['turnHistory']['turnText'],
				context: $turn['ragContext'],
				messageHistory: $turn['turnHistory']['history'],
				agent: $turn['agent'],
				selectedTools: $turn['selectedTools'],
				channel: $turn['channel'],
				cnAiContext: $turn['cnAiContext'],
				contextPreamble: $turn['contextPreamble'],
				trace: $turn['trace'],
				dryRun: $turn['dryRun'],
				conversationId: $turn['conversationId'],
				promptVariables: $this->promptVariablesFor(
					userId: $turn['userId'],
					scope: new PromptVariableScope(
						agent: $turn['agent'],
						organisation: $turn['organisation'],
						appContext: $turn['cnAiContext'],
						conversationData: $turn['conversationData']
					)
				),
				attachments: $turn['resolvedAttachments'],
				speaker: ($turn['authorId'] ?? $turn['userId'])
			);
		} catch (Exception $e) {
			throw $this->attachmentRefusalFor(exception: $e, attachments: $turn['resolvedAttachments']);
		}

		$timings['llm'] = microtime(true) - $startTime;
		if ($token !== null) {
			$turn['trace']?->endStep(token: $token, outcome: 'ok');
		}

		return $aiResponse;
	}//end turnResponse()

	/**
	 * The turn's result envelope (see the class docblock for the contract).
	 *
	 * @param array<string, mixed> $turn    The finished turn.
	 * @param array<string, float> $timings The context, history and llm timings.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/archive/2026-09-06-agent-engine-port/tasks.md#1-port-the-chat-engine
	 */
	private function turnEnvelope(array $turn, array $timings): array {
		$totalTime = ($timings['context'] + $timings['history'] + $timings['llm']);

		return [
			'message' => $turn['aiResponse'],
			// The persisted assistant message id, for SSE consumers.
			'messageId' => (string)($turn['assistantStored']->getUuid() ?? ''),
			'sources' => $turn['sources'],
			'timings' => [
				'context' => round($timings['context'], 2) . 's',
				'history' => round($timings['history'], 3) . 's',
				'llm' => round($timings['llm'], 2) . 's',
				'total' => round($totalTime, 2) . 's',
			],
			// Per-run LLM token/latency usage for run-cost recording
			// (run-analytics / ScheduleService::lastRunUsage): load-bearing,
			// see class docblock.
			'usage' => $this->responseHandler->lastUsage,
			// Chat-attachments-and-images D6: what happened to each attachment
			// the model could not read natively, for the answer's notice.
			'attachmentNotices' => $this->responseHandler->attachmentNotices,
			// The answer's attachments (D8), for the `final` frame.
			'attachments' => $turn['answerAttachments'],
			// Run-trace-observability: the collector's full ordered step
			// timeline, empty when no collector was supplied.
			'steps' => $turn['trace']?->toArray() ?? [],
			// Chat-how-an-answer-came-about: the record stored on the answer.
			'provenance' => $turn['provenance'],
			// Skill-evals: the skill uuids actually exposed to this run's context,
			// recorded on the run's audit entry for ALL runs.
			'skillsUsed' => $turn['skillsUsed'],
		];
	}//end turnEnvelope()

	/**
	 * Store the user's turn, then check whether the conversation needs a summary.
	 *
	 * Authorship is threaded through to this single writer, so a shared session
	 * records who spoke without any path double-storing the turn
	 * (talk-shared-sessions). The CnAiContext snapshot is stored with it.
	 *
	 * @param array<string, mixed> $turn The screened turn.
	 *
	 * @return ObjectEntity The stored user Message.
	 *
	 * @spec openspec/changes/archive/2026-09-06-agent-engine-port/tasks.md#1-port-the-chat-engine
	 */
	private function storeUserTurn(array $turn): ObjectEntity {
		$userTurn = $this->historyHandler->storeMessage(
			conversationId: $turn['conversationId'],
			role: 'user',
			content: $turn['userMessage'],
			sources: null,
			context: $turn['cnAiContext'],
			authorId: $turn['authorId'],
			authorDisplayName: $turn['authorDisplayName'],
			attachments: $turn['resolvedAttachments']
		);

		$this->conversationHandler->checkAndSummarize(conversation: $turn['conversation']);

		return $userTurn;
	}//end storeUserTurn()

	/**
	 * Store the screened answer with its sources and attachments, then queue
	 * the conversation's title off the reply's path.
	 *
	 * Naming is a second LLM round trip and nothing after the answer depends on
	 * the title. ConversationTitleWriter re-reads the conversation and re-checks
	 * whether it still wants a title.
	 *
	 * @param array<string, mixed> $turn The turn with its screened answer.
	 *
	 * @return ObjectEntity The stored assistant Message.
	 *
	 * @spec openspec/specs/agent-engine-port/spec.md
	 * @spec openspec/changes/chat-how-an-answer-came-about/specs/answer-transparency/spec.md#requirement-every-answer-records-how-it-came-about-req-atrn-001
	 */
	private function storeAnswer(array $turn): ObjectEntity {
		$assistantStored = $this->historyHandler->storeMessage(
			conversationId: $turn['conversationId'],
			role: 'assistant',
			content: $turn['aiResponse'],
			sources: $turn['sources'],
			attachments: $turn['answerAttachments'],
			provenance: $turn['provenance']
		);

		$this->queueTitleGeneration(
			conversationId: $turn['conversationId'],
			userMessage: $turn['userMessage'],
			userId: $turn['userId']
		);

		return $assistantStored;
	}//end storeAnswer()

	/**
	 * Resolve the turn's attachments as its speaker; none resolve to none.
	 *
	 * @param array  $attachments The entries the request sent.
	 * @param string $speaker     The uid of the person who sent the turn.
	 *
	 * @return array<int, array{fileId: int, name: string, mimeType: string, size: int, origin: string}>
	 *
	 * @throws AttachmentRefusedException When a file is not available to the speaker.
	 */
	private function resolveAttachments(array $attachments, string $speaker): array {
		if ($attachments === []) {
			return [];
		}

		// Fail closed: without the resolver no file can be read as its speaker.
		if ($this->attachmentResolver === null) {
			throw new AttachmentRefusedException(message: 'This file is not available to you', code: 400);
		}

		return $this->attachmentResolver->resolve(requested: $attachments, speaker: $speaker);
	}//end resolveAttachments()

	/**
	 * The exception to rethrow for a failed response: a redaction refusal on one of
	 * the turn's files becomes a refusal naming that file; anything else as it was.
	 *
	 * @param Exception $exception   The failure, possibly wrapping the refusal.
	 * @param array     $attachments The turn's resolved attachments.
	 *
	 * @return Exception
	 */
	private function attachmentRefusalFor(Exception $exception, array $attachments): Exception {
		if ($attachments === [] || $this->attachmentResolver === null) {
			return $exception;
		}

		for ($cause = $exception; $cause !== null; $cause = $cause->getPrevious()) {
			if ($cause instanceof RedactionRequiredException) {
				return ($this->attachmentResolver->redactionRefusal(refusal: $cause, attachments: $attachments) ?? $exception);
			}
		}

		return $exception;
	}//end attachmentRefusalFor()

	/**
	 * Generate a conversation title from the first message.
	 *
	 * Delegates to ConversationManagementHandler.
	 *
	 * @param string $firstMessage First user message.
	 *
	 * @return string Generated title.
	 *
	 * @spec openspec/changes/archive/2026-09-06-agent-engine-port/tasks.md#1-port-the-chat-engine
	 */
	public function generateConversationTitle(string $firstMessage): string {
		return $this->conversationHandler->generateConversationTitle(firstMessage: $firstMessage);
	}//end generateConversationTitle()

	/**
	 * Ensure a conversation title is unique for a user-agent combination.
	 *
	 * Delegates to ConversationManagementHandler.
	 *
	 * @param string $baseTitle Base title.
	 * @param string $userId User id.
	 * @param string $agentId Agent UUID.
	 *
	 * @return string Unique title.
	 *
	 * @spec openspec/changes/archive/2026-09-06-agent-engine-port/tasks.md#1-port-the-chat-engine
	 */
	public function ensureUniqueTitle(string $baseTitle, string $userId, string $agentId): string {
		return $this->conversationHandler->ensureUniqueTitle(
			baseTitle: $baseTitle,
			userId: $userId,
			agentId: $agentId
		);

	}//end ensureUniqueTitle()

	/**
	 * Queue the conversation-title generation instead of running it inline.
	 *
	 * Naming a conversation costs a second LLM round trip — on the `cli` transport a
	 * whole second `claude` process. It used to run synchronously right after the
	 * reply was stored, so the user waited ~20s of a ~65s wall for a string they had
	 * not asked for and were not yet reading.
	 *
	 * Only the enqueue happens here. Whether the conversation actually wants a title
	 * is decided by ConversationTitleWriter at write time, against a fresh read —
	 * which is strictly more correct than deciding it here: by the time the job runs
	 * the user may have titled the conversation themselves, and a snapshot taken now
	 * would happily overwrite them.
	 *
	 * A null `$jobList` (test callers constructed before this parameter existed)
	 * simply means no title is queued. That is a safe default precisely because the
	 * title is not on the reply's path.
	 *
	 * @param string $conversationId The conversation UUID.
	 * @param string $userMessage The user message to name the conversation from.
	 * @param string $userId The conversation owner, carried into the job so it can
	 *                       run as them. A job has no session, and naming needs an
	 *                       identity twice over: the credential broker will not
	 *                       resolve a provider credential for an anonymous
	 *                       principal, and OpenRegister RBAC refuses the write.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/agent-engine-port/spec.md
	 */
	private function queueTitleGeneration(string $conversationId, string $userMessage, string $userId): void {
		if ($this->jobList === null) {
			return;
		}

		$this->jobList->add(
			ConversationTitleJob::class,
			[
				'conversationId' => $conversationId,
				'userMessage' => $userMessage,
				'userId' => $userId,
			]
		);

	}//end queueTitleGeneration()

	/**
	 * The placeholder values for this turn (agents-instruction-variables): the
	 * acting person, the session's start field answers and the companion's app.
	 * A failure to read one of them never stops the turn; the prompt is then
	 * sent as written.
	 *
	 * @param string               $userId           The acting person.
	 * @param PromptVariableScope $scope The agent, organisation and context a turn's prompt variables are resolved in.
	 *
	 * @return array<string, string>
	 *
	 * @spec openspec/specs/agent-management-ui/spec.md#requirement-placeholders-in-an-agents-instructions-are-filled-in-per-turn-req-agvar-001
	 */
	private function promptVariablesFor(string $userId, PromptVariableScope $scope): array {
		$agent = $scope->agent;
		$organisation = $scope->organisation;
		$appContext = $scope->appContext;
		$conversationData = $scope->conversationData;
		if ($this->promptVariables === null || $agent === null) {
			return [];
		}

		try {
			return $this->promptVariables->variablesFor(
				userId: $userId,
				agentData: $agent->getObject(),
				organisation: $organisation,
				appContext: $appContext,
				startValues: (array)($conversationData['startValues'] ?? [])
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				message: '[Engine] Placeholder values could not be read; the instructions go as written',
				context: ['file' => __FILE__, 'line' => __LINE__, 'error' => $e->getMessage()]
			);
			return [];
		}
	}//end promptVariablesFor()
}//end class

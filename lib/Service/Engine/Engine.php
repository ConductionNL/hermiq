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
 * Ported from `OCA\OpenRegister\Service\ChatService`: thin facade that
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
 * @spec openspec/changes/agent-engine-port/tasks.md#1-port-the-chat-engine
 * @spec openspec/changes/agent-engine-port/tasks.md#1-port-the-chat-engine
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Engine;

use Exception;
use OCA\Hermiq\Service\GuardrailBlockedException;
use OCA\Hermiq\Service\GuardrailPolicyService;
use OCA\Hermiq\Service\Talk\ConversationParticipation;
use OCA\Hermiq\Cron\ConversationTitleJob;
use OCA\OpenRegister\Service\ObjectService;
use OCP\BackgroundJob\IJobList;
use Psr\Log\LoggerInterface;

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
 * @spec openspec/changes/agent-engine-port/tasks.md#1-port-the-chat-engine
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Facade orchestrates the handler set by design.
 * @SuppressWarnings(PHPMD.LongVariable)           `$guardrailPolicyService` is a promoted
 *   constructor collaborator named after its class (GuardrailPolicyService) —
 *   shortening it would obscure which service is injected.
 */
class Engine
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
     * Constructor.
     *
     * @param ObjectService                 $objectService          OpenRegister object read/write.
     * @param ContextRetrievalHandler       $contextHandler         RAG context handler.
     * @param ResponseGenerationHandler     $responseHandler        LLM response handler.
     * @param ConversationManagementHandler $conversationHandler    Title/summary handler.
     * @param MessageHistoryHandler         $historyHandler         Message storage/history handler.
     * @param ContextAssembler              $contextAssembler       Resolves an agent's attached
     *                                                              Context objects into a
     *                                                              system-prompt preamble
     *                                                              (agent-context-system).
     * @param LoggerInterface               $logger                 Logger.
     * @param GuardrailPolicyService|null   $guardrailPolicyService Resolves + applies the effective
     *                                                              GuardrailPolicy's input/output
     *                                                              filters (agent-guardrails).
     *                                                              Nullable/optional purely so
     *                                                              existing test callers that omit
     *                                                              it see zero behavior change
     *                                                              (equivalent to "no policy
     *                                                              configured" — fail-open,
     *                                                              design.md Decision 1); real DI
     *                                                              always provides it.
     * @param IJobList|null                 $jobList                Queues the conversation-title job
     *                                                              so naming never sits on the reply's
     *                                                              critical path
     *                                                              (session-context-performance).
     *                                                              Nullable/defaulted for the same
     *                                                              backward-compat reason; a null list
     *                                                              simply means no title is queued —
     *                                                              the reply is unaffected, which is
     *                                                              the point of deferring it.
     * @param ConversationParticipation     $participation          The owner-or-listed-participant
     *                                                              guard (talk-shared-sessions).
     *                                                              Defaulted so every existing
     *                                                              caller constructs unchanged; the
     *                                                              class is dependency-free.
     *
     * @return void
     *
     * @spec openspec/changes/agent-engine-port/tasks.md#1-port-the-chat-engine
     * @spec openspec/changes/agent-context-system/tasks.md#3-engine-wiring
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
        private readonly ?GuardrailPolicyService $guardrailPolicyService=null,
        private readonly ?IJobList $jobList=null,
        private readonly ConversationParticipation $participation=new ConversationParticipation()
    ) {
    }//end __construct()

    /**
     * Process a chat message and generate the AI response.
     *
     * Main orchestration method that coordinates all handlers. See the class
     * docblock for the binding signature/return-shape contract.
     *
     * @param string                  $conversationId    Conversation object UUID.
     * @param string                  $userId            User id (must own the conversation).
     * @param string                  $userMessage       User message text.
     * @param array                   $selectedViews     View filters for multitenancy (optional).
     * @param array                   $selectedTools     Tool registry ids to use (optional).
     * @param array                   $ragSettings       RAG configuration overrides (optional).
     * @param array                   $context           AI Chat Companion context snapshot the
     *                                                   frontend sent. Persisted on the
     *                                                   user-authored Message when non-empty.
     * @param StreamYieldChannel|null $channel           Streaming channel forwarded to the response
     *                                                   handler so SSE consumers can interleave
     *                                                   `token`/`tool_call`/`tool_result` frames as
     *                                                   the LLM yields. Null for blocking callers.
     * @param RunTraceCollector|null  $trace             Optional run-trace collector
     *                                                   (run-trace-observability);
     *                                                   when supplied,
     *                                                   context/history/llm/tool
     *                                                   steps are timed into it and
     *                                                   returned as the envelope's
     *                                                   `steps` key. Null for
     *                                                   callers that do not need a
     *                                                   step timeline (zero behavior
     *                                                   change).
     * @param bool                    $dryRun            Whether this turn is a dry-run preview
     *                                                   (run-replay-and-dry-run); threaded
     *                                                   onto
     *                                                   `ResponseGenerationHandler::generateResponse()`
     *                                                   so a side-effecting tool call is
     *                                                   neutralised instead of actually
     *                                                   invoked. False (every pre-existing
     *                                                   caller) is byte-for-byte unchanged
     *                                                   behavior.
     * @param array|null              $skillSetOverride  Per-run effective-skill-set override
     *                                                   (skill uuids) for the run-loop
     *                                                   skill-exposure seam (skill-evals): a
     *                                                   paired eval half varies exactly this
     *                                                   set. Null (every non-eval caller)
     *                                                   exposes the agent's stored
     *                                                   `skillInstalls`.
     * @param string|null             $authorId          Uid of the human who produced this turn,
     *                                                   persisted on the user-authored Message
     *                                                   so a shared session records who spoke
     *                                                   (talk-shared-sessions). Null for
     *                                                   single-speaker callers — byte-for-byte
     *                                                   unchanged behavior.
     * @param string|null             $authorDisplayName That human's display name AT SEND TIME,
     *                                                   captured deliberately and never re-resolved
     *                                                   so a transcript stays legible after a
     *                                                   rename or a deleted account (ADR-004).
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
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)   Chat processing involves multiple handler coordination steps
     * @SuppressWarnings(PHPMD.NPathComplexity)        Many optional paths for agent, title generation, and timing
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)  Full chat orchestration requires comprehensive step handling
     * @SuppressWarnings(PHPMD.ExcessiveParameterList) Each parameter is a distinct, independently
     *   optional input (run-trace-observability adds one more to an already-wide, long-established
     *   list) — grouping them would obscure, not simplify, the call site.
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag)    Dry-run preview (run-replay-and-dry-run)
     *   is a cross-cutting mode threaded through the engine as a flag by design.
     *
     * @spec openspec/changes/agent-engine-port/tasks.md#1-port-the-chat-engine
     * @spec openspec/changes/agent-engine-port/tasks.md#1-port-the-chat-engine
     * @spec openspec/changes/archive/2026-07-12-run-trace-observability/tasks.md#task-2-thread-the-collector-through-engine-toolloop-facadetoolinvoker
     * @spec openspec/changes/archive/2026-07-13-run-replay-and-dry-run/tasks.md#task-3-thread-dryrun-through-toolloop-engine-and-responsegenerationhandler
     * @spec openspec/specs/agent-evals/spec.md#requirement-the-engine-run-loop-exposes-the-effective-skill-set-to-a-run
     */
    public function processMessage(
        string $conversationId,
        string $userId,
        string $userMessage,
        array $selectedViews=[],
        array $selectedTools=[],
        array $ragSettings=[],
        array $context=[],
        ?StreamYieldChannel $channel=null,
        ?RunTraceCollector $trace=null,
        bool $dryRun=false,
        ?array $skillSetOverride=null,
        ?string $authorId=null,
        ?string $authorDisplayName=null
    ): array {
        $this->logger->info(
            message: '[Engine] Processing message',
            context: [
                'file'           => __FILE__,
                'line'           => __LINE__,
                'conversationId' => $conversationId,
                'userId'         => $userId,
                'messageLength'  => strlen($userMessage),
            ]
        );

        try {
            // Get conversation and verify access.
            $conversation = $this->objectService->find(
                id: $conversationId,
                register: self::REGISTER_SLUG,
                schema: self::CONVERSATION_SCHEMA
            );
            if ($conversation === null) {
                throw new Exception('Conversation not found: '.$conversationId);
            }

            $conversationData = $conversation->getObject();
            // Owner-or-listed-participant (talk-shared-sessions). This guard is
            // NOT redundant with ChatController's: the Talk bridge reaches the
            // engine from a background job without passing through the
            // controller, so this is the only check on that path.
            if ($this->participation->mayTakeTurn(conversationData: $conversationData, userId: $userId) === false) {
                throw new Exception('Access denied to conversation');
            }

            // Get agent if configured.
            $agent   = null;
            $agentId = $conversationData['agentId'] ?? null;
            if (is_string($agentId) === true && $agentId !== '') {
                $agent = $this->objectService->find(
                    id: $agentId,
                    register: self::REGISTER_SLUG,
                    schema: self::AGENT_SCHEMA
                );
            }

            // Capture the CnAiContext snapshot under its own name before
            // the retrieveContext() call below reuses `$context` for the
            // RAG context object. Without this rename the snapshot would
            // be silently overwritten and the LLM would never see it.
            $cnAiContext = $context;

            // Resolve the agent's attached Context objects (agent-context-system) into a
            // budgeted preamble BEFORE the RAG context call, so it lands ahead of the RAG
            // block in the system prompt (ResponseGenerationHandler prepends it right
            // after Agent.prompt). '' when the agent has none — no-op for most agents.
            $contextPreamble = $this->contextAssembler->assembleForAgent(agent: $agent, actingUserId: $userId);

            // Run-loop skill-exposure seam (skill-evals): inject the effective skill
            // set's content — a per-run override (a paired eval half), else the
            // agent's stored skillInstalls — into the same system-prompt preamble,
            // active skills only. '' + [] for the common no-skills agent (no-op).
            // Defensive shape reads mirror the usage/steps handling: a test double
            // (or future assembler swap) returning a partial bundle degrades to the
            // no-skills case instead of warning.
            $skillBundle = $this->contextAssembler->assembleSkillsForRun(agent: $agent, skillSetOverride: $skillSetOverride);
            $skillText   = (string) ($skillBundle['text'] ?? '');
            $skillsUsed  = ($skillBundle['skillsUsed'] ?? []);

            if ($skillText !== '') {
                $contextPreamble = ltrim($contextPreamble."\n\n".$skillText, "\n");
            }

            // Agent-guardrails: resolve the effective GuardrailPolicy ONCE for this
            // turn (organisation comes from the conversation, exactly like
            // ConversationTitleWriter's tenant-model-policy read) and apply the input
            // filter BEFORE the user Message is persisted and BEFORE the LLM is ever
            // called. A `block` match (PII/secret or prompt-injection) throws here —
            // no user/assistant Message is stored for this attempt (spec: "no LLM
            // call is made... no assistant Message is created"). A `redact` match
            // replaces $userMessage so both the persisted copy and the text sent to
            // the LLM are the masked text, never the original.
            $organisation    = (string) ($conversation->getOrganisation() ?? '');
            $guardrailPolicy = $this->resolveGuardrailPolicy(organisation: $organisation);

            $inputFilter = $this->guardrailPolicyService?->filterInput(
                policy: $guardrailPolicy,
                text: $userMessage
            ) ?? ['text' => $userMessage, 'blocked' => false, 'reason' => null];

            // Run-trace-observability + agent-guardrails: only a guardrail ACTION
            // (a block, or a redaction that actually changed the text) is recorded
            // as a step — a fully-open policy's no-op pass-through never inserts a
            // step, so an organisation with no GuardrailPolicy sees an IDENTICAL
            // step timeline to before this change (spec: "record every input
            // block, output block/redaction... as a trace step" — not every turn).
            if ($this->guardrailActed(filter: $inputFilter, originalText: $userMessage) === true) {
                $inputOutcome = 'redacted';
                if ($inputFilter['blocked'] === true) {
                    $inputOutcome = 'blocked';
                }

                $inputToken = $trace?->startStep(type: 'guardrail', name: 'Input filter');
                if ($inputToken !== null) {
                    $trace?->endStep(token: $inputToken, outcome: $inputOutcome);
                }
            }

            if ($inputFilter['blocked'] === true) {
                throw new GuardrailBlockedException(reason: (string) $inputFilter['reason']);
            }

            $userMessage = (string) $inputFilter['text'];

            // Store user message with the CnAiContext snapshot. Authorship is
            // threaded through to this single writer rather than persisted by
            // the caller, so a shared session records who spoke without any
            // path double-storing the turn (talk-shared-sessions).
            $this->historyHandler->storeMessage(
                conversationId: $conversationId,
                role: 'user',
                content: $userMessage,
                sources: null,
                context: $cnAiContext,
                authorId: $authorId,
                authorDisplayName: $authorDisplayName
            );

            // Check if conversation needs summarization.
            $this->conversationHandler->checkAndSummarize(conversation: $conversation);

            // Retrieve RAG context. Note: `$context` is now the RAG
            // context shape `{text, sources}`, distinct from `$cnAiContext`.
            // run-trace-observability: timed as a `context` step when a collector
            // is attached (never fatal — a null $trace is a no-op via `?->`).
            $contextToken     = $trace?->startStep(type: 'context', name: 'Context retrieval');
            $contextStartTime = microtime(true);
            $context          = $this->contextHandler->retrieveContext(
                query: $userMessage,
                agent: $agent,
                selectedViews: $selectedViews,
                ragSettings: $ragSettings
            );
            $contextTime      = microtime(true) - $contextStartTime;
            if ($contextToken !== null) {
                $trace?->endStep(token: $contextToken, outcome: 'ok');
            }

            // Build message history (timed as a `history` step — see above).
            $historyToken     = $trace?->startStep(type: 'history', name: 'History build');
            $historyStartTime = microtime(true);
            $messageHistory   = $this->historyHandler->buildMessageHistory(conversationId: $conversationId);
            $historyTime      = microtime(true) - $historyStartTime;
            if ($historyToken !== null) {
                $trace?->endStep(token: $historyToken, outcome: 'ok');
            }

            // Generate LLM response. Forward the CnAiContext snapshot so
            // the system prompt can include "the user is currently in
            // {app}" — without it the model would default to generic
            // platform-wide phrasing and pick the wrong tool family.
            //
            // run-trace-observability: the `llm` step wraps the WHOLE call,
            // including any nested tool-calling round trips the response handler
            // makes via ToolLoop/FacadeToolInvoker against the SAME $trace — those
            // `tool` steps complete (and are sequenced) BEFORE this `llm` step
            // completes, matching design.md's documented step ordering.
            $llmToken     = $trace?->startStep(type: 'llm', name: 'LLM generation');
            $llmStartTime = microtime(true);
            $aiResponse   = $this->responseHandler->generateResponse(
                userMessage: $userMessage,
                context: $context,
                messageHistory: $messageHistory,
                agent: $agent,
                selectedTools: $selectedTools,
                channel: $channel,
                cnAiContext: $cnAiContext,
                contextPreamble: $contextPreamble,
                trace: $trace,
                dryRun: $dryRun
            );
            $llmTime      = microtime(true) - $llmStartTime;
            if ($llmToken !== null) {
                $trace?->endStep(token: $llmToken, outcome: 'ok');
            }

            // Agent-guardrails: apply the output filter BEFORE the assistant Message
            // is persisted (and before it is handed back to the caller). A `block`
            // match never aborts the turn — $aiResponse is replaced with a withheld-
            // response placeholder so both the persisted copy and the returned
            // envelope's `message` field carry the placeholder, never the raw output.
            $outputFilter = $this->guardrailPolicyService?->filterOutput(
                policy: $guardrailPolicy,
                text: $aiResponse
            ) ?? ['text' => $aiResponse, 'blocked' => false, 'reason' => null];

            if ($this->guardrailActed(filter: $outputFilter, originalText: $aiResponse) === true) {
                $outputOutcome = 'redacted';
                if ($outputFilter['blocked'] === true) {
                    $outputOutcome = 'blocked';
                }

                $outputToken = $trace?->startStep(type: 'guardrail', name: 'Output filter');
                if ($outputToken !== null) {
                    $trace?->endStep(token: $outputToken, outcome: $outputOutcome);
                }
            }

            $aiResponse = (string) $outputFilter['text'];

            // Store AI response with sources. Capture the return so we can surface
            // the persisted assistant message's id to the caller (the SSE stream
            // controller needs it to populate the `final` event's messageId field;
            // the widget uses it as the Vue render key for the assistant bubble).
            $assistantStored = $this->historyHandler->storeMessage(
                conversationId: $conversationId,
                role: 'assistant',
                content: $aiResponse,
                sources: $context['sources']
            );

            // Name the conversation OFF this path. Naming is a second LLM round trip —
            // on the `cli` transport a second `claude` process, ~20s of a ~65s wall — and
            // nothing below depends on the title, so the user should not wait for it.
            // ConversationTitleWriter re-reads the conversation and re-checks whether it
            // still wants a title, so the decision stays correct even though it is made
            // later.
            $this->queueTitleGeneration(
                conversationId: $conversationId,
                userMessage: $userMessage,
                userId: $userId
            );

            $totalTime = ($contextTime + $historyTime + $llmTime);

            return [
                'message'    => $aiResponse,
                // Surface the persisted assistant message id for SSE consumers.
                'messageId'  => (string) ($assistantStored->getUuid() ?? ''),
                'sources'    => $context['sources'],
                'timings'    => [
                    'context' => round($contextTime, 2).'s',
                    'history' => round($historyTime, 3).'s',
                    'llm'     => round($llmTime, 2).'s',
                    'total'   => round($totalTime, 2).'s',
                ],
                // Per-run LLM token/latency usage for run-cost recording
                // (run-analytics / ScheduleService::lastRunUsage) — load-bearing,
                // see class docblock.
                'usage'      => $this->responseHandler->lastUsage,
                // Run-trace-observability: the collector's full ordered step
                // timeline, empty when no collector was supplied.
                'steps'      => $trace?->toArray() ?? [],
                // Skill-evals: the skill uuids actually exposed to this run's
                // context (active skills of the effective set) — captured by
                // ScheduleService as lastRunSkillsUsed and recorded on the run's
                // audit entry for ALL runs (skill-learnings consumes it later).
                'skillsUsed' => $skillsUsed,
            ];
        } catch (Exception $e) {
            $this->logger->error(
                message: '[Engine] Message processing failed',
                context: [
                    'file'  => __FILE__,
                    'line'  => __LINE__,
                    'error' => $e->getMessage(),
                ]
            );
            throw $e;
        }//end try
    }//end processMessage()

    /**
     * Generate a conversation title from the first message.
     *
     * Delegates to ConversationManagementHandler.
     *
     * @param string $firstMessage First user message.
     *
     * @return string Generated title.
     *
     * @spec openspec/changes/agent-engine-port/tasks.md#1-port-the-chat-engine
     */
    public function generateConversationTitle(string $firstMessage): string
    {
        return $this->conversationHandler->generateConversationTitle(firstMessage: $firstMessage);

    }//end generateConversationTitle()

    /**
     * Ensure a conversation title is unique for a user-agent combination.
     *
     * Delegates to ConversationManagementHandler.
     *
     * @param string $baseTitle Base title.
     * @param string $userId    User id.
     * @param string $agentId   Agent UUID.
     *
     * @return string Unique title.
     *
     * @spec openspec/changes/agent-engine-port/tasks.md#1-port-the-chat-engine
     */
    public function ensureUniqueTitle(string $baseTitle, string $userId, string $agentId): string
    {
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
     * @param string $userMessage    The user message to name the conversation from.
     * @param string $userId         The conversation owner, carried into the job so it can
     *                               run as them. A job has no session, and naming needs an
     *                               identity twice over: the credential broker will not
     *                               resolve a provider credential for an anonymous
     *                               principal, and OpenRegister RBAC refuses the write.
     *
     * @return void
     *
     * @spec openspec/specs/agent-engine-port/spec.md
     */
    private function queueTitleGeneration(string $conversationId, string $userMessage, string $userId): void
    {
        if ($this->jobList === null) {
            return;
        }

        $this->jobList->add(
            ConversationTitleJob::class,
            [
                'conversationId' => $conversationId,
                'userMessage'    => $userMessage,
                'userId'         => $userId,
            ]
        );

    }//end queueTitleGeneration()

    /**
     * Resolve the effective GuardrailPolicy for an organisation, or the
     * fully-open shape when no `GuardrailPolicyService` was injected (existing
     * test callers / a hypothetical install predating agent-guardrails) — zero
     * behavior change either way (design.md Decision 1, fail-open).
     *
     * @param string $organisation The conversation's organisation (may be '').
     *
     * @return array<string,mixed> The effective policy.
     *
     * @spec openspec/specs/agent-guardrails/spec.md#requirement-per-organisation-guardrail-policy-with-a-fully-open-fallback
     */
    private function resolveGuardrailPolicy(string $organisation): array
    {
        if ($this->guardrailPolicyService === null) {
            return [
                'inputFilters'  => ['piiAction' => 'off', 'promptInjectionAction' => 'off'],
                'outputFilters' => ['piiAction' => 'off'],
                'toolPolicy'    => [],
            ];
        }

        return $this->guardrailPolicyService->effectivePolicyFor(organisation: $organisation);

    }//end resolveGuardrailPolicy()

    /**
     * Whether a `filterInput()`/`filterOutput()` result represents an actual
     * guardrail ACTION (a block, or a redaction that changed the text) versus a
     * no-op pass-through. Only an action is worth a `run-history`-visible trace
     * step (spec: "record every input block, output block/redaction... as a
     * trace step") — a fully-open policy (the default, no `GuardrailPolicy`
     * configured) must leave the step timeline byte-for-byte identical to
     * before this change.
     *
     * @param array{text:string,blocked:bool,reason:?string} $filter       The filter result.
     * @param string                                         $originalText The pre-filter text.
     *
     * @return bool
     *
     * @spec openspec/specs/agent-guardrails/spec.md#requirement-every-guardrail-action-is-visible-in-run-history
     */
    private function guardrailActed(array $filter, string $originalText): bool
    {
        if ($filter['blocked'] === true) {
            return true;
        }

        return ((string) $filter['text']) !== $originalText;

    }//end guardrailActed()
}//end class

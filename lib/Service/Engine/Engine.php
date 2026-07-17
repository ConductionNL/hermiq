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
 * @spec openspec/changes/agent-engine-port/tasks.md#task-1-1
 * @spec openspec/changes/agent-engine-port/tasks.md#task-1-2
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Engine;

use Exception;
use OCA\Hermiq\Service\GuardrailBlockedException;
use OCA\Hermiq\Service\GuardrailPolicyService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
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
 * @spec openspec/changes/agent-engine-port/tasks.md#task-1-1
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Facade orchestrates the handler set by design.
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
     * Schema slug for message objects.
     *
     * @var string
     */
    private const MESSAGE_SCHEMA = 'message';

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
     *
     * @return void
     *
     * @spec openspec/changes/agent-engine-port/tasks.md#task-1-1
     * @spec openspec/changes/agent-context-system/tasks.md#task-3-1
     * @spec openspec/changes/agent-guardrails/tasks.md#task-3-wire-inputoutput-filters-into-engineprocessmessage
     */
    public function __construct(
        private readonly ObjectService $objectService,
        private readonly ContextRetrievalHandler $contextHandler,
        private readonly ResponseGenerationHandler $responseHandler,
        private readonly ConversationManagementHandler $conversationHandler,
        private readonly MessageHistoryHandler $historyHandler,
        private readonly ContextAssembler $contextAssembler,
        private readonly LoggerInterface $logger,
        private readonly ?GuardrailPolicyService $guardrailPolicyService=null
    ) {
    }//end __construct()

    /**
     * Process a chat message and generate the AI response.
     *
     * Main orchestration method that coordinates all handlers. See the class
     * docblock for the binding signature/return-shape contract.
     *
     * @param string                  $conversationId Conversation object UUID.
     * @param string                  $userId         User id (must own the conversation).
     * @param string                  $userMessage    User message text.
     * @param array                   $selectedViews  View filters for multitenancy (optional).
     * @param array                   $selectedTools  Tool registry ids to use (optional).
     * @param array                   $ragSettings    RAG configuration overrides (optional).
     * @param array                   $context        AI Chat Companion context snapshot the
     *                                                frontend sent. Persisted on the
     *                                                user-authored Message when non-empty.
     * @param StreamYieldChannel|null $channel        Streaming channel forwarded to the response
     *                                                handler so SSE consumers can interleave
     *                                                `token`/`tool_call`/`tool_result` frames as
     *                                                the LLM yields. Null for blocking callers.
     * @param RunTraceCollector|null  $trace          Optional run-trace collector
     *                                                (run-trace-observability); when supplied,
     *                                                context/history/llm/tool steps are timed
     *                                                into it and returned as the envelope's
     *                                                `steps` key. Null for callers that do not
     *                                                need a step timeline (zero behavior change).
     * @param bool                    $dryRun         Whether this turn is a dry-run preview
     *                                                (run-replay-and-dry-run); threaded onto
     *                                                `ResponseGenerationHandler::generateResponse()`
     *                                                so a side-effecting tool call is
     *                                                neutralised instead of actually invoked.
     *                                                False (every pre-existing caller) is
     *                                                byte-for-byte unchanged behavior.
     *
     * @return array The result envelope.
     *
     * @throws \Exception If processing fails (unknown conversation, access denied,
     *                    provider/LLM failure).
     *
     * @psalm-return array{message: string, messageId: string, sources: list<array>,
     *     timings: array{context: string, history: string, llm: string, total: string},
     *     usage: array<string, int|float>, steps: array<int, array<string, mixed>>}
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)   Chat processing involves multiple handler coordination steps
     * @SuppressWarnings(PHPMD.NPathComplexity)        Many optional paths for agent, title generation, and timing
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)  Full chat orchestration requires comprehensive step handling
     * @SuppressWarnings(PHPMD.ExcessiveParameterList) Each parameter is a distinct, independently
     *   optional input (run-trace-observability adds one more to an already-wide, long-established
     *   list) — grouping them would obscure, not simplify, the call site.
     *
     * @spec openspec/changes/agent-engine-port/tasks.md#task-1-1
     * @spec openspec/changes/agent-engine-port/tasks.md#task-1-2
     * @spec openspec/changes/run-trace-observability/tasks.md#task-2-1
     * @spec openspec/changes/run-replay-and-dry-run/tasks.md#task-3-thread-dryrun-through-toolloop-engine-and-responsegenerationhandler
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
        bool $dryRun=false
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
            if (($conversationData['userId'] ?? null) !== $userId) {
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

            // Agent-guardrails: resolve the effective GuardrailPolicy ONCE for this
            // turn (organisation comes from the conversation, exactly like
            // maybeGenerateTitle()'s tenant-model-policy read) and apply the input
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

            // Agent-guardrails (hermiq-guardrail-preamble-filter): the assembled context
            // preamble is model input too — ADR-024 Rule 3 — so it gets the SAME input
            // filter, in its OWN filterInput() call. Two calls, never one concatenated:
            // concatenating would destroy attribution ("did the USER try to jailbreak
            // this agent, or does an attached document merely contain the phrase?" demand
            // opposite responses from an operator) and would mangle the boundary between
            // them. Runs before storeMessage() so a refused turn persists nothing,
            // whichever boundary refused it.
            if ($contextPreamble !== '') {
                $preambleFilter = $this->guardrailPolicyService?->filterInput(
                    policy: $guardrailPolicy,
                    text: $contextPreamble
                ) ?? ['text' => $contextPreamble, 'blocked' => false, 'reason' => null];

                // Same "only an ACTION is recorded" contract as the user-message filter
                // above: a fully-open policy inserts no step, so an organisation without
                // a GuardrailPolicy keeps an IDENTICAL trace timeline.
                if ($this->guardrailActed(filter: $preambleFilter, originalText: $contextPreamble) === true) {
                    $preambleOutcome = 'redacted';
                    if ($preambleFilter['blocked'] === true) {
                        $preambleOutcome = 'blocked';
                    }

                    $preambleToken = $trace?->startStep(type: 'guardrail', name: 'Context preamble filter');
                    if ($preambleToken !== null) {
                        $trace?->endStep(token: $preambleToken, outcome: $preambleOutcome);
                    }
                }

                if ($preambleFilter['blocked'] === true) {
                    throw new GuardrailBlockedException(
                        reason: $this->contextReasonFor(reason: $preambleFilter['reason'])
                    );
                }

                // The model only ever sees the masked text — same contract as the user
                // message.
                $contextPreamble = (string) $preambleFilter['text'];
            }

            // Store user message with the CnAiContext snapshot.
            $this->historyHandler->storeMessage(
                conversationId: $conversationId,
                role: 'user',
                content: $userMessage,
                sources: null,
                context: $cnAiContext
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

            // Generate a title if this is the first exchange.
            $this->maybeGenerateTitle(
                conversation: $conversation,
                conversationId: $conversationId,
                userMessage: $userMessage
            );

            $totalTime = ($contextTime + $historyTime + $llmTime);

            return [
                'message'   => $aiResponse,
                // Surface the persisted assistant message id for SSE consumers.
                'messageId' => (string) ($assistantStored->getUuid() ?? ''),
                'sources'   => $context['sources'],
                'timings'   => [
                    'context' => round($contextTime, 2).'s',
                    'history' => round($historyTime, 3).'s',
                    'llm'     => round($llmTime, 2).'s',
                    'total'   => round($totalTime, 2).'s',
                ],
                // Per-run LLM token/latency usage for run-cost recording
                // (run-analytics / ScheduleService::lastRunUsage) — load-bearing,
                // see class docblock.
                'usage'     => $this->responseHandler->lastUsage,
                // Run-trace-observability: the collector's full ordered step
                // timeline, empty when no collector was supplied.
                'steps'     => $trace?->toArray() ?? [],
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
     * @spec openspec/changes/agent-engine-port/tasks.md#task-1-1
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
     * @spec openspec/changes/agent-engine-port/tasks.md#task-1-1
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
     * Generate and persist a conversation title when this is the first exchange
     * (message count <= 2 and the current title is unset or a "New Conversation"
     * placeholder).
     *
     * The persisted-count probe fetches at most 3 Message objects — enough to
     * decide `count <= 2` — instead of a full count query (the ported original
     * used `MessageMapper::countByConversation()`; ObjectService's `count()`
     * needs ambient register/schema context, and a limit-3 fetch is equivalent
     * for a boolean threshold this small).
     *
     * NOTE (save-side gotcha, load-bearing): the payload is routed through
     * `sanitizeForSave()` and the `$conversation` entity is NOT re-read after
     * `saveObject()` — the returned in-memory entity is stale by contract.
     *
     * @param ObjectEntity $conversation   The Conversation object (pre-save state).
     * @param string       $conversationId The conversation UUID.
     * @param string       $userMessage    The user message to derive a title from.
     *
     * @return void
     */
    private function maybeGenerateTitle(
        ObjectEntity $conversation,
        string $conversationId,
        string $userMessage
    ): void {
        $recentMessages = $this->objectService
            ->setRegister(self::REGISTER_SLUG)
            ->setSchema(self::MESSAGE_SCHEMA)
            ->findAll(
                config: [
                    'filters' => ['conversationId' => $conversationId],
                    'limit'   => 3,
                ]
            );
        $messageCount   = count($recentMessages);

        $conversationData    = $conversation->getObject();
        $currentTitle        = $conversationData['title'] ?? null;
        $isNewConversation   = ($currentTitle === null || strpos($currentTitle, 'New Conversation') === 0);
        $shouldGenerateTitle = ($messageCount <= 2 && $isNewConversation === true);

        if ($shouldGenerateTitle === false) {
            return;
        }

        // Tenant-model-policy: the conversation already carries its organisation
        // (ObjectEntity metadata) — no new lookup needed to enforce the effective
        // policy on this background title-generation call too.
        $organisation = (string) ($conversation->getOrganisation() ?? '');
        $title        = $this->conversationHandler->generateConversationTitle(firstMessage: $userMessage, organisation: $organisation);
        $agentId      = $conversationData['agentId'] ?? null;
        if (is_string($agentId) === true && $agentId !== '') {
            $title = $this->conversationHandler->ensureUniqueTitle(
                baseTitle: $title,
                userId: (string) ($conversationData['userId'] ?? ''),
                agentId: $agentId
            );
        }

        $conversationData['title'] = $title;

        $this->objectService->saveObject(
            object: $this->sanitizeForSave(data: $conversationData),
            register: self::REGISTER_SLUG,
            schema: self::CONVERSATION_SCHEMA,
            uuid: $conversationId
        );

    }//end maybeGenerateTitle()

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
     * @spec openspec/changes/agent-guardrails/specs/agent-guardrails/spec.md#requirement-per-organisation-guardrail-policy-with-a-fully-open-fallback
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
     * @spec openspec/changes/agent-guardrails/specs/agent-guardrails/spec.md#requirement-every-guardrail-action-is-visible-in-run-history
     */
    private function guardrailActed(array $filter, string $originalText): bool
    {
        if ($filter['blocked'] === true) {
            return true;
        }

        return ((string) $filter['text']) !== $originalText;

    }//end guardrailActed()

    /**
     * Suffix a guardrail reason code so it names the BOUNDARY that matched, not just
     * what matched. "The user tried to jailbreak this agent" and "an attached document
     * contains the phrase" demand opposite responses from an operator
     * (hermiq-guardrail-preamble-filter design.md Decision 3). Suffixing — rather than a
     * hand-written code per case — preserves BOTH facts the operator needs (WHAT matched,
     * and WHERE it came from) and stays correct if `GuardrailPolicyService` ever grows a
     * third reason code, where a `match` would silently fall through to a wrong or null
     * reason.
     *
     * @param string|null $reason The filter's reason code (`prompt_injection`|`sensitive_content`).
     *
     * @return string The `_in_context`-suffixed code, e.g. `prompt_injection_in_context`.
     *
     * @spec openspec/changes/hermiq-guardrail-preamble-filter/specs/agent-guardrails/spec.md#requirement-input-is-filtered-before-every-llm-turn
     */
    private function contextReasonFor(?string $reason): string
    {
        if ($reason === null || $reason === '') {
            return 'context_blocked';
        }

        return $reason.'_in_context';

    }//end contextReasonFor()
}//end class

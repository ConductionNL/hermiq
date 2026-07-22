<?php

/**
 * Hermiq case-assistant conversational surface.
 *
 * Orchestrates one turn of the minimal `POST /api/assistant/converse`
 * endpoint (case-assistant-surface): a deliberately narrower sibling of
 * `Engine::processMessage()` that skips OR-wide RAG search
 * (`ContextRetrievalHandler`) and the agent-context-system preamble
 * (`ContextAssembler`) — this surface's grounding is the caller-supplied
 * `context.contextData` — and, critically, guarantees zero tool execution
 * BY CONSTRUCTION rather than by configuration. See
 * openspec/changes/case-assistant-surface/design.md Decision 1 for why an
 * empty `Agent.tools`/`selectedTools` pair does NOT achieve that (it
 * resolves to "every discovered tool is allowed" in `ToolLoop`).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Assistant
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/case-assistant-surface/tasks.md#task-1-1
 * @spec openspec/changes/woo-llm-anonymisation/tasks.md#task-1-1
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Assistant;

use Exception;
use OCA\Hermiq\Service\Engine\MessageHistoryHandler;
use OCA\Hermiq\Service\Engine\ResponseGenerationHandler;
use OCA\Hermiq\Service\Engine\SanitizesForSaveTrait;
use OCA\Hermiq\Service\Engine\ToolGrantResolver;
use OCA\Hermiq\Service\GuardrailBlockedException;
use OCA\Hermiq\Service\GuardrailPolicyService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use Psr\Log\LoggerInterface;

/**
 * AssistantService orchestrates a single tool-free, context-grounded chat turn.
 *
 * @spec openspec/changes/case-assistant-surface/tasks.md#task-1-1
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Composes the same handler set
 * Engine::processMessage() does, minus the two RAG-specific handlers this
 * surface deliberately skips (design.md Decision 2).
 */
class AssistantService
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
     * Sentinel tool-whitelist entry meaning "this agent is intentionally
     * tool-less" — it matches no real tool id, so a whitelist containing only it
     * deterministically resolves to zero functions (design.md Decision 1).
     *
     * Aliased to `ToolGrantResolver::NO_TOOLS_SENTINEL` rather than re-spelled:
     * the resolver now recognises the value to tell a deliberate no-tools agent
     * apart from one whose grants resolve to zero by accident (which raises
     * `ToolGrantResolutionException`). Two independent copies of the string would
     * mean a rename here silently turns these agents into "broken grants".
     *
     * @var string
     */
    private const NO_TOOLS_SENTINEL = ToolGrantResolver::NO_TOOLS_SENTINEL;

    /**
     * Marker written to `Conversation.metadata.surface` for conversations
     * created by this endpoint (informational only — does not gate access).
     *
     * @var string
     */
    private const SURFACE_MARKER = 'assistant-converse';

    /**
     * Maximum accepted `message` length (characters).
     *
     * @var int
     */
    private const MAX_MESSAGE_LENGTH = 8000;

    /**
     * Maximum accepted JSON-encoded `context.contextData` length (characters).
     *
     * @var int
     */
    private const MAX_CONTEXT_DATA_LENGTH = 20000;

    /**
     * Maximum accepted `text` length for `detectPii()` (characters).
     *
     * @var int
     *
     * @spec openspec/changes/woo-llm-anonymisation/tasks.md#task-1-1
     */
    private const MAX_DETECT_TEXT_LENGTH = 12000;

    /**
     * Detector-agent display name template (`sprintf` with the calling app id).
     *
     * @var string
     */
    private const DETECTOR_AGENT_NAME_TEMPLATE = 'PII Span Detector (%s)';

    /**
     * Constructor.
     *
     * @param ObjectService               $objectService          OpenRegister object read/write.
     * @param MessageHistoryHandler       $historyHandler         Message storage/history (shared with Engine).
     * @param ResponseGenerationHandler   $responseHandler        LLM call orchestration (shared with Engine).
     * @param LoggerInterface             $logger                 PSR-3 logger.
     * @param GuardrailPolicyService|null $guardrailPolicyService Resolves + applies the effective
     *                                                            GuardrailPolicy's input/output
     *                                                            filters. Nullable purely so a
     *                                                            hypothetical install predating
     *                                                            agent-guardrails sees a fully-open
     *                                                            fallback (mirrors
     *                                                            `Engine::resolveGuardrailPolicy()`);
     *                                                            real DI always provides it.
     *
     * @return void
     *
     * @spec openspec/changes/case-assistant-surface/tasks.md#task-1-1
     */
    public function __construct(
        private readonly ObjectService $objectService,
        private readonly MessageHistoryHandler $historyHandler,
        private readonly ResponseGenerationHandler $responseHandler,
        private readonly LoggerInterface $logger,
        private readonly ?GuardrailPolicyService $guardrailPolicyService=null
    ) {
    }//end __construct()

    /**
     * Run one conversational turn.
     *
     * @param string      $userId    Authenticated caller (must own `$sessionId`, when given).
     * @param string|null $sessionId Existing conversation UUID, or null to start a new one.
     * @param string      $message   The user's message text.
     * @param array       $context   `{app, objectType?, objectRef?, contextData?}` — `app` is
     *                               required so a new conversation's dedicated Agent can be
     *                               provisioned/found.
     *
     * @return array{sessionId: string, reply: string, usage: array<string, int|float>}
     *
     * @throws Exception On validation failure (code 400), unknown session (404), foreign
     *                   session (403), or a downstream LLM/provider failure.
     * @throws GuardrailBlockedException When the input is refused by the effective GuardrailPolicy.
     *
     * @spec openspec/changes/case-assistant-surface/tasks.md#task-1-1
     */
    public function converse(string $userId, ?string $sessionId, string $message, array $context): array
    {
        $this->validateMessage(message: $message);
        $app = $this->validateContext(context: $context);

        $conversation   = $this->resolveConversation(sessionId: $sessionId, userId: $userId, app: $app);
        $conversationId = (string) $conversation->getUuid();
        $organisation   = (string) ($conversation->getOrganisation() ?? '');

        $guardrailPolicy = $this->resolveGuardrailPolicy(organisation: $organisation);
        $inputFilter     = $this->guardrailPolicyService?->filterInput(
            policy: $guardrailPolicy,
            text: $message
        ) ?? ['text' => $message, 'blocked' => false, 'reason' => null];

        if ($inputFilter['blocked'] === true) {
            $this->logger->warning(
                message: '[AssistantService] Input blocked by guardrail policy',
                context: ['file' => __FILE__, 'line' => __LINE__, 'conversationId' => $conversationId]
            );
            throw new GuardrailBlockedException(reason: (string) $inputFilter['reason']);
        }

        $message = (string) $inputFilter['text'];

        $this->historyHandler->storeMessage(
            conversationId: $conversationId,
            role: 'user',
            content: $message,
            sources: null,
            context: $context
        );

        $messageHistory = $this->historyHandler->buildMessageHistory(conversationId: $conversationId);

        $agent = $this->objectService->find(
            id: (string) $conversation->getObject()['agentId'],
            register: self::REGISTER_SLUG,
            schema: self::AGENT_SCHEMA
        );

        $ragContext = [
            'text'    => $this->renderContextData(context: $context),
            'sources' => [],
        ];

        $reply = $this->responseHandler->generateResponse(
            userMessage: $message,
            context: $ragContext,
            messageHistory: $messageHistory,
            agent: $agent,
            selectedTools: [],
            channel: null,
            cnAiContext: $this->cnAiContextFor(context: $context),
            contextPreamble: '',
            trace: null,
            dryRun: false
        );

        $outputFilter = $this->guardrailPolicyService?->filterOutput(
            policy: $guardrailPolicy,
            text: $reply
        ) ?? ['text' => $reply, 'blocked' => false, 'reason' => null];

        $reply = (string) $outputFilter['text'];

        $this->historyHandler->storeMessage(
            conversationId: $conversationId,
            role: 'assistant',
            content: $reply,
            sources: null
        );

        return [
            'sessionId' => $conversationId,
            'reply'     => $reply,
            'usage'     => $this->responseHandler->lastUsage,
        ];
    }//end converse()

    /**
     * Run one stateless, structured PII/redaction-span detection call.
     *
     * Deliberately does NOT persist a `Conversation`/`Message` (design.md
     * Decision 2) and deliberately bypasses the effective `GuardrailPolicy`'s
     * PII input-redaction action while keeping prompt-injection filtering
     * active (design.md Decision 1) — the caller's document text must reach
     * the model unredacted for detection to work at all.
     *
     * @param string $userId  Authenticated caller (only used for logging — no
     *                        per-user state is created by this call).
     * @param string $text    The document text to scan (already length-capped
     *                        by the caller's own bound, further capped here).
     * @param array  $context `{app, objectType?, objectRef?}` — `app` is
     *                        required so a dedicated detector Agent can be
     *                        provisioned/found.
     *
     * @return array{spans: array<int, array<string, mixed>>, usage: array<string, int|float>}
     *
     * @throws Exception On validation failure (code 400) or malformed model
     *                   output (code 502).
     * @throws GuardrailBlockedException When the input is refused by the
     *                                   effective GuardrailPolicy's
     *                                   prompt-injection filter.
     *
     * @spec openspec/changes/woo-llm-anonymisation/tasks.md#task-1-2
     */
    public function detectPii(string $userId, string $text, array $context): array
    {
        $this->validateDetectText(text: $text);
        $app = $this->validateContext(context: $context);

        $guardrailPolicy = $this->resolveGuardrailPolicy(organisation: '');
        $guardrailPolicy['inputFilters']['piiAction'] = 'off';

        $inputFilter = $this->guardrailPolicyService?->filterInput(
            policy: $guardrailPolicy,
            text: $text
        ) ?? ['text' => $text, 'blocked' => false, 'reason' => null];

        if ($inputFilter['blocked'] === true) {
            $this->logger->warning(
                message: '[AssistantService] detectPii input blocked by guardrail policy',
                context: ['file' => __FILE__, 'line' => __LINE__, 'app' => $app, 'userId' => $userId]
            );
            throw new GuardrailBlockedException(reason: (string) $inputFilter['reason']);
        }

        $agent = $this->findOrCreateDetectorAgent(app: $app);

        $reply = $this->responseHandler->generateResponse(
            userMessage: $text,
            context: ['text' => '', 'sources' => []],
            messageHistory: [],
            agent: $agent,
            selectedTools: [],
            channel: null,
            cnAiContext: $this->cnAiContextFor(context: $context),
            contextPreamble: '',
            trace: null,
            dryRun: false
        );

        $spans = $this->parseDetectPiiReply(reply: $reply);

        return [
            'spans' => $spans,
            'usage' => $this->responseHandler->lastUsage,
        ];
    }//end detectPii()

    /**
     * Validate the `text` field for `detectPii()`.
     *
     * @param string $text The document text.
     *
     * @return void
     *
     * @throws Exception (code 400) When empty or over the length cap.
     */
    private function validateDetectText(string $text): void
    {
        if (trim($text) === '') {
            throw new Exception('text is required', 400);
        }

        if (strlen($text) > self::MAX_DETECT_TEXT_LENGTH) {
            throw new Exception(
                'text exceeds the maximum length of '.self::MAX_DETECT_TEXT_LENGTH.' characters',
                400
            );
        }
    }//end validateDetectText()

    /**
     * Find, or idempotently create, the dedicated tool-locked PII-detector
     * Agent for `$app` — the same `tools: ['__none__']` sentinel pattern as
     * `findOrCreateAgent()`, distinct name and prompt (design.md Decision 3).
     *
     * @param string $app Calling app id.
     *
     * @return ObjectEntity The Agent object.
     *
     * @spec openspec/changes/woo-llm-anonymisation/design.md#decision-3
     */
    private function findOrCreateDetectorAgent(string $app): ObjectEntity
    {
        $name = sprintf(self::DETECTOR_AGENT_NAME_TEMPLATE, $app);

        $existing = $this->objectService
            ->setRegister(self::REGISTER_SLUG)
            ->setSchema(self::AGENT_SCHEMA)
            ->findAll(config: ['filters' => ['name' => $name], 'limit' => 1]);

        foreach ($existing as $candidate) {
            if ($candidate instanceof ObjectEntity) {
                return $candidate;
            }
        }

        $this->logger->info(
            message: '[AssistantService] Provisioning dedicated PII-span-detector agent',
            context: ['file' => __FILE__, 'line' => __LINE__, 'app' => $app]
        );

        return $this->objectService->saveObject(
            object: $this->sanitizeForSave(
                data: [
                    'name'        => $name,
                    'description' => 'Auto-provisioned tool-free structured PII/redaction-span detector for '
                        .'the '.$app.' woo-llm-anonymisation surface. Do not add tools — this Agent is '
                        .'deliberately locked to zero tool execution.',
                    'prompt'      => $this->detectPiiSystemPrompt(),
                    'tools'       => [self::NO_TOOLS_SENTINEL],
                    'isPrivate'   => true,
                    'active'      => true,
                ]
            ),
            register: self::REGISTER_SLUG,
            schema: self::AGENT_SCHEMA
        );
    }//end findOrCreateDetectorAgent()

    /**
     * System prompt for the PII-span-detector agent — instructs strict
     * JSON-only output so `parseDetectPiiReply()` can parse a structured
     * span list instead of prose.
     *
     * @return string
     */
    private function detectPiiSystemPrompt(): string
    {
        return 'You are a precise PII and redaction-span detector for Dutch government Woo '
            .'(Wet open overheid) document disclosure review. You are given a block of document text. '
            .'Identify every span that should be considered for redaction before public disclosure: '
            .'persons\' names, BSN (burgerservicenummer), postal addresses, phone numbers, email '
            .'addresses, signatures, and medical or financial mentions about an identifiable natural '
            .'person. Respond with STRICT JSON ONLY, no prose, no markdown code fence, in exactly this '
            .'shape: {"spans":[{"start":<int>,"end":<int>,"category":'
            .'"<person|bsn|address|contact|signature|medical|financial>","confidence":"<low|medium|high>"}]}. '
            .'`start`/`end` are character offsets into the EXACT text you were given (0-indexed, `end` '
            .'exclusive). Do NOT repeat the matched substring anywhere in your response — offsets and a '
            .'category label are sufficient. If nothing should be redacted, respond with {"spans":[]}.';
    }//end detectPiiSystemPrompt()

    /**
     * Parse a `detectPii()` model reply into a validated spans array.
     *
     * Strips a leading/trailing markdown code fence (models routinely wrap
     * JSON in ```json even when instructed not to), then requires the exact
     * `{"spans": [...]}` shape — anything else fails loud rather than
     * returning a partial/guessed result (design.md Decision 4).
     *
     * @param string $reply The raw model reply text.
     *
     * @return array<int, array<string, mixed>> The validated spans.
     *
     * @throws Exception (code 502) When the reply is not the expected JSON shape.
     */
    private function parseDetectPiiReply(string $reply): array
    {
        $cleaned = trim($reply);
        $cleaned = preg_replace('/^```(?:json)?\s*/i', '', $cleaned) ?? $cleaned;
        $cleaned = preg_replace('/\s*```$/', '', $cleaned) ?? $cleaned;

        $decoded = json_decode(trim($cleaned), true);
        if (is_array($decoded) === false || array_key_exists('spans', $decoded) === false
            || is_array($decoded['spans']) === false
        ) {
            throw new Exception('AI response was not valid PII-span JSON', 502);
        }

        $spans = [];
        foreach ($decoded['spans'] as $span) {
            if (is_array($span) === false
                || is_int($span['start'] ?? null) === false
                || is_int($span['end'] ?? null) === false
                || is_string($span['category'] ?? null) === false
            ) {
                continue;
            }

            $spans[] = [
                'start'      => $span['start'],
                'end'        => $span['end'],
                'category'   => $span['category'],
                'confidence' => (string) ($span['confidence'] ?? 'medium'),
            ];
        }

        return $spans;
    }//end parseDetectPiiReply()

    /**
     * Validate the `message` field.
     *
     * @param string $message The message text.
     *
     * @return void
     *
     * @throws Exception (code 400) When empty or over the length cap.
     */
    private function validateMessage(string $message): void
    {
        if (trim($message) === '') {
            throw new Exception('message is required', 400);
        }

        if (strlen($message) > self::MAX_MESSAGE_LENGTH) {
            throw new Exception(
                'message exceeds the maximum length of '.self::MAX_MESSAGE_LENGTH.' characters',
                400
            );
        }
    }//end validateMessage()

    /**
     * Validate the `context` field and return its required `app`.
     *
     * @param array $context The context payload.
     *
     * @return string The validated `app`.
     *
     * @throws Exception (code 400) When `app` is missing or `contextData` is over the length cap.
     */
    private function validateContext(array $context): string
    {
        $app = (string) ($context['app'] ?? '');
        if ($app === '') {
            throw new Exception('context.app is required', 400);
        }

        $contextData = ($context['contextData'] ?? null);
        if ($contextData !== null) {
            $encoded = json_encode($contextData, JSON_UNESCAPED_SLASHES);
            if ($encoded !== false && strlen($encoded) > self::MAX_CONTEXT_DATA_LENGTH) {
                throw new Exception(
                    'context.contextData exceeds the maximum length of '.self::MAX_CONTEXT_DATA_LENGTH.' characters',
                    400
                );
            }
        }

        return $app;
    }//end validateContext()

    /**
     * Resolve (load or create) the Conversation this turn runs against.
     *
     * @param string|null $sessionId Existing conversation UUID, or null to create one.
     * @param string      $userId    Current user id.
     * @param string      $app       Calling app id (used to find/provision the dedicated Agent).
     *
     * @return ObjectEntity The resolved conversation.
     *
     * @throws Exception 404 when `$sessionId` does not resolve; 403 when it belongs to another user.
     */
    private function resolveConversation(?string $sessionId, string $userId, string $app): ObjectEntity
    {
        if ($sessionId !== null && $sessionId !== '') {
            $conversation = $this->objectService->find(
                id: $sessionId,
                register: self::REGISTER_SLUG,
                schema: self::CONVERSATION_SCHEMA
            );
            if ($conversation === null) {
                throw new Exception('The session with id '.$sessionId.' does not exist', 404);
            }

            if (($conversation->getObject()['userId'] ?? null) !== $userId) {
                throw new Exception('You do not have access to this session', 403);
            }

            return $conversation;
        }

        $agent = $this->findOrCreateAgent(app: $app);

        return $this->objectService->saveObject(
            object: $this->sanitizeForSave(
                data: [
                    'userId'   => $userId,
                    'agentId'  => (string) $agent->getUuid(),
                    'title'    => 'Case Assistant — '.$app,
                    'metadata' => ['surface' => self::SURFACE_MARKER],
                ]
            ),
            register: self::REGISTER_SLUG,
            schema: self::CONVERSATION_SCHEMA
        );
    }//end resolveConversation()

    /**
     * Find, or idempotently create, the dedicated tool-locked Agent for `$app`.
     *
     * @param string $app Calling app id.
     *
     * @return ObjectEntity The Agent object.
     *
     * @spec openspec/changes/case-assistant-surface/design.md#decision-1
     */
    private function findOrCreateAgent(string $app): ObjectEntity
    {
        $name = 'Case Assistant ('.$app.')';

        $existing = $this->objectService
            ->setRegister(self::REGISTER_SLUG)
            ->setSchema(self::AGENT_SCHEMA)
            ->findAll(config: ['filters' => ['name' => $name], 'limit' => 1]);

        foreach ($existing as $candidate) {
            if ($candidate instanceof ObjectEntity) {
                return $candidate;
            }
        }

        $this->logger->info(
            message: '[AssistantService] Provisioning dedicated case-assistant agent',
            context: ['file' => __FILE__, 'line' => __LINE__, 'app' => $app]
        );

        return $this->objectService->saveObject(
            object: $this->sanitizeForSave(
                data: [
                    'name'        => $name,
                    'description' => 'Auto-provisioned tool-free conversational agent for the '.$app.
                        ' case-assistant surface. Do not add tools — this Agent is deliberately locked '.
                        'to zero tool execution (case-assistant-surface design.md Decision 1).',
                    'prompt'      => 'You are a helpful case assistant. Answer only using the CASE CONTEXT '.
                        'provided below and the conversation so far. If the context does not contain the '.
                        'answer, say so honestly instead of guessing. You cannot take any action — you can '.
                        'only discuss and explain.',
                    'tools'       => [self::NO_TOOLS_SENTINEL],
                    'isPrivate'   => true,
                    'active'      => true,
                ]
            ),
            register: self::REGISTER_SLUG,
            schema: self::AGENT_SCHEMA
        );
    }//end findOrCreateAgent()

    /**
     * Render the caller-supplied `context` into the RAG-shaped grounding text
     * `ResponseGenerationHandler::generateResponse()` already knows how to
     * inject ("Use the following context to answer the user's question").
     *
     * @param array $context `{app, objectType?, objectRef?, contextData?}`.
     *
     * @return string The rendered grounding text, or '' when there is nothing to ground on.
     */
    private function renderContextData(array $context): string
    {
        $contextData = ($context['contextData'] ?? null);
        if ($contextData === null || $contextData === [] || $contextData === '') {
            return '';
        }

        if (is_string($contextData) === true) {
            return $contextData;
        }

        $encoded = json_encode($contextData, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($encoded === false) {
            return '';
        }

        return $encoded;
    }//end renderContextData()

    /**
     * Build the small `cnAiContext` snapshot forwarded to
     * `ResponseGenerationHandler`'s "CURRENT APP CONTEXT" system-prompt block.
     *
     * @param array $context `{app, objectType?, objectRef?, contextData?}`.
     *
     * @return array<string, mixed> The snapshot (contextData omitted — it is
     *                              rendered separately via `renderContextData()`).
     */
    private function cnAiContextFor(array $context): array
    {
        $snapshot = ['app' => ($context['app'] ?? '')];

        if (empty($context['objectType']) === false) {
            $snapshot['objectType'] = $context['objectType'];
        }

        if (empty($context['objectRef']) === false) {
            $snapshot['objectRef'] = $context['objectRef'];
        }

        return $snapshot;
    }//end cnAiContextFor()

    /**
     * Resolve the effective GuardrailPolicy for an organisation, or the
     * fully-open shape when no `GuardrailPolicyService` was injected — mirrors
     * `Engine::resolveGuardrailPolicy()` exactly (same fail-open-on-missing-
     * service fallback, agent-guardrails design.md Decision 1).
     *
     * @param string $organisation The conversation's organisation (may be '').
     *
     * @return array<string,mixed> The effective policy.
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
}//end class

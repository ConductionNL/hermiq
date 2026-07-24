<?php

/**
 * Hermiq Chat Response Generation Handler.
 *
 * Handler for generating LLM responses using the configured provider. Ported from
 * `OCA\OpenRegister\Service\Chat\ResponseGenerationHandler`; the inline
 * provider-selection switch (openregister HEAD lines ~235-460) was extracted into
 * `OCA\Hermiq\Service\Llm\ProviderFactory` — this class keeps the orchestration:
 * system-prompt assembly (agent prompt + CnAiContext + RAG context), tool wiring,
 * the streaming-vs-blocking invocation ladder, and per-run usage capture.
 *
 * Streaming-mode outcome (ported contract): dual-mode. When the caller passes a
 * `StreamYieldChannel` AND the active provider's chat instance exposes
 * `generateStreamOfText`, we call `generateChatStream($messages)` and iterate its
 * PSR-7 stream, forwarding each chunk to `$channel->emitToken()`. When no channel
 * is supplied (blocking callers, background workers) we keep the blocking
 * `generateChat()` call exactly as before.
 *
 * The `nextcloud` TaskProcessing driver is deliberately NOT accepted here: its
 * scope (plan §8 move 1) is background/non-interactive work only — conversation
 * titles and summaries, served by `ConversationManagementHandler` — never chat
 * generation. Selecting it as `chatProvider` and then chatting raises a clear
 * `ProviderUnavailableException` instead of a silent wrong-path call.
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
 * @spec openspec/changes/agent-engine-port/tasks.md#task-2-1
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Engine;

use Exception;
use OCA\Hermiq\Service\Llm\ProviderFactory;
use OCA\Hermiq\Service\Llm\ProviderUnavailableException;
use OCA\OpenRegister\Db\ObjectEntity;
use Psr\Log\LoggerInterface;
use LLPhant\Chat\OllamaChat;
use LLPhant\Chat\OpenAIChat;
use LLPhant\Chat\Message as LLPhantMessage;
use LLPhant\Exception\MissingFeatureException;

/**
 * Orchestrates one LLM response generation: prompt assembly, tool wiring,
 * streaming/blocking invocation, usage capture.
 *
 * @spec openspec/changes/agent-engine-port/tasks.md#task-1-1
 */
class ResponseGenerationHandler
{

    /**
     * Token/latency usage from the last generateResponse() call, for per-run cost
     * recording (run-analytics). Populated from the LLPhant chat instance; empty
     * when the provider does not expose usage. Keys: promptTokens,
     * completionTokens, totalDurationMs, llmSeconds. PUBLIC and read by the
     * Engine facade after each call — the `usage` key in
     * `Engine::processMessage()`'s return shape depends on it (design.md risk:
     * ScheduleService's `lastRunUsage` / run-analytics).
     *
     * @var array<string, int|float>
     */
    public array $lastUsage = [];

    /**
     * Constructor.
     *
     * @param ProviderFactory $providerFactory LLM provider resolution (`hermiq.llm`).
     * @param ToolLoop        $toolLoop        Tool resolution via the OR facade.
     * @param LoggerInterface $logger          Logger.
     *
     * @return void
     *
     * @spec openspec/changes/agent-engine-port/tasks.md#task-1-1
     */
    public function __construct(
        private readonly ProviderFactory $providerFactory,
        private readonly ToolLoop $toolLoop,
        private readonly LoggerInterface $logger
    ) {
    }//end __construct()

    /**
     * Generate a response using the configured LLM provider.
     *
     * @param string                  $userMessage     User's message text.
     * @param array                   $context         RAG context with 'text' and 'sources' keys.
     * @param array                   $messageHistory  Array of LLPhant Message objects.
     * @param ObjectEntity|null       $agent           Agent object (optional).
     * @param array                   $selectedTools   Tools selected for this request (optional).
     * @param StreamYieldChannel|null $channel         Streaming channel; when supplied the handler
     *                                                 attempts the LLPhant streaming surface and
     *                                                 forwards token / tool-call / tool-result
     *                                                 events to the channel. When null the handler
     *                                                 runs in blocking mode.
     * @param array                   $cnAiContext     Optional AI Chat Companion context snapshot.
     * @param string                  $contextPreamble Optional assembled Context preamble
     *                                                 (agent-context-system, from
     *                                                 `ContextAssembler::assembleForAgent()`);
     *                                                 prepended to the system prompt right after
     *                                                 `Agent.prompt`, ahead of CnAiContext/RAG.
     * @param RunTraceCollector|null  $trace           Optional run-trace collector; threaded
     *                                                 onto `ToolLoop::buildFunctionInfos()` so
     *                                                 each tool call is timed as a `tool` step
     *                                                 (run-trace-observability).
     * @param bool                    $dryRun          Whether this turn is a dry-run preview
     *                                                 (run-replay-and-dry-run); threaded onto
     *                                                 `ToolLoop::buildFunctionInfos()` so a
     *                                                 side-effecting tool call is neutralised
     *                                                 instead of actually invoked. False (every
     *                                                 pre-existing caller) is byte-for-byte
     *                                                 unchanged behavior.
     *
     * @return string Generated response text.
     *
     * @throws \Exception If the LLM provider is not configured or the API call fails.
     *
     * @psalm-param array{text: string, sources: list<array>} $context
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)   Response generation requires many conditional API calls
     * @SuppressWarnings(PHPMD.NPathComplexity)        Response generation requires many conditional API calls
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)  Ported orchestration kept structurally intact
     * @SuppressWarnings(PHPMD.StaticAccess)           LLPhant's Message role factories are the
     * library's public API — there is no injectable seam.
     * @SuppressWarnings(PHPMD.ElseExpression)         Ported fireworks-vs-LLPhant provider fork kept
     * structurally intact from the OR original for parity reviewability.
     * @SuppressWarnings(PHPMD.ExcessiveParameterList) Each parameter is a distinct, independently
     * optional input to prompt assembly (agent-context-system adds one more to an already-wide,
     * long-established list) — grouping them would obscure, not simplify, the call site.
     *
     * @spec openspec/changes/agent-engine-port/tasks.md#task-1-1
     * @spec openspec/changes/agent-engine-port/tasks.md#task-2-1
     * @spec openspec/changes/agent-context-system/tasks.md#task-3-2
     * @spec openspec/changes/run-trace-observability/tasks.md#task-2-1
     * @spec openspec/changes/run-replay-and-dry-run/tasks.md#task-3-thread-dryrun-through-toolloop-engine-and-responsegenerationhandler
     */
    public function generateResponse(
        string $userMessage,
        array $context,
        array $messageHistory,
        ?ObjectEntity $agent,
        array $selectedTools=[],
        ?StreamYieldChannel $channel=null,
        array $cnAiContext=[],
        string $contextPreamble='',
        ?RunTraceCollector $trace=null,
        bool $dryRun=false
    ): string {
        $startTime = microtime(true);
        $agentData = [];
        if ($agent !== null) {
            $agentData = $agent->getObject();
        }

        $this->logger->info(
            message: '[ResponseGenerationHandler] Generating response',
            context: [
                'file'          => __FILE__,
                'line'          => __LINE__,
                'messageLength' => strlen($userMessage),
                'contextLength' => strlen($context['text']),
                'historyCount'  => count($messageHistory),
                'selectedTools' => count($selectedTools),
            ]
        );

        // Get enabled tool functions for the agent, filtered by selectedTools.
        $toolsStartTime = microtime(true);
        $functions      = $this->toolLoop->listAgentFunctions(agent: $agent, selectedTools: $selectedTools);
        $toolsTime      = microtime(true) - $toolsStartTime;
        if (empty($functions) === false) {
            $this->logger->info(
                message: '[ResponseGenerationHandler] Agent has tools enabled',
                context: [
                    'file'      => __FILE__,
                    'line'      => __LINE__,
                    'toolCount' => count($functions),
                    'tools'     => array_map(static fn (array $function): string => (string) ($function['name'] ?? ''), $functions),
                ]
            );
        }

        try {
            // Resolve the provider driver (agent model/temperature overrides applied).
            $llmConfig = $this->providerFactory->getLlmConfig();

            $agentModel = $agentData['model'] ?? null;
            if (is_string($agentModel) === false) {
                $agentModel = null;
            }

            $agentTemperature = null;
            if (isset($agentData['temperature']) === true && is_numeric($agentData['temperature']) === true) {
                $agentTemperature = (float) $agentData['temperature'];
            }

            // The agent's output ceiling. Stored, versioned and shown in the UI
            // since agents existed, but never read here — so every request used
            // the provider default regardless of what an admin had set. A
            // non-positive value is treated as unset rather than as "no tokens".
            $agentMaxTokens = null;
            if (isset($agentData['maxTokens']) === true && is_numeric($agentData['maxTokens']) === true
                && (int) $agentData['maxTokens'] > 0
            ) {
                $agentMaxTokens = (int) $agentData['maxTokens'];
            }

            // Tenant-model-policy: the agent's organisation (already in hand — no new
            // lookup) is threaded to ProviderFactory so the resolved (provider, model)
            // pair is checked against its effective ModelPolicy before any provider
            // client is used. null (no agent bound to this turn) skips the check.
            $organisation = null;
            if ($agent !== null) {
                $organisation = (string) ($agent->getOrganisation() ?? '');
            }

            $driver = $this->providerFactory->createChatDriver(
                llmConfig: $llmConfig,
                agentModel: $agentModel,
                agentTemperature: $agentTemperature,
                organisation: $organisation,
                agentMaxTokens: $agentMaxTokens
            );

            if ($driver->provider === 'nextcloud') {
                // Scope guard — see the class docblock.
                throw new ProviderUnavailableException(
                    message: 'The nextcloud (TaskProcessing) chat provider serves background work only '
                    .'(titles, summaries) — select openai, ollama, or fireworks for chat.',
                    code: 503
                );
            }

            $this->logger->info(
                message: '[ResponseGenerationHandler] Using chat provider',
                context: [
                    'file'     => __FILE__,
                    'line'     => __LINE__,
                    'provider' => $driver->provider,
                    'model'    => $driver->model,
                    'hasTools' => empty($functions) === false,
                ]
            );

            // Build system prompt.
            $defaultPrompt = 'You are a helpful AI assistant that helps users find and understand their data.';
            $systemPrompt  = $agentData['prompt'] ?? $defaultPrompt;

            // Prepend the assembled Context preamble (agent-context-system) right after
            // Agent.prompt — same category ("who you are / what you know statically") as
            // the prompt itself, ahead of the per-turn CnAiContext/RAG blocks below.
            if ($contextPreamble !== '') {
                $systemPrompt .= "\n\n".$contextPreamble;
            }

            // Inject the CnAiContext snapshot the widget sends with each
            // message. Without this the LLM has no idea which app the user
            // is in — so it would call the wrong app's tools (or default to
            // platform-wide phrasing). The snapshot is small and free-form
            // (typically {app, slug, view, objectId}); rendered as a bullet
            // list so the model can quote individual fields.
            if (empty($cnAiContext) === false) {
                $systemPrompt .= "\n\nCURRENT APP CONTEXT (this is where the user is RIGHT NOW — prefer tools that match this app):\n";
                foreach ($cnAiContext as $key => $value) {
                    if (is_scalar($value) === true) {
                        $systemPrompt .= "- {$key}: ".(string) $value."\n";
                        continue;
                    }

                    $systemPrompt .= "- {$key}: ".json_encode($value, JSON_UNESCAPED_SLASHES)."\n";
                }
            }

            if (empty($context['text']) === false) {
                $systemPrompt .= "\n\nUse the following context to answer the user's question:\n\n";
                $systemPrompt .= "CONTEXT:\n".$context['text']."\n\n";
                $systemPrompt .= "If the context doesn't contain relevant information, say so honestly. ";
                $systemPrompt .= 'Always cite which sources you used when answering.';
            }

            // Add system message to history, then the current user message.
            array_unshift($messageHistory, LLPhantMessage::system($systemPrompt));
            $messageHistory[] = LLPhantMessage::user($userMessage);

            $llmStartTime = microtime(true);

            if ($driver->provider === 'fireworks') {
                // Fireworks uses direct HTTP (ported callFireworksChatAPIWithHistory —
                // now ProviderFactory::callFireworksChat). Function calling is not
                // supported there; functions are logged + ignored inside the call.
                $response = $this->providerFactory->callFireworksChat(
                    credentialId: (string) $driver->credentialId,
                    model: $driver->model,
                    baseUrl: (string) $driver->baseUrl,
                    messageHistory: $messageHistory,
                    functions: $functions
                );
                $llmTime  = microtime(true) - $llmStartTime;

                // Fireworks exposes no usage today (matches the ported original).
                $this->lastUsage = ['llmSeconds' => round($llmTime, 2)];
            } else if ($driver->provider === 'anthropic') {
                // Anthropic uses direct HTTP through the broker (ProviderFactory::
                // callAnthropicChat) with the auth headers selected by authMode.
                //
                // Tool use: build the SAME governed FunctionInfo objects the LLPhant
                // branch below uses (ToolLoop::buildFunctionInfos — one shared
                // FacadeToolInvoker, agent/guardrails/approval/redaction/trace/dry-run
                // all applied), then hand callAnthropicChat a `$toolExecutor` that runs a
                // requested tool by (name, input) through that same governed path via
                // FunctionInfo::callWithArguments(). The tool name Claude receives
                // (ProviderFactory::buildAnthropicTools) and the FunctionInfo name both
                // derive from `$functions['name']`, so they match. Executor is null when
                // there are no tools → callAnthropicChat runs text-only (the fail-safe:
                // never advertise a tool Hermiq cannot run).
                $anthropicToolExecutor = null;
                if (empty($functions) === false) {
                    $anthropicFunctionInfos = $this->toolLoop->buildFunctionInfos(
                        functions: $functions,
                        channel: $channel,
                        trace: $trace,
                        agent: $agent,
                        organisation: $organisation,
                        dryRun: $dryRun
                    );

                    $anthropicFnByName = [];
                    foreach ($anthropicFunctionInfos as $anthropicFunctionInfo) {
                        $anthropicFnByName[$anthropicFunctionInfo->name] = $anthropicFunctionInfo;
                    }

                    $anthropicToolExecutor = static function (string $toolName, array $toolInput) use ($anthropicFnByName): string {
                        $functionInfo = ($anthropicFnByName[$toolName] ?? null);
                        if ($functionInfo === null) {
                            return (string) json_encode(['error' => "Unknown tool: {$toolName}"]);
                        }

                        return (string) $functionInfo->callWithArguments($toolInput);
                    };
                }//end if

                // Bind the per-run token (cli-runner-governed-mcp-and-egress) so a
                // tool-requiring `cli` turn is governed via Hermiq's MCP endpoint rather
                // than refused. Null on the `http` path / agent-less chat.
                $cliAgentId = null;
                if ($agent !== null) {
                    $cliAgentId = (string) $agent->getUuid();
                }

                $response = $this->providerFactory->callAnthropicChat(
                    credentialId: (string) $driver->credentialId,
                    model: $driver->model,
                    baseUrl: (string) $driver->baseUrl,
                    messageHistory: $messageHistory,
                    authMode: (string) $driver->authMode,
                    functions: $functions,
                    toolExecutor: $anthropicToolExecutor,
                    executionMode: $driver->executionMode,
                    agentId: $cliAgentId,
                    maxTokens: $driver->maxTokens
                );
                $llmTime  = microtime(true) - $llmStartTime;

                // Anthropic usage (input/output tokens) is available on the response but not
                // yet threaded here; record latency only, matching the Fireworks path.
                $this->lastUsage = ['llmSeconds' => round($llmTime, 2)];
            } else {
                // OpenAI / Ollama: LLPhant chat instance from the driver.
                $chat = $driver->chat;
                if ($chat === null) {
                    throw new ProviderUnavailableException(message: "Provider {$driver->provider} returned no chat instance");
                }

                if (empty($functions) === false) {
                    // Agent-guardrails: thread $agent (agent-tool-governance-and-disclosure's
                    // approval-gate check needs it and, until now, never received it — see
                    // the class docblock's note below) and $organisation (already resolved
                    // above for tenant-model-policy) so ToolLoop can resolve the effective
                    // GuardrailPolicy's tool classification exactly once for this turn.
                    $functionInfoObjects = $this->toolLoop->buildFunctionInfos(
                        functions: $functions,
                        channel: $channel,
                        trace: $trace,
                        agent: $agent,
                        organisation: $organisation,
                        dryRun: $dryRun
                    );
                    $chat->setTools($functionInfoObjects);
                }

                $response = $this->invokeChat(
                    chat: $chat,
                    messageHistory: $messageHistory,
                    channel: $channel,
                    provider: $driver->provider,
                    hasTools: (empty($functions) === false)
                );
                $llmTime  = microtime(true) - $llmStartTime;

                // Expose the LLM token/latency usage for per-run cost recording
                // (run-analytics). Only OllamaChat accumulates usage today (via the
                // llphant-ollama-usage-capture vendor patch); other providers leave
                // it empty.
                $this->lastUsage = [];
                if ($chat instanceof OllamaChat) {
                    $this->lastUsage = $chat->lastUsage;
                }

                $this->lastUsage['llmSeconds'] = round($llmTime, 2);
            }//end if

            $totalTime = microtime(true) - $startTime;

            $this->logger->info(
                message: '[ResponseGenerationHandler] Response generated - PERFORMANCE',
                context: [
                    'file'           => __FILE__,
                    'line'           => __LINE__,
                    'provider'       => $driver->provider,
                    'model'          => $driver->model,
                    'responseLength' => strlen($response),
                    'timings'        => [
                        'total'         => round($totalTime, 2).'s',
                        'toolsLoading'  => round($toolsTime, 3).'s',
                        'llmGeneration' => round($llmTime, 2).'s',
                        'overhead'      => round($totalTime - $llmTime - $toolsTime, 3).'s',
                    ],
                ]
            );

            return $response;
        } catch (Exception $e) {
            $this->logger->error(
                message: '[ResponseGenerationHandler] Failed to generate response',
                context: [
                    'file'  => __FILE__,
                    'line'  => __LINE__,
                    'error' => $e->getMessage(),
                ]
            );
            throw new Exception('Failed to generate response: '.$e->getMessage(), (int) $e->getCode(), $e);
        }//end try
    }//end generateResponse()

    /**
     * Invoke the configured chat client, preferring streaming where possible.
     *
     * Ollama-with-tools degrades to blocking: `OllamaChat::generateChatStream`
     * does NOT process tool_calls — only the blocking `generateChat` path
     * handles the tool-call branch (and our FacadeToolInvoker still fires
     * tool_call/tool_result frames from there, so SSE consumers see tool
     * progress even without token streaming). OpenAI's streamed response
     * handles tool_calls during the stream, so it stays on the streaming path.
     * On `MissingFeatureException` we fall through to the blocking call so
     * providers that advertise streaming but fail at runtime degrade gracefully.
     *
     * @param OpenAIChat|OllamaChat   $chat           Configured chat client.
     * @param array                   $messageHistory LLPhant message history.
     * @param StreamYieldChannel|null $channel        Optional streaming channel.
     * @param string                  $provider       Provider slug (for logging).
     * @param bool                    $hasTools       Whether tools were registered on the chat
     *                                                (tracked at setTools() time — the ported
     *                                                original recovered this via reflection on
     *                                                LLPhant's protected `tools` property; we
     *                                                set the tools ourselves, so no reflection).
     *
     * @return string The assistant's textual response.
     *
     * @spec openspec/changes/agent-engine-port/tasks.md#task-1-1
     */
    private function invokeChat(
        OpenAIChat|OllamaChat $chat,
        array $messageHistory,
        ?StreamYieldChannel $channel,
        string $provider,
        bool $hasTools
    ): string {
        $ollamaWithTools = (($chat instanceof OllamaChat) === true && $hasTools === true);

        if ($channel !== null
            && $ollamaWithTools === false
            && method_exists($chat, 'generateStreamOfText') === true
        ) {
            try {
                return $this->streamChat(chat: $chat, messageHistory: $messageHistory, channel: $channel);
            } catch (MissingFeatureException $e) {
                // Provider advertises streaming but cannot deliver — log + degrade.
                $this->logger->info(
                    message: '[ResponseGenerationHandler] Streaming unavailable, falling back to blocking call',
                    context: [
                        'file'     => __FILE__,
                        'line'     => __LINE__,
                        'provider' => $provider,
                        'error'    => $e->getMessage(),
                    ]
                );
            }
        }

        // Non-streaming fallback — load-bearing for blocking callers, for
        // providers without streaming support, and for Ollama-with-tools where
        // the FacadeToolInvoker still fires from the blocking callFunction path.
        return $chat->generateChat($messageHistory);

    }//end invokeChat()

    /**
     * Drive the LLPhant streaming surface, forwarding each chunk to the
     * channel's `emitToken` callback and assembling the full text for the
     * final SSE frame.
     *
     * @param OpenAIChat|OllamaChat $chat           Configured chat instance.
     * @param array                 $messageHistory Array of LLPhant Message objects.
     * @param StreamYieldChannel    $channel        Channel to forward chunks to.
     *
     * @return string Assembled assistant text.
     *
     * @throws MissingFeatureException When the provider's streaming surface throws.
     *
     * @spec openspec/changes/agent-engine-port/tasks.md#task-1-1
     */
    private function streamChat(
        OpenAIChat|OllamaChat $chat,
        array $messageHistory,
        StreamYieldChannel $channel
    ): string {
        $stream    = $chat->generateChatStream($messageHistory);
        $assembled = '';

        // PSR-7 StreamInterface: read until EOF; LLPhant emits one chunk
        // per delta when `delta.content` is non-empty.
        while ($stream->eof() === false) {
            $chunk = $stream->read(1024);
            if ($chunk === '') {
                continue;
            }

            $assembled .= $chunk;
            $channel->emitToken(delta: $chunk);
        }

        return $assembled;

    }//end streamChat()
}//end class

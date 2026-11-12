<?php

/**
 * Hermiq Chat Response Generation Handler.
 *
 * Handler for generating LLM responses using the configured provider. Ported from
 * `OCA\OpenRegister\Mcp\Service\Chat\ResponseGenerationHandler`; the inline
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

use Closure;
use Exception;
use LLPhant\Chat\Message as LLPhantMessage;
use LLPhant\Chat\OllamaChat;
use LLPhant\Chat\OpenAIChat;
use OCA\Hermiq\Service\Agent\AgentAvailability;
use OCA\Hermiq\Service\AiFeature\FeatureProviderResolver;
use OCA\Hermiq\Service\Llm\AttachmentPartBuilder;
use OCA\Hermiq\Service\Llm\ChatDriver;
use OCA\Hermiq\Service\Llm\ProviderFactory;
use OCA\Hermiq\Service\Llm\ProviderUnavailableException;
use OCA\OpenRegister\Db\ObjectEntity;
use Psr\Log\LoggerInterface;

/**
 * Orchestrates one LLM response generation: prompt assembly, tool wiring,
 * streaming/blocking invocation, usage capture.
 *
 * ⚠️ COUPLING IS SUPPRESSED HERE, AND IT IS THE ONLY SUPPRESSION IN THIS CLASS.
 * phpmd counts 13 coupled objects against a threshold of 12. That count is not
 * a bloated constructor: it takes FOUR collaborators. It is the types this
 * class necessarily names to do its one job — two LLPhant chat clients, two
 * exception types it must distinguish, the provider factory and its failure
 * type, the feature resolver, the tool loop, an ObjectEntity and a logger.
 *
 * Bringing the number down means hiding those types behind an abstraction
 * whose only purpose is to reduce a count, on the path that answers every
 * agent turn. That is a refactor worth doing deliberately, with time to test
 * provider selection and tool wiring properly, and not one worth doing to make
 * a number smaller.
 *
 * If you are reading this because you are doing that refactor: delete the
 * annotation, do not widen the threshold.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 *
 * @spec openspec/changes/agent-engine-port/tasks.md#task-1-1
 */
class ResponseGenerationHandler {

	/**
	 * Token/latency usage from the last generateResponse() call, for per-run cost
	 * recording (run-analytics) and token budgets. Populated from the LLPhant chat
	 * instance (Ollama, OpenAI) or from ProviderFactory::lastCallUsage() (Fireworks,
	 * Anthropic); empty when the provider does not expose usage. Keys: promptTokens,
	 * completionTokens, totalDurationMs, llmSeconds. PUBLIC and read by the
	 * Engine facade after each call — the `usage` key in
	 * `Engine::processMessage()`'s return shape depends on it (design.md risk:
	 * ScheduleService's `lastRunUsage` / run-analytics).
	 *
	 * @var array<string, int|float>
	 */
	public array $lastUsage = [];

	/**
	 * The notices about attachments the last turn could not send natively, in the
	 * person's language (chat-attachments-and-images D6). Read by the Engine onto
	 * its result, and from there onto the stream's `final` frame.
	 *
	 * @var list<string>
	 */
	public array $attachmentNotices = [];

	/**
	 * Constructor.
	 *
	 * @param ProviderFactory $providerFactory LLM provider resolution (`hermiq.llm`).
	 * @param ToolLoop $toolLoop Tool resolution via the OR facade.
	 * @param LoggerInterface $logger Logger.
	 * @param FeatureProviderResolver|null $featureResolver Records which feature, provider,
	 *                                                      model and residency a run used
	 *                                                      (a-provider-and-a-place-per-ai-feature).
	 *                                                      Nullable and trailing: a handler
	 *                                                      built by hand in a test records
	 *                                                      nothing and behaves as before.
	 * @param AttachmentPartBuilder|null $attachmentParts Turns a turn's attachments into
	 *                                                    native image and document parts for
	 *                                                    a model declared to read them
	 *                                                    (chat-attachments-and-images). Null
	 *                                                    sends the text turn as before.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agent-engine-port/tasks.md#task-1-1
	 */
	public function __construct(
		private readonly ProviderFactory $providerFactory,
		private readonly ToolLoop $toolLoop,
		private readonly LoggerInterface $logger,
		private readonly ?FeatureProviderResolver $featureResolver = null,
		private readonly ?AttachmentPartBuilder $attachmentParts = null,
	) {
	}//end __construct()

	/**
	 * Generate a response using the configured LLM provider.
	 *
	 * @param string $userMessage User's message text.
	 * @param array $context RAG context with 'text' and 'sources' keys.
	 * @param array $messageHistory Array of LLPhant Message objects: the turns BEFORE
	 *                              this one. This method appends the current turn
	 *                              (`$userMessage` plus its attachments) exactly once,
	 *                              so a caller never includes it (user-turn-sent-once).
	 * @param ObjectEntity|null $agent Agent object (optional).
	 * @param array $selectedTools Tools selected for this request (optional).
	 * @param StreamYieldChannel|null $channel Streaming channel; when supplied the handler
	 *                                         attempts the LLPhant streaming surface and
	 *                                         forwards token / tool-call / tool-result
	 *                                         events to the channel. When null the handler
	 *                                         runs in blocking mode.
	 * @param array $cnAiContext Optional AI Chat Companion context snapshot.
	 * @param string $contextPreamble Optional assembled Context preamble
	 *                                (agent-context-system, from
	 *                                `ContextAssembler::assembleForAgent()`);
	 *                                prepended to the system prompt right after
	 *                                `Agent.prompt`, ahead of CnAiContext/RAG.
	 * @param RunTraceCollector|null $trace Optional run-trace collector; threaded
	 *                                      onto `ToolLoop::buildFunctionInfos()` so
	 *                                      each tool call is timed as a `tool` step
	 *                                      (run-trace-observability).
	 * @param bool $dryRun Whether this turn is a dry-run preview
	 *                     (run-replay-and-dry-run); threaded onto
	 *                     `ToolLoop::buildFunctionInfos()` so a
	 *                     side-effecting tool call is neutralised
	 *                     instead of actually invoked. False (every
	 *                     pre-existing caller) is byte-for-byte
	 *                     unchanged behavior.
	 * @param string $conversationId The conversation this turn belongs to, so a step
	 *                               published on the run-step bus reaches the right
	 *                               stream and the performance log can be joined to
	 *                               the runner's own timings.
	 * @param array<string, string> $promptVariables Placeholder values for this turn
	 *                                               (agents-instruction-variables,
	 *                                               `PromptVariableResolver::variablesFor()`);
	 *                                               empty leaves Agent.prompt as written.
	 * @param array<int, array<string, mixed>> $attachments The turn's attachments, already
	 *                                                      resolved as the speaker
	 *                                                      (TurnAttachmentResolver).
	 * @param string $speaker The uid the attachments were resolved for; their bytes are
	 *                        read as this person when the model reads them natively.
	 *
	 * @return string Generated response text.
	 *
	 * @throws \Exception If the LLM provider is not configured or the API call fails.
	 *
	 * @psalm-param array{text: string, sources: list<array>} $context
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) Each parameter is a distinct, independently
	 * optional input to prompt assembly (agent-context-system adds one more to an already-wide,
	 * long-established list) — grouping them would obscure, not simplify, the call site.
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)    Dry-run preview (run-replay-and-dry-run)
	 * is a cross-cutting mode threaded through the engine as a flag by design.
	 *
	 * @spec openspec/changes/agent-engine-port/tasks.md#task-1-1
	 * @spec openspec/changes/agent-engine-port/tasks.md#task-2-1
	 * @spec openspec/changes/agent-context-system/tasks.md#task-3-2
	 * @spec openspec/specs/run-audit-log/spec.md#requirement-every-run-and-tool-call-is-audited-mvp
	 * @spec openspec/changes/run-replay-and-dry-run/tasks.md#task-3-thread-dryrun-through-toolloop-engine-and-responsegenerationhandler
	 * @spec openspec/specs/agent-management-ui/spec.md#requirement-placeholders-in-an-agents-instructions-are-filled-in-per-turn-req-agvar-001
	 * @spec openspec/changes/chat-attachments-and-images/specs/chat-attachments/spec.md#requirement-an-attachment-is-read-as-the-person-who-sent-it-req-catt-003
	 * @spec openspec/changes/chat-attachments-and-images/specs/chat-attachments/spec.md#requirement-a-model-that-reads-images-or-pdfs-natively-gets-them-natively-req-catt-004
	 * @spec openspec/specs/agent-engine-port/spec.md#requirement-the-model-receives-the-users-turn-once
	 */
	public function generateResponse(
		string $userMessage,
		array $context,
		array $messageHistory,
		?ObjectEntity $agent,
		array $selectedTools = [],
		?StreamYieldChannel $channel = null,
		array $cnAiContext = [],
		string $contextPreamble = '',
		?RunTraceCollector $trace = null,
		bool $dryRun = false,
		string $conversationId = '',
		array $promptVariables = [],
		array $attachments = [],
		string $speaker = '',
	): string {
		$run = $this->startTurn(run: new ResponseTurnRun(userMessage: $userMessage, context: $context), agent: $agent);
		$run->selectedTools = $selectedTools;
		$run->messageHistory = $messageHistory;
		$run->channel = $channel;
		$run->cnAiContext = $cnAiContext;
		$run->contextPreamble = $contextPreamble;
		$run->trace = $trace;
		$run->dryRun = $dryRun;
		$run->conversationId = $conversationId;
		$run->promptVariables = $promptVariables;
		$run->attachments = $attachments;
		$run->speaker = $speaker;

		return $this->runTurn(run: $run);
	}//end generateResponse()

	/**
	 * Start a turn: its clock, a clean set of attachment notices, and the agent.
	 *
	 * @param ResponseTurnRun   $run   The new turn.
	 * @param ObjectEntity|null $agent Agent object (optional).
	 *
	 * @return ResponseTurnRun The same turn.
	 */
	private function startTurn(ResponseTurnRun $run, ?ObjectEntity $agent): ResponseTurnRun {
		$run->progress->startTime = microtime(true);
		$this->attachmentNotices = [];
		$run->agent = $agent;
		return $run;
	}//end startTurn()

	/**
	 * Run the turn: load the agent's tools, resolve the driver, build the
	 * messages, call the model and log the timings.
	 *
	 * @param ResponseTurnRun $run The turn.
	 *
	 * @return string Generated response text.
	 *
	 * @throws Exception If the LLM provider is not configured or the API call fails.
	 */
	private function runTurn(ResponseTurnRun $run): string {
		$this->logTurnStart(run: $run);
		$this->loadFunctions(run: $run);

		try {
			$this->resolveDriver(run: $run);
			$this->buildMessages(run: $run);
			$response = $this->callProvider(run: $run);
			(new TurnPerformanceLog(logger: $this->logger))->record(run: $run, response: $response);
			return $response;
		} catch (TurnStoppedException $e) {
			// Agents-switch-off-and-stop: the model asked for a tool after the turn was
			// stopped (agent switched off, or no tool calls left). The turn ends here.
			return $e->getMessage() . '.';
		} catch (Exception $e) {
			throw $this->turnFailure(error: $e);
		}
	}//end runTurn()

	/**
	 * Log the start of a turn with the size of its inputs.
	 *
	 * @param ResponseTurnRun $run The turn.
	 *
	 * @return void
	 */
	private function logTurnStart(ResponseTurnRun $run): void {
		$this->logger->info(
			message: '[ResponseGenerationHandler] Generating response',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'messageLength' => strlen($run->userMessage),
				'contextLength' => strlen($run->context['text']),
				'historyCount' => count($run->messageHistory),
				'selectedTools' => count($run->selectedTools),
			]
		);
	}//end logTurnStart()

	/**
	 * Get the agent's enabled tool functions, filtered by the selected tools.
	 *
	 * @param ResponseTurnRun $run The turn.
	 *
	 * @return void
	 */
	private function loadFunctions(ResponseTurnRun $run): void {
		$toolsStartTime = microtime(true);
		$run->progress->functions = $this->toolLoop->listAgentFunctions(agent: $run->agent, selectedTools: $run->selectedTools);
		$run->progress->toolsTime = microtime(true) - $toolsStartTime;
		if (empty($run->progress->functions) === true) {
			return;
		}

		$this->logger->info(
			message: '[ResponseGenerationHandler] Agent has tools enabled',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'toolCount' => count($run->progress->functions),
				'tools' => array_map(static fn (array $function): string => (string)($function['name'] ?? ''), $run->progress->functions),
			]
		);
	}//end loadFunctions()

	/**
	 * Resolve the provider driver (agent model/temperature overrides applied),
	 * record which model saw the turn, and refuse a driver that cannot chat.
	 *
	 * @param ResponseTurnRun $run The turn.
	 *
	 * @return void
	 *
	 * @throws ProviderUnavailableException When the driver serves background work only.
	 */
	private function resolveDriver(ResponseTurnRun $run): void {
		$llmConfig = $this->providerFactory->getLlmConfig();
		$inputs = new ChatDriverInputs(run: $run);
		$run->progress->organisation = $inputs->organisation();
		$run->progress->aiFeature = $inputs->aiFeature();
		$run->progress->driver = $this->createDriver(llmConfig: $llmConfig, inputs: $inputs);

		$this->recordProviderDisclosure(run: $run);
		$this->assertChatProvider(provider: $run->progress->driver->provider);
		$this->logProvider(run: $run);
	}//end resolveDriver()

	/**
	 * Ask the provider factory for the turn's chat driver.
	 *
	 * A refused credential pin, a policy refusal or a refused attachment stops
	 * the turn here, before any model is called.
	 *
	 * @param array<string, mixed> $llmConfig The LLM settings.
	 * @param ChatDriverInputs     $inputs    The turn's driver inputs.
	 *
	 * @return ChatDriver
	 */
	private function createDriver(array $llmConfig, ChatDriverInputs $inputs): ChatDriver {
		return $this->providerFactory->createChatDriver(
			llmConfig: $llmConfig,
			agentModel: $inputs->model(),
			agentTemperature: $inputs->temperature(),
			organisation: $inputs->organisation(),
			agentMaxTokens: $inputs->maxTokens(),
			aiFeature: $inputs->aiFeature(),
			documentReference: $inputs->documentReference(),
			agentCredentialIds: $inputs->credentialIds(),
			attachmentReferences: $inputs->attachmentReferences()
		);
	}//end createDriver()

	/**
	 * Which model saw this case, where, and what it declared about training.
	 * Copied onto the run rather than referenced, so relabelling the provider
	 * later cannot rewrite it. Recorded for every run, with `feature` empty when
	 * the run names none (models-no-training-guarantee).
	 *
	 * @param ResponseTurnRun $run The turn.
	 *
	 * @return void
	 */
	private function recordProviderDisclosure(ResponseTurnRun $run): void {
		if ($run->trace === null || $this->featureResolver === null) {
			return;
		}

		$run->trace->recordProviderDisclosure(
			disclosure: $this->featureResolver->disclosureFor(
				featureSlug: (string)$run->progress->aiFeature,
				provider: $run->progress->driver->provider,
				model: $run->progress->driver->model
			)
		);
	}//end recordProviderDisclosure()

	/**
	 * Scope guard — see the class docblock: the nextcloud driver never chats.
	 *
	 * @param string $provider The resolved provider slug.
	 *
	 * @return void
	 *
	 * @throws ProviderUnavailableException For the nextcloud driver.
	 */
	private function assertChatProvider(string $provider): void {
		if ($provider !== 'nextcloud') {
			return;
		}

		throw new ProviderUnavailableException(
			message: 'The nextcloud (TaskProcessing) chat provider serves background work only '
			. '(titles, summaries) — select openai, ollama, or fireworks for chat.',
			code: 503
		);
	}//end assertChatProvider()

	/**
	 * Log the provider and model the turn runs on.
	 *
	 * @param ResponseTurnRun $run The turn.
	 *
	 * @return void
	 */
	private function logProvider(ResponseTurnRun $run): void {
		$this->logger->info(
			message: '[ResponseGenerationHandler] Using chat provider',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'provider' => $run->progress->driver->provider,
				'model' => $run->progress->driver->model,
				'hasTools' => empty($run->progress->functions) === false,
			]
		);
	}//end logProvider()

	/**
	 * Put the system prompt in front of the earlier turns and the current user
	 * turn at the end. This is the ONE place the current turn joins the history;
	 * callers pass earlier turns only.
	 *
	 * Chat-attachments-and-images: the attachments a declared model reads
	 * natively ride on the user turn; the rest go as text or are left out, and
	 * the notices say which (kept for the answer's `final` frame).
	 *
	 * @param ResponseTurnRun $run The turn.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) LLPhant's Message role factories are the
	 * library's public API — there is no injectable seam.
	 */
	private function buildMessages(ResponseTurnRun $run): void {
		array_unshift($run->messageHistory, LLPhantMessage::system((new TurnSystemPrompt())->compose(run: $run)));
		$turn = ($this->attachmentParts?->compose(
			text: $run->userMessage,
			driver: $run->progress->driver,
			attachments: $run->attachments,
			speaker: $run->speaker
		) ?? ['turn' => LLPhantMessage::user($run->userMessage), 'notices' => []]);
		$this->attachmentNotices = $turn['notices'];
		$run->messageHistory[] = $turn['turn'];
	}//end buildMessages()

	/**
	 * Call the model on the turn's provider path.
	 *
	 * @param ResponseTurnRun $run The turn.
	 *
	 * @return string The response text.
	 */
	private function callProvider(ResponseTurnRun $run): string {
		$run->progress->llmStartTime = microtime(true);
		if ($run->progress->driver->provider === 'fireworks') {
			return $this->callFireworks(run: $run);
		}

		if ($run->progress->driver->provider === 'anthropic') {
			return $this->callAnthropic(run: $run);
		}

		return $this->callLlphant(run: $run);
	}//end callProvider()

	/**
	 * Fireworks uses direct HTTP (ProviderFactory::callFireworksChat). Function
	 * calling is not supported there; functions are logged + ignored inside the call.
	 *
	 * @param ResponseTurnRun $run The turn.
	 *
	 * @return string The response text.
	 */
	private function callFireworks(ResponseTurnRun $run): string {
		$response = $this->providerFactory->callFireworksChat(
			credentialId: (string)$run->progress->driver->credentialId,
			model: $run->progress->driver->model,
			baseUrl: (string)$run->progress->driver->baseUrl,
			messageHistory: $run->messageHistory,
			functions: $run->progress->functions
		);
		$this->markLlmDone(run: $run);

		// The tokens Fireworks reported, which a token budget counts (hermiq#985).
		$this->recordFactoryUsage(run: $run);
		return $response;
	}//end callFireworks()

	/**
	 * Anthropic uses direct HTTP through the broker (ProviderFactory::
	 * callAnthropicChat) with the auth headers selected by authMode.
	 *
	 * The per-run token (cli-runner-governed-mcp-and-egress) is bound to the
	 * agent so a tool-requiring `cli` turn is governed via Hermiq's MCP endpoint
	 * rather than refused; null on agent-less chat. Agents-switch-off-and-stop:
	 * the Anthropic loop stops at the agent's cap itself, so the run trace learns
	 * it here.
	 *
	 * @param ResponseTurnRun $run The turn.
	 *
	 * @return string The response text.
	 */
	private function callAnthropic(ResponseTurnRun $run): string {
		$toolExecutor = $this->anthropicToolExecutor(run: $run);
		if ($run->agent !== null) {
			$run->progress->cliAgentId = (string)$run->agent->getUuid();
		}

		$response = $this->requestAnthropic(run: $run, toolExecutor: $toolExecutor);
		$this->markLlmDone(run: $run);

		$run->trace?->recordStopWhen(stopped: $this->providerFactory->lastCallHitToolCap(), reason: TurnGuard::LIMIT_REACHED);

		// The input and output tokens of every request of the turn, tool loop
		// included, which a token budget counts (hermiq#985).
		$this->recordFactoryUsage(run: $run);
		return $response;
	}//end callAnthropic()

	/**
	 * The Anthropic request of the turn.
	 *
	 * @param ResponseTurnRun $run          The turn.
	 * @param Closure|null    $toolExecutor Runs a requested tool, or null for a text-only turn.
	 *
	 * @return string The response text.
	 */
	private function requestAnthropic(ResponseTurnRun $run, ?Closure $toolExecutor): string {
		return $this->providerFactory->callAnthropicChat(
			credentialId: (string)$run->progress->driver->credentialId,
			model: $run->progress->driver->model,
			baseUrl: (string)$run->progress->driver->baseUrl,
			messageHistory: $run->messageHistory,
			authMode: (string)$run->progress->driver->authMode,
			functions: $run->progress->functions,
			toolExecutor: $toolExecutor,
			executionMode: $run->progress->driver->executionMode,
			agentId: $run->progress->cliAgentId,
			maxTokens: $run->progress->driver->maxTokens,
			conversationId: $run->conversationId,
			maxToolCalls: (new AgentAvailability())->maxToolCalls(agent: $run->agent)
		);
	}//end requestAnthropic()

	/**
	 * The executor callAnthropicChat runs a requested tool with, by (name, input).
	 *
	 * It runs the tool through the SAME governed FunctionInfo objects the LLPhant
	 * path uses (ToolLoop::buildFunctionInfos — one shared FacadeToolInvoker,
	 * agent/guardrails/approval/redaction/trace/dry-run all applied) via
	 * FunctionInfo::callWithArguments(). The tool name Claude receives
	 * (ProviderFactory::buildAnthropicTools) and the FunctionInfo name both
	 * derive from `$functions['name']`, so they match. Null when there are no
	 * tools → callAnthropicChat runs text-only (the fail-safe: never advertise a
	 * tool Hermiq cannot run).
	 *
	 * @param ResponseTurnRun $run The turn.
	 *
	 * @return Closure|null
	 */
	private function anthropicToolExecutor(ResponseTurnRun $run): ?Closure {
		if (empty($run->progress->functions) === true) {
			return null;
		}

		$anthropicFnByName = [];
		foreach ($this->functionInfosFor(run: $run) as $functionInfo) {
			$anthropicFnByName[$functionInfo->name] = $functionInfo;
		}

		return static function (string $toolName, array $toolInput) use ($anthropicFnByName): string {
			$functionInfo = ($anthropicFnByName[$toolName] ?? null);
			if ($functionInfo === null) {
				return (string)json_encode(['error' => "Unknown tool: {$toolName}"]);
			}

			return (string)$functionInfo->callWithArguments($toolInput);
		};
	}//end anthropicToolExecutor()

	/**
	 * The governed FunctionInfo objects of the turn's functions.
	 *
	 * Agent-guardrails: $agent (the approval-gate check needs it) and
	 * $organisation (resolved for tenant-model-policy) are threaded so ToolLoop
	 * can resolve the effective GuardrailPolicy's tool classification exactly
	 * once for this turn.
	 *
	 * @param ResponseTurnRun $run The turn.
	 *
	 * @return array<int, \LLPhant\Chat\FunctionInfo\FunctionInfo>
	 */
	private function functionInfosFor(ResponseTurnRun $run): array {
		return $this->toolLoop->buildFunctionInfos(
			functions: $run->progress->functions,
			channel: $run->channel,
			trace: $run->trace,
			agent: $run->agent,
			organisation: $run->progress->organisation,
			dryRun: $run->dryRun
		);
	}//end functionInfosFor()

	/**
	 * OpenAI / Ollama: the LLPhant chat instance from the driver.
	 *
	 * Expose the LLM token/latency usage for per-run cost recording
	 * (run-analytics) and token budgets. OllamaChat accumulates usage via the
	 * llphant-ollama-usage-capture vendor patch; OpenAIChat keeps a running
	 * total, read as a before/after difference (hermiq#985).
	 *
	 * @param ResponseTurnRun $run The turn.
	 *
	 * @return string The response text.
	 *
	 * @throws ProviderUnavailableException When the driver carries no chat instance.
	 */
	private function callLlphant(ResponseTurnRun $run): string {
		$call = new LlphantChatCall(chat: $this->toolReadyChat(run: $run), logger: $this->logger);
		$response = $call->generate(run: $run);
		$this->markLlmDone(run: $run);

		$this->lastUsage = $call->usage();
		$this->lastUsage['llmSeconds'] = round($run->progress->llmTime, 2);
		return $response;
	}//end callLlphant()

	/**
	 * The driver's LLPhant chat instance, with the turn's governed tools set.
	 *
	 * @param ResponseTurnRun $run The turn.
	 *
	 * @return OpenAIChat|OllamaChat
	 *
	 * @throws ProviderUnavailableException When the driver carries no chat instance.
	 */
	private function toolReadyChat(ResponseTurnRun $run): OpenAIChat|OllamaChat {
		$chat = $run->progress->driver->chat;
		if ($chat === null) {
			throw new ProviderUnavailableException(message: "Provider {$run->progress->driver->provider} returned no chat instance");
		}

		if (empty($run->progress->functions) === false) {
			$chat->setTools($this->functionInfosFor(run: $run));
		}

		return $chat;
	}//end toolReadyChat()

	/**
	 * Stop the model-call clock.
	 *
	 * @param ResponseTurnRun $run The turn.
	 *
	 * @return void
	 */
	private function markLlmDone(ResponseTurnRun $run): void {
		$run->progress->llmTime = microtime(true) - $run->progress->llmStartTime;
	}//end markLlmDone()

	/**
	 * Expose the usage the provider factory recorded for its last call, with the
	 * model-call seconds beside it.
	 *
	 * @param ResponseTurnRun $run The turn.
	 *
	 * @return void
	 */
	private function recordFactoryUsage(ResponseTurnRun $run): void {
		$this->lastUsage = $this->providerFactory->lastCallUsage();
		$this->lastUsage['llmSeconds'] = round($run->progress->llmTime, 2);
	}//end recordFactoryUsage()

	/**
	 * Log a failed turn and wrap its cause (the ported "Failed to generate
	 * response" contract).
	 *
	 * @param Exception $error The cause.
	 *
	 * @return Exception The wrapped failure to throw.
	 */
	private function turnFailure(Exception $error): Exception {
		$this->logger->error(
			message: '[ResponseGenerationHandler] Failed to generate response',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'error' => $error->getMessage(),
			]
		);
		return new Exception('Failed to generate response: ' . $error->getMessage(), (int)$error->getCode(), $error);
	}//end turnFailure()
}//end class

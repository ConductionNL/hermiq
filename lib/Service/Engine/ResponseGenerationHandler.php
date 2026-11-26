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
 * The `nextcloud` driver (Nextcloud AI) is accepted for chat and agent turns since
 * use-nextcloud-ai-settings: the turn runs as `core:text2text:chat` or, with tools,
 * `core:text2text:chatwithtools` tasks, with hermiq running the tool loop through the
 * same governed executor as the Anthropic branch. TaskProcessing has no streaming
 * surface, so the SSE caller receives the whole answer at once.
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
 * @spec openspec/changes/archive/2026-09-06-agent-engine-port/tasks.md#task-1-1
 * @spec openspec/changes/archive/2026-09-06-agent-engine-port/tasks.md#task-2-1
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Engine;

use OCA\Hermiq\Service\RetrievalScope\ValidatedMode;
use Closure;
use Exception;
use LLPhant\Chat\Message as LLPhantMessage;
use LLPhant\Chat\OllamaChat;
use LLPhant\Chat\OpenAIChat;
use OCA\Hermiq\Mcp\ComputerToolDescriptors;
use OCA\Hermiq\Service\Agent\AgentAvailability;
use OCA\Hermiq\Kernel\Contracts\BudgetHardCapInterface;
use OCA\Hermiq\Service\AiFeature\FeatureProviderResolver;
use OCA\Hermiq\Service\AnswerCache\AnswerCacheService;
use OCA\Hermiq\Service\Llm\AttachmentPartBuilder;
use OCA\Hermiq\Service\Llm\ChatDriver;
use OCA\Hermiq\Service\Llm\ProviderCooldown;
use OCA\Hermiq\Service\Llm\ProviderFactory;
use OCA\Hermiq\Service\Llm\ProviderUnavailableException;
use OCA\Hermiq\Service\Llm\ReasoningOllamaChat;
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
 * @spec openspec/changes/archive/2026-09-06-agent-engine-port/tasks.md#task-1-1
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassLength) carried in with models-prompt-and-answer-cache; splitting it
 * is follow-up work, not part of this merge.
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
	 * The model's reasoning from the last generateResponse() call, unfiltered; ''
	 * when the agent's owner did not ask for it or the provider returned none
	 * (chat-how-an-answer-came-about D3). The Engine filters and redacts it.
	 *
	 * @var string
	 */
	public string $lastReasoning = '';

	/**
	 * What the model was given in the last generateResponse() call, for the context
	 * meter (chat-how-an-answer-came-about D4): the provider and model that served it
	 * and the characters of every message sent (system prompt, history and turn).
	 *
	 * @var array{provider: string, model: string, promptCharacters: int}|array{}
	 */
	public array $lastPrompt = [];

	/**
	 * The notices about attachments the last turn could not send natively, in the
	 * person's language (chat-attachments-and-images D6). Read by the Engine onto
	 * its result, and from there onto the stream's `final` frame.
	 *
	 * @var list<string>
	 */
	public array $attachmentNotices = [];

	/**
	 * How the last turn's answer came from the answer cache, or [] when the model
	 * gave it (models-prompt-and-answer-cache): `layer`, `entryId` and, for the
	 * similar layer, `similarity`. Read by the Engine onto its result, and from
	 * there onto the run and the `final` frame.
	 *
	 * @var array<string, string|float>
	 */
	public array $lastCache = [];

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
	 * @param ProviderCooldown|null $cooldown Skips a provider cooling down for the organisation
	 *                                       (models-several-models-per-turn). Null keeps none.
	 * @param BudgetHardCapInterface|null $budget The budget's hard cap, checked before each reference
	 *                                   call of an ensemble turn (models-several-models-per-turn).
	 *                                   Null checks none.
	 * @param AnswerCacheService|null    $answerCache     The answer cache of turns without tools
	 *                                                    (models-prompt-and-answer-cache). Null:
	 *                                                    every turn calls the model.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-09-06-agent-engine-port/tasks.md#task-1-1
	 */
	public function __construct(
		private readonly ProviderFactory $providerFactory,
		private readonly ToolLoop $toolLoop,
		private readonly LoggerInterface $logger,
		private readonly ?FeatureProviderResolver $featureResolver = null,
		private readonly ?AttachmentPartBuilder $attachmentParts = null,
		private readonly ?ProviderCooldown $cooldown = null,
		private readonly ?BudgetHardCapInterface $budget = null,
		private readonly ?AnswerCacheService $answerCache = null,
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
	 * @param string $projectKey The session's project (memory-project-scopes): the memory
	 *                           tools read and write that project's memory beside the
	 *                           shared one. '' is a session without a project.
	 * @param bool   $answerCacheBypass True when the person asked the model again: the
	 *                                  answer cache is neither read nor written.
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
	 * @spec openspec/changes/archive/2026-09-06-agent-engine-port/tasks.md#task-1-1
	 * @spec openspec/changes/archive/2026-09-06-agent-engine-port/tasks.md#task-2-1
	 * @spec openspec/changes/archive/2026-09-06-agent-context-system/tasks.md#task-3-2
	 * @spec openspec/specs/run-audit-log/spec.md#requirement-every-run-and-tool-call-is-audited-mvp
	 * @spec openspec/changes/archive/2026-07-13-run-replay-and-dry-run/tasks.md#task-3-thread-dryrun-through-toolloop-engine-and-responsegenerationhandler
	 * @spec openspec/specs/agent-management-ui/spec.md#requirement-placeholders-in-an-agents-instructions-are-filled-in-per-turn-req-agvar-001
	 * @spec openspec/specs/chat-attachments/spec.md#requirement-an-attachment-is-read-as-the-person-who-sent-it-req-catt-003
	 * @spec openspec/specs/chat-attachments/spec.md#requirement-a-model-that-reads-images-or-pdfs-natively-gets-them-natively-req-catt-004
	 * @spec openspec/specs/agent-engine-port/spec.md#requirement-the-model-receives-the-users-turn-once
	 * @spec openspec/changes/archive/2026-11-25-chat-about-a-document-cites-its-sources/specs/document-chat/spec.md#requirement-every-scoped-answer-cites-its-sources-req-dchat-003
	 * @spec openspec/changes/models-prompt-and-answer-cache/specs/answer-cache/spec.md#requirement-cached-answers-can-be-cleared-and-expire-req-acache-004
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
		string $projectKey = '',
		bool $answerCacheBypass = false,
	): string {
		// Chat-about-a-document: a scoped turn with nothing in scope is answered
		// with that fact, and the model is never asked to answer from general knowledge.
		$noMatch = self::scopedNoMatchReply(context: $context);
		if ($noMatch !== null) {
			$this->attachmentNotices = [];
			$this->lastUsage = [];
			$this->lastReasoning = '';
			$this->lastPrompt = [];
			return $noMatch;
		}

		$run = $this->startTurn(run: new ResponseTurnRun(userMessage: $userMessage, context: $context), agent: $agent);
		$run->selectedTools = $selectedTools;
		$run->messageHistory = $messageHistory;
		$run->channel = $channel;
		$run->cnAiContext = $cnAiContext;
		$run->contextPreamble = $contextPreamble;
		$run->trace = $trace;
		$run->dryRun = $dryRun;
		$run->conversationId = $conversationId;
		$run->projectKey = $projectKey;
		$run->promptVariables = $promptVariables;
		$run->attachments = $attachments;
		$run->speaker = $speaker;
		$run->answerCacheBypass = $answerCacheBypass;

		return $this->runTurn(run: $run);
	}//end generateResponse()

	/**
	 * The reply of a scoped turn with nothing in scope, or null for any other turn.
	 *
	 * @param array<string, mixed> $context The turn's context.
	 *
	 * @return string|null The no-match sentence.
	 *
	 * @spec openspec/changes/archive/2026-11-25-chat-about-a-document-cites-its-sources/specs/document-chat/spec.md#requirement-every-scoped-answer-cites-its-sources-req-dchat-003
	 */
	private static function scopedNoMatchReply(array $context): ?string {
		$reply = (string)($context['noMatch'] ?? '');
		if (($context['scoped'] ?? false) === true && ($context['sources'] ?? []) === [] && $reply !== '') {
			return $reply;
		}

		return null;
	}//end scopedNoMatchReply()

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
		$this->lastReasoning = '';
		$this->lastPrompt = [];
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
			// Tools-browser-and-computer-use D4: a screenshot is an image, so a model
			// path that cannot read one in a tool result is never offered them.
			$run->progress->functions = ComputerToolDescriptors::forDriver(
				functions: $run->progress->functions,
				provider: $run->progress->driver->provider,
				executionMode: $run->progress->driver->executionMode
			);
			// Models-prompt-and-answer-cache: the lookup sits where the model call is,
			// after every gate, so a blocked run stays blocked. The messages are built
			// once for the key; the turn itself builds them again, with any reference
			// answers, from the history as it was.
			$history = $run->messageHistory;
			$this->lastCache = [];
			$cached = null;
			if ($this->answerCache !== null) {
				$this->buildMessages(run: $run);
				$cached = $this->answerCache->lookup(run: $run);
			}

			if ($cached !== null) {
				$this->lastCache = ['layer' => $cached['layer'], 'entryId' => $cached['entryId']];
				if (isset($cached['similarity']) === true) {
					$this->lastCache['similarity'] = $cached['similarity'];
				}

				$this->lastUsage = ['promptTokens' => 0, 'completionTokens' => 0, 'llmSeconds' => 0.0];
				return $cached['answer'];
			}

			$keyed = $run->messageHistory;
			$run->messageHistory = $history;
			$references = $this->askReferenceModels(run: $run);
			$response = $this->answerTurn(run: $run);
			$this->addReferenceUsage(usages: $references, run: $run);
			if ($references === [] && $this->answerCache !== null) {
				// Only a single-model answer is stored, under the key it was looked up by.
				$answered = $run->messageHistory;
				$run->messageHistory = $keyed;
				$this->answerCache->store(run: $run, answer: $response);
				$run->messageHistory = $answered;
			}
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
		// Validated mode (memory-retrieval-scope D3): the turn resolves no tools,
		// so the model cannot fetch anything beyond the validated passages.
		if (ValidatedMode::isOn(agentData: ($run->agent?->getObject() ?? [])) === true) {
			$run->progress->functions = [];
			$run->progress->toolsTime = microtime(true) - $toolsStartTime;
			return;
		}

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
	 * and record which model saw the turn.
	 *
	 * @param ResponseTurnRun $run The turn.
	 *
	 * @return void
	 *
	 * @throws ProviderUnavailableException When the driver serves background work only.
	 * @spec openspec/specs/agent-management-ui/spec.md#REQ-SPCOV-002
	 */
	private function resolveDriver(ResponseTurnRun $run): void {
		$llmConfig = $this->providerFactory->getLlmConfig();
		$inputs = new ChatDriverInputs(run: $run);
		$run->progress->organisation = $inputs->organisation();
		$run->progress->aiFeature = $inputs->aiFeature();
		$run->progress->driver = $this->createDriver(llmConfig: $llmConfig, inputs: $inputs);

		$this->recordProviderDisclosure(run: $run);
		$this->logProvider(run: $run);
	}//end resolveDriver()

	/**
	 * The first half of an ensemble turn: ask each reference model the prompt
	 * without tools, and hand their answers to the agent's own model as labelled
	 * context (models-several-models-per-turn D7). Nothing happens for an agent
	 * without an enabled ensemble.
	 *
	 * @param ResponseTurnRun $run The turn, with its own driver resolved.
	 *
	 * @return array<int, array{provider: string, model: string, usage: array<string, mixed>}> What each reference call cost.
	 *
	 * @spec openspec/specs/model-ensemble/spec.md#requirement-reference-models-answer-without-tools-and-the-agents-own-model-merges-req-mens-002
	 */
	private function askReferenceModels(ResponseTurnRun $run): array {
		$references = ReferenceAnswerRound::referencesOf(agentData: $run->agentData());
		if ($references === []) {
			return [];
		}

		$round = new ReferenceAnswerRound(
			driverFor: fn (string $provider, string $model): ChatDriver => $this->hopDriver(run: $run, provider: $provider, model: $model),
			answer: fn (ChatDriver $driver): array => $this->answerAsReference(run: $run, driver: $driver),
			budget: $this->budget
		);
		$collected = $round->collect(run: $run, references: $references);
		$run->referenceAnswers = $collected['answers'];

		return $collected['usages'];
	}//end askReferenceModels()

	/**
	 * One reference call: the turn's prompt and history, no tools, no stream,
	 * no trace steps of its own.
	 *
	 * @param ResponseTurnRun $run    The turn.
	 * @param ChatDriver      $driver The reference model's driver.
	 *
	 * @return array{text: string, usage: array<string, mixed>} Its answer and what it cost.
	 *
	 * @spec openspec/specs/model-ensemble/spec.md#requirement-reference-models-answer-without-tools-and-the-agents-own-model-merges-req-mens-002
	 */
	private function answerAsReference(ResponseTurnRun $run, ChatDriver $driver): array {
		$reference = new ResponseTurnRun(userMessage: $run->userMessage, context: $run->context);
		$reference->agent = $run->agent;
		$reference->cnAiContext = $run->cnAiContext;
		$reference->contextPreamble = $run->contextPreamble;
		$reference->dryRun = $run->dryRun;
		$reference->conversationId = $run->conversationId;
		$reference->promptVariables = $run->promptVariables;
		$reference->attachments = $run->attachments;
		$reference->speaker = $run->speaker;
		$reference->progress->organisation = $run->progress->organisation;
		$reference->progress->aiFeature = $run->progress->aiFeature;
		$reference->progress->functions = [];

		$this->lastUsage = [];
		$text = $this->answerWith(run: $reference, driver: $driver, history: $run->messageHistory);

		return ['text' => $text, 'usage' => $this->lastUsage];
	}//end answerAsReference()

	/**
	 * Add the reference calls to the turn's usage: `usage` becomes the sum of every
	 * call, and `usageByModel` holds each call's own (models-several-models-per-turn D8).
	 *
	 * @param array<int, array{provider: string, model: string, usage: array<string, mixed>}> $usages The reference calls.
	 * @param ResponseTurnRun                                                                   $run    The turn, whose own driver answered last.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/model-ensemble/spec.md#requirement-an-ensemble-counts-every-call-against-the-budget-req-mens-004
	 */
	private function addReferenceUsage(array $usages, ResponseTurnRun $run): void {
		if ($usages === []) {
			return;
		}

		$own = $this->lastUsage;
		$calls = array_merge($usages, [['provider' => $run->progress->driver->provider, 'model' => $run->progress->driver->model, 'usage' => $own]]);
		$this->lastUsage = EnsembleUsage::sum(calls: $calls);
	}//end addReferenceUsage()

	/**
	 * Answer the turn from the first provider, or from the fallback chain when it
	 * fails with a temporary error or is cooling down (models-several-models-per-turn).
	 *
	 * @param ResponseTurnRun $run The turn, with its first driver resolved.
	 *
	 * @return string The answer.
	 *
	 * @throws Exception The first provider's failure when the turn may not move.
	 *
	 * @spec openspec/specs/tenant-model-policy/spec.md#requirement-a-temporary-provider-failure-moves-the-turn-to-the-next-allowed-hop-req-mfail-002
	 */
	private function answerTurn(ResponseTurnRun $run): string {
		$history = $run->messageHistory;
		$driverFor = fn (string $provider, string $model): ChatDriver => $this->hopDriver(run: $run, provider: $provider, model: $model);
		$answer = fn (ChatDriver $driver): string => $this->answerWith(run: $run, driver: $driver, history: $history);

		if ($this->cooldown?->isCooling(organisation: (string)$run->progress->organisation, provider: $run->progress->driver->provider) === true) {
			$loop = $this->fallbackLoop(run: $run);
			if ($loop->firstIsCooling(run: $run) === true) {
				return $loop->skipCoolingFirst(run: $run, driverFor: $driverFor, answer: $answer);
			}
		}

		$startedAt = microtime(true);
		try {
			$this->buildMessages(run: $run);
			$text = $this->callProvider(run: $run);
		} catch (TurnStoppedException $e) {
			throw $e;
		} catch (Exception $e) {
			return $this->fallbackLoop(run: $run)->recover(run: $run, failure: $e, startedAt: $startedAt, driverFor: $driverFor, answer: $answer);
		}

		$run->trace?->recordProviderAttempt(
			provider: $run->progress->driver->provider,
			model: $run->progress->driver->model,
			outcome: 'answered',
			reason: '',
			durationMs: (int)round((microtime(true) - $startedAt) * 1000),
			received: true
		);

		return $text;
	}//end answerTurn()

	/**
	 * The fallback loop over the organisation's effective chain.
	 *
	 * @param ResponseTurnRun $run The turn.
	 *
	 * @return ProviderFallbackLoop
	 *
	 * @spec openspec/specs/tenant-model-policy/spec.md#requirement-a-temporary-provider-failure-moves-the-turn-to-the-next-allowed-hop-req-mfail-002
	 */
	private function fallbackLoop(ResponseTurnRun $run): ProviderFallbackLoop {
		return new ProviderFallbackLoop(
			hops: $this->providerFactory->fallbackChainFor(organisation: $run->progress->organisation),
			cooldown: $this->cooldown
		);
	}//end fallbackLoop()

	/**
	 * A hop's driver, through the same driver path and gates as the first provider.
	 *
	 * @param ResponseTurnRun $run      The turn.
	 * @param string          $provider The hop's provider.
	 * @param string          $model    The hop's model.
	 *
	 * @return ChatDriver
	 *
	 * @spec openspec/specs/tenant-model-policy/spec.md#requirement-a-temporary-provider-failure-moves-the-turn-to-the-next-allowed-hop-req-mfail-002
	 */
	private function hopDriver(ResponseTurnRun $run, string $provider, string $model): ChatDriver {
		$inputs = new ChatDriverInputs(run: $run);
		return $this->providerFactory->createChatDriver(
			llmConfig: $this->providerFactory->getLlmConfig(),
			agentModel: $inputs->model(),
			agentTemperature: $inputs->temperature(),
			organisation: $inputs->organisation(),
			agentMaxTokens: $inputs->maxTokens(),
			aiFeature: $inputs->aiFeature(),
			documentReference: $inputs->documentReference(),
			agentCredentialIds: $inputs->credentialIds(),
			attachmentReferences: $inputs->attachmentReferences(),
			providerOverride: $provider,
			modelOverride: $model
		);
	}//end hopDriver()

	/**
	 * Run the turn on a hop's driver, from the history as it was before the first try.
	 *
	 * @param ResponseTurnRun     $run     The turn.
	 * @param ChatDriver          $driver  The hop's driver.
	 * @param array<int, mixed>   $history The message history before the first try.
	 *
	 * @return string The answer.
	 *
	 * @spec openspec/specs/tenant-model-policy/spec.md#requirement-a-temporary-provider-failure-moves-the-turn-to-the-next-allowed-hop-req-mfail-002
	 */
	private function answerWith(ResponseTurnRun $run, ChatDriver $driver, array $history): string {
		$run->progress->driver = $driver;
		$run->messageHistory = $history;
		$this->recordProviderDisclosure(run: $run);
		// A hop to a model path that cannot read a screenshot drops the computer tools too.
		$run->progress->functions = ComputerToolDescriptors::forDriver(
			functions: $run->progress->functions,
			provider: $driver->provider,
			executionMode: $driver->executionMode
		);
		$this->buildMessages(run: $run);
		return $this->callProvider(run: $run);
	}//end answerWith()

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
		// Models-prompt-and-answer-cache: the stable block first, the per-turn block
		// as its own system message after it, so the cached prefix stays the same.
		$prompt  = new TurnSystemPrompt();
		$system  = [LLPhantMessage::system($prompt->stable(run: $run))];
		$perTurn = $prompt->perTurn(run: $run);
		if ($perTurn !== '') {
			$system[] = LLPhantMessage::system($perTurn);
		}

		array_unshift($run->messageHistory, ...$system);
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
		$this->lastPrompt = [
			'provider' => $run->progress->driver->provider,
			'model' => $run->progress->driver->model,
			'promptCharacters' => self::promptCharacters(messageHistory: $run->messageHistory),
		];
		if ($run->progress->driver->provider === 'fireworks') {
			return $this->callFireworks(run: $run);
		}

		if ($run->progress->driver->provider === 'anthropic') {
			return $this->callAnthropic(run: $run);
		}

		if ($run->progress->driver->provider === 'nextcloud') {
			return $this->callNextcloud(run: $run);
		}

		if ($run->progress->driver->apiMode === ProviderFactory::API_MODE_RESPONSES) {
			return $this->callResponses(run: $run);
		}

		return $this->callLlphant(run: $run);
	}//end callProvider()

	/**
	 * Nextcloud AI (use-nextcloud-ai-settings): the turn runs as TaskProcessing
	 * tasks through the provider set up under Administration settings > Artificial
	 * intelligence, as the session user (a scheduled run impersonates its acting
	 * user). Tools go through the same governed executor as the Anthropic path;
	 * without a chatwithtools provider a turn with tools fails loudly instead of
	 * running without them. TaskProcessing reports no token usage, so only the
	 * latency is recorded.
	 *
	 * @param ResponseTurnRun $run The turn.
	 *
	 * @return string The response text.
	 *
	 * @spec openspec/changes/archive/2026-11-20-use-nextcloud-ai-settings/tasks.md#task-2-4
	 */
	private function callNextcloud(ResponseTurnRun $run): string {
		$response = $this->providerFactory->callNextcloudChat(
			messageHistory: $run->messageHistory,
			functions: $run->progress->functions,
			toolExecutor: $this->anthropicToolExecutor(run: $run),
			maxToolCalls: (new AgentAvailability())->maxToolCalls(agent: $run->agent)
		);
		$this->markLlmDone(run: $run);

		$run->trace?->recordStopWhen(stopped: $this->providerFactory->lastCallHitToolCap(), reason: TurnGuard::LIMIT_REACHED);

		$this->lastUsage = ['llmSeconds' => round($run->progress->llmTime, 2)];
		return $response;
	}//end callNextcloud()

	/**
	 * OpenAI and OpenAI-compatible in Responses API mode: direct HTTP
	 * (ProviderFactory::callOpenAiResponses), every function call through the
	 * same governed executor the Anthropic path uses (models-provider-connections D4).
	 *
	 * @param ResponseTurnRun $run The turn.
	 *
	 * @return string The response text.
	 *
	 * @spec openspec/changes/models-provider-connections/specs/llm-provider-connections/spec.md#requirement-a-provider-can-run-in-responses-api-mode-with-governed-tools-req-pconn-004
	 */
	private function callResponses(ResponseTurnRun $run): string {
		$response = $this->providerFactory->callOpenAiResponses(
			driver: $run->progress->driver,
			messageHistory: $run->messageHistory,
			functions: $run->progress->functions,
			toolExecutor: $this->anthropicToolExecutor(run: $run),
			maxToolCalls: (new AgentAvailability())->maxToolCalls(agent: $run->agent)
		);
		$this->markLlmDone(run: $run);

		$run->trace?->recordStopWhen(stopped: $this->providerFactory->lastCallHitToolCap(), reason: TurnGuard::LIMIT_REACHED);
		$this->recordFactoryUsage(run: $run);
		return $response;
	}//end callResponses()

	/**
	 * The characters of every message the model is sent, for an estimated token count.
	 *
	 * @param array<int, mixed> $messageHistory The LLPhant messages of the turn.
	 *
	 * @return int The character count (multibyte-aware).
	 *
	 * @spec openspec/specs/answer-transparency/spec.md#requirement-a-person-sees-how-full-the-models-context-is-req-atrn-004
	 */
	public static function promptCharacters(array $messageHistory): int {
		$characters = 0;
		foreach ($messageHistory as $message) {
			if (is_object($message) === true && is_string($message->content ?? null) === true) {
				$characters += mb_strlen($message->content);
			}
		}

		return $characters;
	}//end promptCharacters()

	/**
	 * Whether the agent's owner switched on "Show the model's reasoning".
	 *
	 * @param ResponseTurnRun $run The turn.
	 *
	 * @return bool True only for a stored boolean true.
	 *
	 * @spec openspec/specs/answer-transparency/spec.md#requirement-the-models-reasoning-is-shown-only-when-the-owner-allows-it-and-the-provider-returns-it-req-atrn-003
	 */
	public static function showsReasoning(ResponseTurnRun $run): bool {
		return ($run->agentData()['showReasoning'] ?? false) === true;
	}//end showsReasoning()

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
		$this->lastReasoning = $this->providerFactory->lastCallReasoning();

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
			maxToolCalls: (new AgentAvailability())->maxToolCalls(agent: $run->agent),
			projectKey: $run->projectKey,
			showReasoning: self::showsReasoning(run: $run)
		);
	}//end requestAnthropic()

	/**
	 * The executor callAnthropicChat and callNextcloudChat run a requested tool with, by (name, input).
	 *
	 * It runs the tool through the SAME governed FunctionInfo objects the LLPhant
	 * path uses (ToolLoop::buildFunctionInfos — one shared FacadeToolInvoker,
	 * agent/guardrails/approval/redaction/trace/dry-run all applied) via
	 * FunctionInfo::callWithArguments(). The tool name Claude receives
	 * (AnthropicMessageFormat::buildAnthropicTools) and the FunctionInfo name both
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
			dryRun: $run->dryRun,
			conversationId: $run->conversationId,
			runId: self::approvalRunOf(run: $run),
			projectKey: $run->projectKey
		);
	}//end functionInfosFor()

	/**
	 * The run a chat turn's computer actions are approved for: its conversation.
	 *
	 * A chat turn has no run token; an action waiting for approval ends the turn
	 * and the next turn of the same conversation retries it, so the conversation
	 * is the run a reviewer's machine pre-authorisation covers, for at most its
	 * fifteen minutes (tools-browser-and-computer-use REQ-COMPUTER-003).
	 *
	 * @param ResponseTurnRun $run The turn.
	 *
	 * @return string `conversation:<id>`, or '' without a conversation.
	 *
	 * @spec openspec/changes/tools-browser-and-computer-use/specs/computer-use/spec.md#requirement-every-computer-action-passes-the-approval-gate-req-computer-003
	 */
	private static function approvalRunOf(ResponseTurnRun $run): string {
		if ($run->conversationId === '') {
			return '';
		}

		return 'conversation:' . $run->conversationId;
	}//end approvalRunOf()

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
		$chat = $this->toolReadyChat(run: $run);
		// Ollama returns reasoning when asked (`think: true`); OpenAI and Nextcloud are
		// never asked (chat-how-an-answer-came-about D3).
		if (($chat instanceof ReasoningOllamaChat) === true && self::showsReasoning(run: $run) === true) {
			$chat->askForReasoning();
		}

		$call = new LlphantChatCall(chat: $chat, logger: $this->logger);
		$response = $call->generate(run: $run);
		$this->markLlmDone(run: $run);
		if (($chat instanceof ReasoningOllamaChat) === true) {
			$this->lastReasoning = $chat->lastThinking;
		}

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

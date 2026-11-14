<?php

/**
 * Hermiq: one model call on an LLPhant chat instance (OpenAI, Ollama).
 *
 * Streams where the provider can and falls back to the blocking call where it
 * cannot, and reads the tokens the call used from the chat instance.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Engine
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/archive/2026-09-06-agent-engine-port/tasks.md#task-1-1
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Engine;

use LLPhant\Chat\OllamaChat;
use LLPhant\Chat\OpenAIChat;
use LLPhant\Exception\MissingFeatureException;
use OCA\Hermiq\Service\Llm\ProviderCallFailedException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * One LLPhant model call of a turn. Built by ResponseGenerationHandler per call.
 *
 * @spec openspec/changes/archive/2026-09-06-agent-engine-port/tasks.md#task-1-1
 */
final class LlphantChatCall {

	/**
	 * The chat instance's running token total before the call (OpenAI).
	 *
	 * @var int
	 */
	private int $tokensBefore = 0;

	/**
	 * Constructor.
	 *
	 * @param OpenAIChat|OllamaChat $chat   The chat instance the call runs on.
	 * @param LoggerInterface       $logger Logger.
	 */
	public function __construct(
		private readonly OpenAIChat|OllamaChat $chat,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Generate the turn's response on the chat instance.
	 *
	 * @param ResponseTurnRun $run The turn.
	 *
	 * @return string The assistant's textual response.
	 *
	 * @spec openspec/changes/archive/2026-09-06-agent-engine-port/tasks.md#task-1-1
	 */
	public function generate(ResponseTurnRun $run): string {
		if ($this->chat instanceof OpenAIChat) {
			$this->tokensBefore = $this->chat->getTotalTokens();
		}

		return $this->invokeChat(chat: $this->chat, run: $run);
	}//end generate()

	/**
	 * The LLM token usage of the call, for per-run cost recording
	 * (run-analytics) and token budgets. OllamaChat accumulates usage via the
	 * llphant-ollama-usage-capture vendor patch; OpenAIChat keeps a running
	 * total, read as a before/after difference (hermiq#985).
	 *
	 * @return array<string, int|float>
	 *
	 * @spec openspec/changes/models-several-models-per-turn/tasks.md#task-7-the-ensemble-turn-and-its-budget
	 */
	public function usage(): array {
		if ($this->chat instanceof OllamaChat) {
			return $this->chat->lastUsage;
		}

		return $this->openAiUsage(chat: $this->chat, tokensBefore: $this->tokensBefore);
	}//end usage()

	/**
	 * The tokens an OpenAI turn used, from LLPhant's running total.
	 *
	 * `OpenAIChat` adds each request's `usage.totalTokens` to a running total and
	 * keeps the prompt/completion split of its LAST request only. The difference
	 * in the total across the call is exact; the last request supplies the
	 * completion tokens, and every other token of the turn (the earlier requests
	 * of a tool loop) is counted as a prompt token. So the sum a token budget
	 * reads is exact, and the split is exact for a turn of one request. A
	 * streamed turn carries no usage in LLPhant, so it records none.
	 *
	 * @param OpenAIChat $chat The chat instance the turn ran on.
	 * @param int $tokensBefore Its running total before the turn.
	 *
	 * @return array<string, int> `promptTokens` and `completionTokens`, or `[]`.
	 *
	 * @spec openspec/changes/models-several-models-per-turn/tasks.md#task-7-the-ensemble-turn-and-its-budget
	 */
	private function openAiUsage(OpenAIChat $chat, int $tokensBefore): array {
		$total = ($chat->getTotalTokens() - $tokensBefore);
		if ($total <= 0) {
			return [];
		}

		$completion = (int)($chat->getLastResponse()?->usage->completionTokens ?? 0);
		$completion = min($completion, $total);

		return [
			'promptTokens' => ($total - $completion),
			'completionTokens' => $completion,
		];
	}//end openAiUsage()

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
	 * Whether tools were registered on the chat is read from the turn's
	 * functions (tracked at setTools() time — the ported original recovered this
	 * via reflection on LLPhant's protected `tools` property; we set the tools
	 * ourselves, so no reflection).
	 *
	 * @param OpenAIChat|OllamaChat $chat Configured chat client.
	 * @param ResponseTurnRun       $run  The turn: its message history, channel, provider and functions.
	 *
	 * @return string The assistant's textual response.
	 *
	 * @spec openspec/changes/archive/2026-09-06-agent-engine-port/tasks.md#task-1-1
	 * @spec openspec/changes/models-several-models-per-turn/tasks.md#task-2
	 */
	private function invokeChat(OpenAIChat|OllamaChat $chat, ResponseTurnRun $run): string {
		try {
			return $this->callChat(chat: $chat, run: $run);
		} catch (Throwable $failure) {
			// Hermiq's own exceptions (policy, residency and redaction refusals,
			// an unavailable provider) keep their class so every caller's catch
			// still holds; only the client's failures are typed, once.
			if (str_starts_with(get_class($failure), 'OCA\\Hermiq\\') === true) {
				throw $failure;
			}

			throw ProviderCallFailedException::fromThrowable(failure: $failure);
		}
	}//end invokeChat()

	/**
	 * Call the chat client: streaming when a channel is open, else blocking.
	 *
	 * @param OpenAIChat|OllamaChat $chat The LLPhant chat.
	 * @param ResponseTurnRun       $run  The turn.
	 *
	 * @return string The answer.
	 */
	private function callChat(OpenAIChat|OllamaChat $chat, ResponseTurnRun $run): string {
		$ollamaWithTools = (($chat instanceof OllamaChat) === true && empty($run->progress->functions) === false);

		// Both branches of `$chat`'s OpenAIChat|OllamaChat type declare
		// generateStreamOfText(), so the method_exists() conjunct this replaces
		// could never be false. A provider that advertises streaming but cannot
		// deliver still throws MissingFeatureException, which is caught below.
		if ($run->channel !== null && $ollamaWithTools === false) {
			try {
				return $this->streamChat(chat: $chat, run: $run);
			} catch (MissingFeatureException $e) {
				// Provider advertises streaming but cannot deliver — log + degrade.
				$this->logStreamingFallback(provider: $run->progress->driver->provider, error: $e);
			}
		}

		// Non-streaming fallback — load-bearing for blocking callers, for
		// providers without streaming support, and for Ollama-with-tools where
		// the FacadeToolInvoker still fires from the blocking callFunction path.
		return $chat->generateChat($run->messageHistory);
	}//end callChat()

	/**
	 * Log that a provider advertising streaming could not deliver it.
	 *
	 * @param string                  $provider Provider slug.
	 * @param MissingFeatureException $error    The streaming failure.
	 *
	 * @return void
	 */
	private function logStreamingFallback(string $provider, MissingFeatureException $error): void {
		$this->logger->info(
			message: '[ResponseGenerationHandler] Streaming unavailable, falling back to blocking call',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'provider' => $provider,
				'error' => $error->getMessage(),
			]
		);
	}//end logStreamingFallback()

	/**
	 * Drive the LLPhant streaming surface, forwarding each chunk to the
	 * channel's `emitToken` callback and assembling the full text for the
	 * final SSE frame.
	 *
	 * @param OpenAIChat|OllamaChat $chat Configured chat instance.
	 * @param ResponseTurnRun       $run  The turn: its message history and the
	 *                                    (non-null) channel to forward chunks to.
	 *
	 * @return string Assembled assistant text.
	 *
	 * @throws MissingFeatureException When the provider's streaming surface throws.
	 *
	 * @spec openspec/changes/archive/2026-09-06-agent-engine-port/tasks.md#task-1-1
	 */
	private function streamChat(OpenAIChat|OllamaChat $chat, ResponseTurnRun $run): string {
		$stream = $chat->generateChatStream($run->messageHistory);
		$assembled = '';

		// PSR-7 StreamInterface: read until EOF; LLPhant emits one chunk
		// per delta when `delta.content` is non-empty.
		while ($stream->eof() === false) {
			$chunk = $stream->read(1024);
			if ($chunk === '') {
				continue;
			}

			$assembled .= $chunk;
			$run->channel?->emitToken(delta: $chunk);
		}

		return $assembled;
	}//end streamChat()
}//end class

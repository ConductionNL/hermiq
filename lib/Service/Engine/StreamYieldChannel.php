<?php

/**
 * Hermiq Chat Stream Yield Channel.
 *
 * Plain value object that forwards token / tool-call / tool-result events
 * from the response-generation handler to a consuming SSE controller during
 * a streaming LLM call. Heartbeats are not one of them — see the note at the
 * foot of the class.
 *
 * The channel forwards events as they arrive, with one exception: after
 * `holdTokens()` it keeps token deltas back until `releaseTokens()` hands it
 * the text that may be shown, so an answer that still has to pass the output
 * guardrail reaches the client only in its reviewed form. Buffering of
 * partial tool-call frames lives in `ResponseGenerationHandler`; SSE framing
 * + heartbeat interleaving lives in the (later-chunk) stream controller. Multiple callbacks per event type are
 * allowed (future-proofs for telemetry / logging interceptors). Late
 * registration after a prior emit is allowed; the new callback only sees
 * subsequent events (no replay).
 *
 * Ported verbatim from `OCA\OpenRegister\Mcp\Service\Chat\StreamYieldChannel` — pure
 * streaming plumbing with no persistence and no OR-specific dependency, so no
 * adaptation beyond the namespace was needed.
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
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Engine;

use Closure;

/**
 * StreamYieldChannel
 *
 * Request-scoped event forwarder used by the SSE chat stream. Pure-PHP value
 * object — no DI, no I/O.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Engine
 *
 * @spec openspec/changes/agent-engine-port/tasks.md#task-1-1
 */
class StreamYieldChannel {

	/**
	 * Screens the `arguments` of a tool-call payload and the `result` of a
	 * tool-result payload before any callback sees them; null forwards them as given.
	 *
	 * @var Closure|null
	 */
	private ?Closure $toolDetailScreen = null;

	/**
	 * Registered token callbacks. Each receives the new token delta as a
	 * single string argument.
	 *
	 * @var array<int, callable>
	 */
	private array $tokenCallbacks = [];

	/**
	 * Registered tool-call callbacks. Each receives the assembled tool-call
	 * payload (an associative array with `toolId` + `arguments`) as a single
	 * argument.
	 *
	 * @var array<int, callable>
	 */
	private array $toolCallCallbacks = [];

	/**
	 * Registered tool-result callbacks. Each receives the tool-result
	 * payload (an associative array with `toolId`, `result`, `isError`)
	 * as a single argument.
	 *
	 * @var array<int, callable>
	 */
	private array $toolResultCallbacks = [];

	/**
	 * Whether token deltas are kept back until `releaseTokens()`.
	 *
	 * @var boolean
	 */
	private bool $holdingTokens = false;

	/**
	 * Token deltas kept back while holding, in arrival order.
	 *
	 * @var array<int, string>
	 */
	private array $heldTokens = [];

	/**
	 * Register a callback invoked for each token delta emitted by the LLM
	 * stream.
	 *
	 * @param callable $callback Function receiving a single string argument
	 *                           (the new token delta).
	 *
	 * @return void
	 *
	 * @spec exclude Pure pub-sub forwarder plumbing — registers a callback; carries no business logic
	 *              (the class is self-documented as "pure forwarding").
	 */
	public function onToken(callable $callback): void {
		$this->tokenCallbacks[] = $callback;
	}//end onToken()

	/**
	 * Register a callback invoked once per tool invocation when the LLM
	 * signals `finish_reason=tool_calls`.
	 *
	 * @param callable $callback Function receiving the assembled tool-call
	 *                           payload as a single associative-array argument.
	 *
	 * @return void
	 *
	 * @spec exclude Pure pub-sub forwarder plumbing — registers a callback; carries no business logic.
	 */
	public function onToolCall(callable $callback): void {
		$this->toolCallCallbacks[] = $callback;
	}//end onToolCall()

	/**
	 * Register a callback invoked once per tool result after the tool loop
	 * returns for the matching tool call.
	 *
	 * @param callable $callback Function receiving the tool-result payload
	 *                           as a single associative-array argument.
	 *
	 * @return void
	 *
	 * @spec exclude Pure pub-sub forwarder plumbing — registers a callback; carries no business logic.
	 */
	public function onToolResult(callable $callback): void {
		$this->toolResultCallbacks[] = $callback;
	}//end onToolResult()

	/**
	 * Emit a token delta to every registered token callback in registration
	 * order.
	 *
	 * @param string $delta New token delta from the LLM stream.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/agent-guardrails/spec.md#requirement-output-is-filtered-before-persistence-and-before-delivery
	 */
	public function emitToken(string $delta): void {
		if ($this->holdingTokens === true) {
			$this->heldTokens[] = $delta;
			return;
		}

		$this->forwardToken(delta: $delta);
	}//end emitToken()

	/**
	 * Keep every later token delta back until `releaseTokens()`.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/agent-guardrails/spec.md#requirement-output-is-filtered-before-persistence-and-before-delivery
	 */
	public function holdTokens(): void {
		$this->holdingTokens = true;
	}//end holdTokens()

	/**
	 * Stop holding and forward the reviewed text in place of the held deltas.
	 *
	 * When the reviewed text equals the held deltas joined, the deltas are
	 * forwarded as they arrived; otherwise the reviewed text is forwarded as
	 * one delta. Nothing is forwarded when no delta was held, so a provider
	 * that did not stream still sends no token frame. A no-op when not holding.
	 *
	 * @param string $reviewed The answer text the output guardrail let through.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/agent-guardrails/spec.md#requirement-output-is-filtered-before-persistence-and-before-delivery
	 */
	public function releaseTokens(string $reviewed): void {
		$held = $this->heldTokens;
		$this->holdingTokens = false;
		$this->heldTokens = [];

		if ($held === []) {
			return;
		}

		if (implode('', $held) !== $reviewed) {
			$held = [$reviewed];
		}

		foreach ($held as $delta) {
			$this->forwardToken(delta: $delta);
		}
	}//end releaseTokens()

	/**
	 * Hand one token delta to every registered token callback.
	 *
	 * @param string $delta The token delta.
	 *
	 * @return void
	 */
	private function forwardToken(string $delta): void {
		foreach ($this->tokenCallbacks as $callback) {
			$callback($delta);
		}
	}//end forwardToken()

	/**
	 * Emit one assembled tool-call payload to every registered tool-call
	 * callback in registration order.
	 *
	 * @param array<string, mixed> $payload Tool-call payload (`toolId`,
	 *                                      `arguments`).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agent-engine-port/tasks.md#task-1-1
	 */
	public function emitToolCall(array $payload): void {
		$payload = $this->screenToolDetail(payload: $payload, key: 'arguments');
		foreach ($this->toolCallCallbacks as $callback) {
			$callback($payload);
		}
	}//end emitToolCall()

	/**
	 * Emit one tool-result payload to every registered tool-result callback
	 * in registration order.
	 *
	 * @param array<string, mixed> $payload Tool-result payload (`toolId`,
	 *                                      `result`, `isError`).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agent-engine-port/tasks.md#task-1-1
	 */
	public function emitToolResult(array $payload): void {
		$payload = $this->screenToolDetail(payload: $payload, key: 'result');
		foreach ($this->toolResultCallbacks as $callback) {
			$callback($payload);
		}
	}//end emitToolResult()

	/**
	 * Screen what tool-call and tool-result frames show from now on.
	 *
	 * The screen receives a frame's `arguments` or `result` and returns what the
	 * callbacks see instead. The payload is a copy, so the tool still runs with
	 * its own arguments and the model still reads the real result.
	 *
	 * @param callable $screen Function from the value to the value to show.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/output-filter-tool-details/specs/agent-guardrails/spec.md#requirement-output-is-filtered-before-persistence-and-before-delivery
	 */
	public function screenToolDetails(callable $screen): void {
		$this->toolDetailScreen = $screen(...);
	}//end screenToolDetails()

	/**
	 * The payload with its `$key` entry passed through the tool detail screen.
	 *
	 * @param array<string, mixed> $payload The frame payload.
	 * @param string               $key     `arguments` or `result`.
	 *
	 * @return array<string, mixed>
	 */
	private function screenToolDetail(array $payload, string $key): array {
		if ($this->toolDetailScreen === null || array_key_exists($key, $payload) === false) {
			return $payload;
		}

		$payload[$key] = ($this->toolDetailScreen)($payload[$key]);

		return $payload;
	}//end screenToolDetail()

	/*
	 * NO HEARTBEAT PUB/SUB HERE, AND THERE CANNOT BE ONE.
	 *
	 * This class used to carry an `onHeartbeat()` / `emitHeartbeat()` pair.
	 * `ChatStreamController` registered the consumer half; nothing ever
	 * called the producer half, and nothing could:
	 *
	 *   - the only silences long enough to need a keepalive are INSIDE a
	 *     blocking call — LLPhant's `generateChat`, the provider HTTP
	 *     round-trip, or a slow tool in `FacadeToolInvoker` — and PHP runs
	 *     no code of ours during those;
	 *   - every moment the producer side DOES hold control is a moment it is
	 *     already emitting a token/tool_call/tool_result frame, which the
	 *     controller's `forwardWithHeartbeat()` already interleaves a
	 *     heartbeat ahead of.
	 *
	 * The live keepalive is therefore the controller's own: the heartbeat
	 * frame emitted right after the SSE headers, plus the wall-clock
	 * interleave in `forwardWithHeartbeat()`.
	 *
	 * OpenRegister's `Service\Chat\StreamYieldChannel`, the class this one
	 * was ported from, removed the same pair for the same reason.
	 */

}//end class

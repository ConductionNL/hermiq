<?php

/**
 * Hermiq Chat Stream Yield Channel.
 *
 * Plain value object that forwards token / tool-call / tool-result events
 * from the response-generation handler to a consuming SSE controller during
 * a streaming LLM call. Heartbeats are not one of them — see the note at the
 * foot of the class.
 *
 * The channel is pure forwarding: it does not buffer, format, or filter
 * events. Buffering of partial tool-call frames lives in
 * `ResponseGenerationHandler`; SSE framing + heartbeat interleaving lives in
 * the (later-chunk) stream controller. Multiple callbacks per event type are
 * allowed (future-proofs for telemetry / logging interceptors). Late
 * registration after a prior emit is allowed; the new callback only sees
 * subsequent events (no replay).
 *
 * Ported verbatim from `OCA\OpenRegister\Service\Chat\StreamYieldChannel` — pure
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
	 * @spec exclude Pure pub-sub forwarder plumbing — loops registered callbacks; carries no business logic.
	 */
	public function emitToken(string $delta): void {
		foreach ($this->tokenCallbacks as $callback) {
			$callback($delta);
		}
	}//end emitToken()

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
		foreach ($this->toolResultCallbacks as $callback) {
			$callback($payload);
		}
	}//end emitToolResult()

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

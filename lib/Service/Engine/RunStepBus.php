<?php

/**
 * Cross-request step bus: makes an agent's tool calls visible in the chat.
 *
 * ## Why this exists
 *
 * The chat UI can already display an agent's steps. `ChatStreamController`
 * emits `tool_call` / `tool_result` SSE events from `StreamYieldChannel`,
 * `useAiChatStream` folds them into `state.toolCalls`, and `CnAiMessageList`
 * renders each with expand/collapse. That whole chain works — for tools the
 * engine invokes IN PROCESS.
 *
 * A governed `executionMode: cli` turn does not invoke tools in process. The
 * CLI calls Hermiq's MCP endpoint, so every tool runs inside a SEPARATE HTTP
 * request (`McpRunController`) that has no reference to the turn's channel.
 * Nothing was broken at either end; the transport simply bypassed the channel
 * the UI was listening to, and a turn that made five tool calls appeared to the
 * user as one silent minute.
 *
 * This bus is the correlation the two halves were missing. The per-run bearer
 * token already binds `(runId, agentId, userId, conversationId)`, so the MCP
 * request knows which conversation it belongs to and can append its step where
 * that conversation's turn will look for it.
 *
 * ## Why a cache and not the database
 *
 * These records live for the length of one turn and are read exactly once. A
 * distributed cache is TTL-native and needs no migration, no cleanup job and no
 * schema. Steps are display material, not an audit trail — the audit trail is
 * the run's own, written elsewhere and unaffected by this.
 *
 * ⚠️ Best-effort BY DESIGN. Every method swallows its own failures: a step that
 * cannot be recorded must never fail the tool call that produced it, and a
 * bucket that cannot be read must never fail the turn. Losing a display step is
 * a cosmetic loss; losing the tool call is not.
 *
 * @category  Service
 * @package   OCA\Hermiq\Service\Engine
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Engine;

use OCP\ICache;
use OCP\ICacheFactory;
use Throwable;

/**
 * Records tool steps from the MCP request and drains them into the turn.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Engine
 * @author   Conduction B.V. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://conduction.nl
 */
class RunStepBus {

	/**
	 * How long a bucket survives unread, in seconds.
	 *
	 * Comfortably longer than the CLI dispatch budget (300s) so a slow turn
	 * still finds its own steps, and short enough that an abandoned turn's
	 * records disappear on their own.
	 *
	 * @var int
	 */
	private const TTL_SECONDS = 600;

	/**
	 * Most steps kept per conversation.
	 *
	 * A runaway tool loop must not grow this without bound; the chat cannot
	 * usefully display hundreds of steps anyway.
	 *
	 * @var int
	 */
	private const MAX_STEPS = 50;

	/**
	 * Longest tool result kept, in characters.
	 *
	 * The panel shows the result behind an expander, so a whole document or a
	 * large search payload is neither displayable nor worth caching.
	 *
	 * @var int
	 */
	private const MAX_RESULT_CHARS = 600;

	/**
	 * A tool call that completed.
	 *
	 * @var string
	 */
	public const OUTCOME_OK = 'ok';

	/**
	 * A tool call that did not complete.
	 *
	 * @var string
	 */
	public const OUTCOME_ERROR = 'error';

	/**
	 * The distributed cache, or null when none is configured.
	 *
	 * @var ICache|null
	 */
	private ?ICache $cache;

	/**
	 * Constructor.
	 *
	 * @param ICacheFactory $cacheFactory Creates the distributed cache.
	 */
	public function __construct(ICacheFactory $cacheFactory) {
		$this->cache = null;
		try {
			$this->cache = $cacheFactory->createDistributed('hermiq_run_steps');
		} catch (Throwable $e) {
			// No cache configured: the bus becomes a no-op and the chat simply
			// shows no steps, exactly as before this existed.
			$this->cache = null;
		}

	}//end __construct()

	/**
	 * Record one completed tool call against its conversation.
	 *
	 * @param string $conversationId The conversation the run belongs to.
	 * @param string $toolName       The tool that ran.
	 * @param array  $arguments      The arguments it ran with.
	 * @param string $result         The raw result payload.
	 * @param int    $durationMs     How long it took.
	 * @param string $outcome        `ok` or `error`, stored verbatim.
	 *
	 * ⚠️ `$outcome` is the STORED VALUE, not a boolean the method converts. It
	 * was a `bool $ok` that was mapped to exactly these two strings one line
	 * later — a flag argument whose only job was to pick between two constants
	 * the caller could simply name. The stored vocabulary is now the same at the
	 * call site, in this signature, and in the cache.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/warm-start-and-cli-step-visibility/specs/warm-start-and-cli-step-visibility/spec.md#requirement-a-cli-turns-tool-calls-are-visible-in-the-chat
	 */
	public function record(
		string $conversationId,
		string $toolName,
		array $arguments,
		string $result,
		int $durationMs,
		string $outcome = self::OUTCOME_OK,
	): void {
		if ($this->cache === null || $conversationId === '') {
			return;
		}

		try {
			$steps = $this->read(conversationId: $conversationId);
			if (count($steps) >= self::MAX_STEPS) {
				return;
			}

			// `agentId` is stamped in by the transport, not asked for by the
			// model, so showing it back would be noise in the UI.
			unset($arguments['agentId']);

			$steps[] = [
				'toolId' => $toolName . '-' . count($steps),
				'name' => $toolName,
				'arguments' => $arguments,
				'result' => mb_substr($result, 0, self::MAX_RESULT_CHARS),
				'truncated' => (mb_strlen($result) > self::MAX_RESULT_CHARS),
				'durationMs' => $durationMs,
				'outcome' => $outcome,
			];

			$this->cache->set($this->key(conversationId: $conversationId), json_encode($steps), self::TTL_SECONDS);
		} catch (Throwable $e) {
			// Display material only — never let this break the tool call.
			return;
		}

	}//end record()

	/**
	 * Read the steps recorded for a conversation, without clearing them.
	 *
	 * @param string $conversationId The conversation.
	 *
	 * @return array<int, array<string, mixed>> The recorded steps, oldest first.
	 *
	 * @spec openspec/changes/warm-start-and-cli-step-visibility/specs/warm-start-and-cli-step-visibility/spec.md#requirement-a-cli-turns-tool-calls-are-visible-in-the-chat
	 */
	public function read(string $conversationId): array {
		if ($this->cache === null || $conversationId === '') {
			return [];
		}

		try {
			$raw = $this->cache->get($this->key(conversationId: $conversationId));
			if (is_string($raw) === false || $raw === '') {
				return [];
			}

			$decoded = json_decode($raw, true);
			if (is_array($decoded) === false) {
				return [];
			}

			return $decoded;
		} catch (Throwable $e) {
			return [];
		}

	}//end read()

	/**
	 * Read the steps and clear the bucket, so the next turn starts empty.
	 *
	 * Draining rather than reading is what keeps one turn's steps from being
	 * shown again on the next one.
	 *
	 * @param string $conversationId The conversation.
	 *
	 * @return array<int, array<string, mixed>> The recorded steps, oldest first.
	 *
	 * @spec openspec/changes/warm-start-and-cli-step-visibility/specs/warm-start-and-cli-step-visibility/spec.md#scenario-steps-do-not-outlive-their-turn
	 */
	public function drain(string $conversationId): array {
		$steps = $this->read(conversationId: $conversationId);
		$this->clear(conversationId: $conversationId);

		return $steps;

	}//end drain()

	/**
	 * Drop any steps recorded for a conversation.
	 *
	 * @param string $conversationId The conversation.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/warm-start-and-cli-step-visibility/specs/warm-start-and-cli-step-visibility/spec.md#scenario-steps-do-not-outlive-their-turn
	 */
	public function clear(string $conversationId): void {
		if ($this->cache === null || $conversationId === '') {
			return;
		}

		try {
			$this->cache->remove($this->key(conversationId: $conversationId));
		} catch (Throwable $e) {
			return;
		}

	}//end clear()

	/**
	 * The cache key for a conversation's bucket.
	 *
	 * Hashed so no conversation identifier reaches a cache-key listing.
	 *
	 * @param string $conversationId The conversation.
	 *
	 * @return string The key.
	 */
	private function key(string $conversationId): string {
		return hash('sha256', $conversationId);

	}//end key()
}//end class

<?php

/**
 * Contract of Engine
 *
 * The public surface of Engine that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Hermiq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/hermiq
 *
 * @spec exclude Structural contract, no functional requirement.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Hermiq\Kernel\Contracts;

use OCA\Hermiq\Service\Engine\RunTraceCollector;
use OCA\Hermiq\Service\Engine\StreamYieldChannel;

/**
 * Contract of Engine.
 *
 * @category Kernel
 * @package  OCA\Hermiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/hermiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface EngineInterface {

	/**
	 * Ensure a conversation title is unique for a user-agent combination.
	 *
	 * Delegates to ConversationManagementHandler.
	 *
	 * @param string $baseTitle Base title.
	 * @param string $userId User id.
	 * @param string $agentId Agent UUID.
	 *
	 * @return string Unique title.
	 *
	 * @spec openspec/changes/archive/2026-09-06-agent-engine-port/tasks.md#1-port-the-chat-engine
	 */
	public function ensureUniqueTitle(string $baseTitle, string $userId, string $agentId): string;

	/**
	 * Process a chat message and generate the AI response.
	 *
	 * Main orchestration method that coordinates all handlers. See the class
	 * docblock for the binding signature/return-shape contract.
	 *
	 * @param string $conversationId Conversation object UUID.
	 * @param string $userId User id (must own the conversation).
	 * @param string $userMessage User message text.
	 * @param array $selectedViews View filters for multitenancy (optional).
	 * @param array $selectedTools Tool registry ids to use (optional).
	 * @param array $ragSettings RAG configuration overrides (optional).
	 * @param array $context AI Chat Companion context snapshot the
	 *                       frontend sent. Persisted on the
	 *                       user-authored Message when non-empty.
	 * @param StreamYieldChannel|null $channel Streaming channel forwarded to the response
	 *                                         handler so SSE consumers can interleave
	 *                                         `token`/`tool_call`/`tool_result` frames as
	 *                                         the LLM yields. Null for blocking callers.
	 * @param RunTraceCollector|null $trace Optional run-trace collector
	 *                                      (run-trace-observability);
	 *                                      when supplied,
	 *                                      context/history/llm/tool
	 *                                      steps are timed into it and
	 *                                      returned as the envelope's
	 *                                      `steps` key. Null for
	 *                                      callers that do not need a
	 *                                      step timeline (zero behavior
	 *                                      change).
	 * @param bool $dryRun Whether this turn is a dry-run preview
	 *                     (run-replay-and-dry-run); threaded
	 *                     onto
	 *                     `ResponseGenerationHandler::generateResponse()`
	 *                     so a side-effecting tool call is
	 *                     neutralised instead of actually
	 *                     invoked. False (every pre-existing
	 *                     caller) is byte-for-byte unchanged
	 *                     behavior.
	 * @param array|null $skillSetOverride Per-run effective-skill-set override
	 *                                     (skill uuids) for the run-loop
	 *                                     skill-exposure seam (skill-evals): a
	 *                                     paired eval half varies exactly this
	 *                                     set. Null (every non-eval caller)
	 *                                     exposes the agent's stored
	 *                                     `skillInstalls`.
	 * @param string|null $authorId Uid of the human who produced this turn,
	 *                              persisted on the user-authored Message
	 *                              so a shared session records who spoke
	 *                              (talk-shared-sessions). Null for
	 *                              single-speaker callers — byte-for-byte
	 *                              unchanged behavior.
	 * @param string|null $authorDisplayName That human's display name AT SEND TIME,
	 *                                       captured deliberately and never re-resolved
	 *                                       so a transcript stays legible after a
	 *                                       rename or a deleted account (ADR-004).
	 * @param array $attachments The files the turn names, as the request sent them
	 *                           ({fileId} or {path}). Resolved in the speaker's Files
	 *                           before the turn is stored or any model is called, and
	 *                           kept on the user turn as references only.
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
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) Each parameter is a distinct, independently
	 *   optional input (run-trace-observability adds one more to an already-wide, long-established
	 *   list) — grouping them would obscure, not simplify, the call site.
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)    Dry-run preview (run-replay-and-dry-run)
	 *   is a cross-cutting mode threaded through the engine as a flag by design.
	 *
	 * @spec openspec/changes/archive/2026-09-06-agent-engine-port/tasks.md#1-port-the-chat-engine
	 * @spec openspec/changes/archive/2026-09-06-agent-engine-port/tasks.md#1-port-the-chat-engine
	 * @spec openspec/changes/archive/2026-07-12-run-trace-observability/tasks.md#task-2-thread-the-collector-through-engine-toolloop-facadetoolinvoker
	 * @spec openspec/changes/archive/2026-07-13-run-replay-and-dry-run/tasks.md#task-3-thread-dryrun-through-toolloop-engine-and-responsegenerationhandler
	 * @spec openspec/specs/agent-evals/spec.md#requirement-the-engine-run-loop-exposes-the-effective-skill-set-to-a-run
	 * @spec openspec/changes/chat-attachments-and-images/specs/chat-attachments/spec.md#requirement-an-attachment-is-read-as-the-person-who-sent-it-req-catt-003
	 * @spec openspec/specs/agent-engine-port/spec.md#requirement-the-model-receives-the-users-turn-once
	 */
	public function processMessage(
		string $conversationId,
		string $userId,
		string $userMessage,
		array $selectedViews = [],
		array $selectedTools = [],
		array $ragSettings = [],
		array $context = [],
		?StreamYieldChannel $channel = null,
		?RunTraceCollector $trace = null,
		bool $dryRun = false,
		?array $skillSetOverride = null,
		?string $authorId = null,
		?string $authorDisplayName = null,
		array $attachments = [],
	): array;
}//end interface

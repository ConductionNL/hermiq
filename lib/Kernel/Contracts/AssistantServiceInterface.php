<?php

/**
 * Contract of AssistantService
 *
 * The public surface of AssistantService that other components call, so they depend
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

use Exception;
use OCA\Hermiq\Service\GuardrailBlockedException;

/**
 * Contract of AssistantService.
 *
 * @category Kernel
 * @package  OCA\Hermiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/hermiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface AssistantServiceInterface {

	/**
	 * Run one conversational turn.
	 *
	 * @param string $userId Authenticated caller (must own `$sessionId`, when given).
	 * @param string|null $sessionId Existing conversation UUID, or null to start a new one.
	 * @param string $message The user's message text.
	 * @param array $context `{app, objectType?, objectRef?, contextData?}` — `app` is
	 *                       required so a new conversation's dedicated Agent can be
	 *                       provisioned/found.
	 *
	 * @return array{sessionId: string, reply: string, usage: array<string, int|float>}
	 *
	 * @throws Exception On validation failure (code 400), unknown session (404), foreign
	 *                   session (403), or a downstream LLM/provider failure.
	 * @throws GuardrailBlockedException When the input is refused by the effective GuardrailPolicy.
	 *
	 * @spec openspec/changes/archive/2026-09-06-case-assistant-surface/tasks.md#task-1-1
	 * @spec openspec/specs/agent-engine-port/spec.md#requirement-the-model-receives-the-users-turn-once
	 */
	public function converse(string $userId, ?string $sessionId, string $message, array $context): array;

	/**
	 * Run one stateless, structured PII/redaction-span detection call.
	 *
	 * Deliberately does NOT persist a `Conversation`/`Message` (design.md
	 * Decision 2) and deliberately bypasses the effective `GuardrailPolicy`'s
	 * PII input-redaction action while keeping prompt-injection filtering
	 * active (design.md Decision 1) — the caller's document text must reach
	 * the model unredacted for detection to work at all.
	 *
	 * @param string $userId Authenticated caller (only used for logging — no
	 *                       per-user state is created by this call).
	 * @param string $text The document text to scan (already length-capped
	 *                     by the caller's own bound, further capped here).
	 * @param array $context `{app, objectType?, objectRef?}` — `app` is
	 *                       required so a dedicated detector Agent can be
	 *                       provisioned/found.
	 *
	 * @return array{spans: array<int, array<string, mixed>>, usage: array<string, int|float>}
	 *
	 * @throws Exception On validation failure (code 400) or malformed model
	 *                   output (code 502).
	 * @throws GuardrailBlockedException When the input is refused by the
	 *                                   effective GuardrailPolicy's
	 *                                   prompt-injection filter.
	 *
	 * @spec openspec/changes/archive/2026-07-16-woo-llm-anonymisation/tasks.md#task-1-2
	 */
	public function detectPii(string $userId, string $text, array $context): array;
}//end interface

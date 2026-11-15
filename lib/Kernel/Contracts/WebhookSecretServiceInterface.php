<?php

/**
 * Contract of WebhookSecretService
 *
 * The public surface of WebhookSecretService that other components call, so they depend
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

use OCA\OpenRegister\Db\ObjectEntity;
use RuntimeException;

/**
 * Contract of WebhookSecretService.
 *
 * @category Kernel
 * @package  OCA\Hermiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/hermiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface WebhookSecretServiceInterface {

	/**
	 * Create a new webhook secret for an agent that has none.
	 *
	 * @param string $agentId The agent UUID.
	 * @param string $owner The agent's owner UID (impersonated around the save,
	 *                      exactly like `ApprovalService::persistApproval()`).
	 *
	 * @return array{secret:string, object:ObjectEntity} The plaintext secret (shown
	 *                                                   once) and the persisted object.
	 *
	 * @throws RuntimeException When a webhook already exists for this agent (409 — use rotate()).
	 *
	 * @spec openspec/changes/archive/2026-07-12-agent-webhook-trigger/tasks.md#task-1-agentwebhook-schema-secret-lifecycle-service
	 */
	public function create(string $agentId, string $owner): array;

	/**
	 * Find the (at most one) AgentWebhook configured for an agent, system-wide
	 * (no RBAC/multitenancy) — callers apply their own guard: the management
	 * controller checks agent ownership before calling in, and the public
	 * trigger endpoint has no session to scope by.
	 *
	 * @param string $agentId The agent UUID.
	 *
	 * @return ObjectEntity|null The AgentWebhook object, or null when none exists.
	 *
	 * @spec openspec/changes/archive/2026-07-12-agent-webhook-trigger/tasks.md#task-1-agentwebhook-schema-secret-lifecycle-service
	 */
	public function findForAgent(string $agentId): ?ObjectEntity;

	/**
	 * Best-effort, non-fatal bookkeeping: record that a valid, enabled webhook
	 * just accepted a request. Never throws — a failure here must not affect
	 * the trigger response.
	 *
	 * @param ObjectEntity $webhook The verified AgentWebhook object.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/agent-webhook-trigger/spec.md#requirement-the-public-trigger-endpoint-authenticates-by-secret-not-session
	 */
	public function markUsed(ObjectEntity $webhook): void;

	/**
	 * Update only the approval-gate fields (requiresApproval/reviewer/reviewerType) —
	 * mirrors Schedule's identical fields. Never touches the secret.
	 *
	 * @param ObjectEntity $webhook The existing AgentWebhook object.
	 * @param array<string, mixed> $fields The fields to update (requiresApproval/reviewer/reviewerType).
	 *
	 * @return ObjectEntity The persisted, updated object.
	 *
	 * @spec openspec/changes/archive/2026-07-12-agent-webhook-trigger/tasks.md#task-2-agentwebhookcontroller-session-authenticated-owner-guarded-crud
	 */
	public function patch(ObjectEntity $webhook, array $fields): ObjectEntity;

	/**
	 * Revoke a webhook: disable it without deleting its configuration.
	 *
	 * @param ObjectEntity $webhook The existing AgentWebhook object.
	 *
	 * @return ObjectEntity The persisted, now-disabled object.
	 *
	 * @spec openspec/changes/archive/2026-07-12-agent-webhook-trigger/tasks.md#task-1-agentwebhook-schema-secret-lifecycle-service
	 */
	public function revoke(ObjectEntity $webhook): ObjectEntity;

	/**
	 * Rotate an existing webhook's secret — the previous secret's hash no
	 * longer verifies immediately (no rotation grace window).
	 *
	 * @param ObjectEntity $webhook The existing AgentWebhook object.
	 *
	 * @return array{secret:string, object:ObjectEntity} The new plaintext secret
	 *                                                   (shown once) and the persisted object.
	 *
	 * @spec openspec/changes/archive/2026-07-12-agent-webhook-trigger/tasks.md#task-1-agentwebhook-schema-secret-lifecycle-service
	 */
	public function rotate(ObjectEntity $webhook): array;

	/**
	 * Shape an AgentWebhook object (or its absence) into the status payload the
	 * management GET endpoint and the AgentDetail panel consume. NEVER includes
	 * secretHash or the plaintext secret.
	 *
	 * @param ObjectEntity|null $webhook The AgentWebhook object, or null when unconfigured.
	 *
	 * @return array<string, mixed> The status payload.
	 *
	 * @spec openspec/changes/archive/2026-07-12-agent-webhook-trigger/tasks.md#task-2-agentwebhookcontroller-session-authenticated-owner-guarded-crud
	 */
	public function status(?ObjectEntity $webhook): array;

	/**
	 * Verify a trigger request's secret and return the matched, enabled
	 * AgentWebhook — the SINGLE enumeration-safe auth entry point (design.md
	 * Decision 6). ALWAYS computes a hash_equals() comparison, even when no
	 * AgentWebhook exists for the given agent id (against a fixed, process-local
	 * dummy hash), so "no such webhook", "disabled", and "wrong secret" are all
	 * code-path-identical — never a shortcut that skips hash_equals() entirely.
	 *
	 * @param string $agentId The agent UUID from the trigger URL.
	 * @param string $providedSecret The secret from the X-Hermiq-Webhook-Secret header.
	 *
	 * @return ObjectEntity|null The matched, enabled AgentWebhook, or null on ANY
	 *                           auth-failure mode (unknown agent / no webhook /
	 *                           disabled / wrong secret).
	 *
	 * @spec openspec/changes/archive/2026-07-12-agent-webhook-trigger/tasks.md#task-3-webhooktriggercontroller-public-secret-authenticated-enumeration-safe
	 */
	public function verifyAndLoad(string $agentId, string $providedSecret): ?ObjectEntity;
}//end interface

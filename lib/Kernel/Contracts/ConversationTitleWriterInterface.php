<?php

/**
 * Contract of ConversationTitleWriter
 *
 * The public surface of ConversationTitleWriter that other components call, so they depend
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

/**
 * Contract of ConversationTitleWriter.
 *
 * @category Kernel
 * @package  OCA\Hermiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/hermiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ConversationTitleWriterInterface {

	/**
	 * Generate and persist a conversation's title.
	 *
	 * Re-reads the conversation rather than trusting a payload snapshot: this runs
	 * after the reply, so the object may have moved on, and `IJobList` arguments are
	 * a JSON round-trip. The re-read also makes the "already titled" test authoritative
	 * at write time, so a retried or duplicated job cannot rename a conversation the
	 * user has since titled themselves.
	 *
	 * Never throws: a missing title is cosmetic, and this runs detached from the reply
	 * that already succeeded. Failing loudly here would turn a naming hiccup into a
	 * red job with nothing to retry usefully.
	 *
	 * Runs AS the conversation's owner. A background job has no session, and both
	 * things this needs are identity-bound: OpenRegister RBAC refuses an `update` from
	 * `Anonymous`, and the credential broker refuses to resolve a provider credential
	 * for an unauthenticated principal. Without impersonation the deferred title
	 * silently degraded to a fallback string that RBAC then refused to persist — the
	 * work happened, cost a job, and changed nothing. The owner is impersonated rather
	 * than RBAC being elevated (`runAsSystem()`) because naming a conversation from a
	 * user's own message is that user's write, not a system write.
	 *
	 * @param string $conversationId The conversation UUID.
	 * @param string $userMessage The first user message to name it from.
	 * @param string $userId The conversation owner's UID, impersonated for the
	 *                       read, the generation and the write.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/agent-engine-port/spec.md#requirement-the-deferred-title-write-preserves-the-whole-conversation-object
	 */
	public function write(string $conversationId, string $userMessage, string $userId): void;
}//end interface

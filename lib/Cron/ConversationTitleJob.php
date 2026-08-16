<?php

/**
 * Hermiq ConversationTitleJob.
 *
 * One-shot QueuedJob that names a conversation from its first user message.
 * Enqueued by `Engine::processMessage()` via `IJobList::add()` so the user's
 * reply never waits on it (session-context-performance).
 *
 * Why it is a job at all: naming a conversation is a second LLM round trip. On
 * the `cli` transport that is a second `claude` process — ~20s of a ~65s wall,
 * paid on the FIRST message of every conversation, for a string the user has not
 * asked for and is not reading yet. Nothing downstream of the reply depends on
 * the title, so it has no business on the reply's critical path.
 *
 * The title is deferred, never dropped: `ChatStreamController` already writes a
 * "New conversation" placeholder at creation, so a conversation is always
 * readable, and this job replaces the placeholder when it runs. A conversation
 * still bearing the placeholder is a pending title, not a failure.
 *
 * @category Cron
 * @package  OCA\Hermiq\Cron
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/agent-engine-port/spec.md#requirement-conversation-title-generation-does-not-block-the-reply
 */

declare(strict_types=1);

namespace OCA\Hermiq\Cron;

use OCA\Hermiq\Service\Engine\ConversationTitleWriter;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;

/**
 * Names a conversation off the reply path.
 *
 * A pure wrapper (ADR-002): every decision lives in ConversationTitleWriter, so
 * the same code path is exercised by a test without a job runner.
 *
 * @spec openspec/specs/agent-engine-port/spec.md#requirement-conversation-title-generation-does-not-block-the-reply
 */
class ConversationTitleJob extends QueuedJob {
	/**
	 * Constructor.
	 *
	 * @param ITimeFactory $time Time factory for the base job.
	 * @param ConversationTitleWriter $writer Generates and persists the title.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly ConversationTitleWriter $writer,
	) {
		parent::__construct(time: $time);

	}//end __construct()

	/**
	 * Run the job: generate and persist the conversation's title.
	 *
	 * @param mixed $argument `['conversationId' => string, 'userMessage' => string,
	 *                        'userId' => string]`. Defensively re-checked: `IJobList`
	 *                        argument storage is a JSON round-trip, not a compile-time-
	 *                        guaranteed shape. `userId` is the owner this runs as — a job
	 *                        has no session, and both the write (OpenRegister RBAC) and the
	 *                        credential broker refuse an anonymous principal.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/agent-engine-port/spec.md#requirement-conversation-title-generation-does-not-block-the-reply
	 */
	protected function run($argument): void {
		$payload = $argument;
		if (is_array($payload) === false) {
			return;
		}

		$conversationId = (string)($payload['conversationId'] ?? '');
		$userMessage = (string)($payload['userMessage'] ?? '');
		$userId = (string)($payload['userId'] ?? '');
		if ($conversationId === '' || $userMessage === '' || $userId === '') {
			return;
		}

		$this->writer->write(
			conversationId: $conversationId,
			userMessage: $userMessage,
			userId: $userId
		);

	}//end run()
}//end class

<?php

/**
 * Contract of MessageHistoryHandler
 *
 * The public surface of MessageHistoryHandler that other components call, so they depend
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

/**
 * Contract of MessageHistoryHandler.
 *
 * @category Kernel
 * @package  OCA\Hermiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/hermiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface MessageHistoryHandlerInterface {

	/**
	 * Store a message as a `Message` OR object.
	 *
	 * @param string $conversationId Conversation UUID.
	 * @param string $role Message role (system|user|assistant|tool).
	 * @param string $content Message content.
	 * @param array|null $sources Optional RAG sources.
	 * @param array|null $context Optional AI Chat Companion context snapshot.
	 * @param string|null $authorId Uid of the human who produced this turn — `role=user` only.
	 * @param string|null $authorDisplayName That human's display name AT SEND TIME (talk-shared-sessions).
	 * @param array $attachments The turn's attachments as references (fileId, name, mimeType,
	 *                           size, origin), resolved as the speaker; never bytes or text.
	 * @param array|null $provenance How an assistant answer came about: model, sources and step
	 *                               outcomes, never content (chat-how-an-answer-came-about).
	 *
	 * @return ObjectEntity The persisted Message object.
	 *
	 * @spec openspec/changes/archive/2026-09-06-agent-engine-port/tasks.md#task-1-1
	 * @spec openspec/changes/archive/2026-09-06-agent-engine-port/tasks.md#task-1-2
	 * @spec openspec/specs/talk-shared-sessions/spec.md#requirement-each-human-turn-records-its-author
	 * @spec openspec/changes/chat-attachments-and-images/specs/chat-attachments/spec.md#requirement-an-attachment-is-read-as-the-person-who-sent-it-req-catt-003
	 * @spec openspec/changes/chat-how-an-answer-came-about/specs/answer-transparency/spec.md#requirement-every-answer-records-how-it-came-about-req-atrn-001
	 */
	public function storeMessage(
		string $conversationId,
		string $role,
		string $content,
		?array $sources = null,
		?array $context = null,
		?string $authorId = null,
		?string $authorDisplayName = null,
		array $attachments = [],
		?array $provenance = null,
	): ObjectEntity;
}//end interface

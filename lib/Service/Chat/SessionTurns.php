<?php

/**
 * Hermiq SessionTurns.
 *
 * The session side of a chat action that answers outside the agent loop (create
 * an image, translate a recording): whether the person may add to the session,
 * and storing their request and the answer as two turns.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Chat
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
 * @spec openspec/changes/chat-translate-a-recording/specs/speech-services/spec.md#requirement-the-languages-and-engines-used-are-recorded-req-strn-002
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Chat;

use OCA\Hermiq\Kernel\Contracts\MessageHistoryHandlerInterface;
use OCA\Hermiq\Service\Talk\ConversationParticipation;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;

/**
 * Guards a chat session and stores a request and its answer as two turns.
 *
 * @spec openspec/changes/chat-translate-a-recording/specs/speech-services/spec.md#requirement-the-languages-and-engines-used-are-recorded-req-strn-002
 */
class SessionTurns {

	/**
	 * Constructor.
	 *
	 * @param ObjectService                  $objectService Reads the session.
	 * @param MessageHistoryHandlerInterface $history       Stores the two turns.
	 * @param ConversationParticipation      $participation Owner-or-listed-participant guard.
	 * @param IL10N                          $l10n          Messages in the person's language.
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly MessageHistoryHandlerInterface $history,
		private readonly ConversationParticipation $participation,
		private readonly IL10N $l10n,
	) {
	}//end __construct()

	/**
	 * The refusal for a person who may not add to the session, or null when they may:
	 * 404 when the session does not exist, 403 when they are neither its owner nor a
	 * listed participant.
	 *
	 * @param string $sessionId The session.
	 * @param string $uid       The person.
	 *
	 * @return JSONResponse|null `{ error, code }`, or null.
	 *
	 * @spec openspec/changes/chat-translate-a-recording/specs/speech-services/spec.md#requirement-a-person-can-translate-a-recording-and-hear-the-result-req-strn-001
	 */
	public function refusalFor(string $sessionId, string $uid): ?JSONResponse {
		$session = $this->objectService->find(id: $sessionId, options: new ObjectFindOptions(register: 'hermiq', schema: 'agentsession'));
		if ($session === null) {
			return new JSONResponse(data: ['error' => $this->l10n->t('This session does not exist.'), 'code' => 'not_found'], statusCode: 404);
		}

		if ($this->participation->mayTakeTurn(conversationData: $session->getObject(), userId: $uid) === false) {
			return new JSONResponse(data: ['error' => $this->l10n->t('You cannot add to this session.'), 'code' => 'forbidden'], statusCode: 403);
		}

		return null;
	}//end refusalFor()

	/**
	 * Store the person's request and the answer as two turns, in that order.
	 *
	 * `$user` holds `content`, `authorId` and `authorDisplayName`; `$assistant` holds
	 * `content`, `attachments` and, for a translation, its `translation` record.
	 *
	 * @param string               $sessionId The session.
	 * @param array<string, mixed> $user      The request and who sent it.
	 * @param array<string, mixed> $assistant The answer and its attachments.
	 *
	 * @return array{userTurn: array<string, mixed>, assistantTurn: array<string, mixed>} The two turns as the Chat page reads them.
	 *
	 * @throws \Throwable When a turn cannot be stored; the caller answers 500.
	 *
	 * @spec openspec/changes/chat-translate-a-recording/specs/speech-services/spec.md#requirement-the-languages-and-engines-used-are-recorded-req-strn-002
	 */
	public function store(string $sessionId, array $user, array $assistant): array {
		$userTurn = $this->history->storeMessage(
			conversationId: $sessionId,
			role: 'user',
			content: $user['content'],
			authorId: $user['authorId'],
			authorDisplayName: $user['authorDisplayName']
		);
		$assistantTurn = $this->history->storeMessage(
			conversationId: $sessionId,
			role: 'assistant',
			content: $assistant['content'],
			attachments: $assistant['attachments'],
			translation: ($assistant['translation'] ?? null)
		);

		return [
			'userTurn' => ['id' => (string)$userTurn->getUuid(), 'role' => 'user', 'content' => $user['content'], 'attachments' => []],
			'assistantTurn' => ['id' => (string)$assistantTurn->getUuid(), 'role' => 'assistant'] + $assistant,
		];
	}//end store()
}//end class

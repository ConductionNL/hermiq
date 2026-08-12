<?php

/**
 * Hermiq ConversationParticipation.
 *
 * The single definition of "may this user take a turn in this conversation".
 * Permitted = the `userId` owner OR a uid listed in the conversation's
 * `participants` roster; the owner is IMPLICITLY a participant and need not
 * appear in the list, so an empty or unset roster means owner-only — exactly
 * the behaviour of every conversation that existed before talk-shared-sessions.
 *
 * Deliberately dependency-free so the identical rule can be enforced at every
 * entry point without any of them reaching for a different interpretation:
 * `ChatController` (HTTP), `Engine` (defense-in-depth, and the ONLY guard the
 * Talk bridge passes through — it reaches the engine without the controller),
 * and the bridge's own listener.
 *
 * Per ADR-023 Rule 1 this is NOT a re-implementation of object RBAC. Data
 * authorization stays OpenRegister's job; this is an action-level guard on
 * taking a turn, layered on top of it.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Talk
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
 * @spec openspec/changes/talk-chat-bridge/tasks.md#6-multi-participant-sessions
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Talk;

/**
 * Decides whether a user may take a turn in a conversation.
 *
 * @psalm-api
 *
 * @spec openspec/changes/talk-chat-bridge/specs/talk-shared-sessions/spec.md#requirement-a-session-may-be-taken-up-by-its-owner-or-a-listed-participant
 */
class ConversationParticipation {
	/**
	 * Whether the given user may take a turn in the given conversation.
	 *
	 * Never widens to "any authenticated user", and never consults live Talk
	 * room membership — the roster is explicit precisely so that "who can use
	 * this agent" cannot change silently when someone is added to a room.
	 *
	 * @param array $conversationData The conversation object payload.
	 * @param string $userId The acting user's uid.
	 *
	 * @return bool True when the user owns the conversation or is a listed participant.
	 *
	 * @spec openspec/changes/talk-chat-bridge/specs/talk-shared-sessions/spec.md#requirement-a-session-may-be-taken-up-by-its-owner-or-a-listed-participant
	 */
	public function mayTakeTurn(array $conversationData, string $userId): bool {
		if ($userId === '') {
			return false;
		}

		if (($conversationData['userId'] ?? null) === $userId) {
			return true;
		}

		return in_array($userId, $this->roster(conversationData: $conversationData), true);
	}//end mayTakeTurn()

	/**
	 * Normalise the participants roster to a list of non-empty uid strings.
	 *
	 * Tolerates a null, absent or malformed roster by returning an empty list,
	 * so a corrupt payload degrades to owner-only rather than to open access.
	 *
	 * @param array $conversationData The conversation object payload.
	 *
	 * @return string[] The listed participant uids, excluding the implicit owner.
	 *
	 * @spec openspec/changes/talk-chat-bridge/specs/talk-shared-sessions/spec.md#requirement-a-session-may-be-taken-up-by-its-owner-or-a-listed-participant
	 */
	public function roster(array $conversationData): array {
		$participants = ($conversationData['participants'] ?? []);
		if (is_array($participants) === false) {
			return [];
		}

		$uids = [];
		foreach ($participants as $participant) {
			if (is_string($participant) === true && $participant !== '') {
				$uids[] = $participant;
			}
		}

		return $uids;
	}//end roster()

	/**
	 * The full set of uids permitted to take a turn, owner first.
	 *
	 * @param array $conversationData The conversation object payload.
	 *
	 * @return string[] Owner plus listed participants, de-duplicated.
	 *
	 * @spec openspec/changes/talk-chat-bridge/specs/talk-shared-sessions/spec.md#requirement-a-session-may-be-taken-up-by-its-owner-or-a-listed-participant
	 */
	public function permittedUids(array $conversationData): array {
		$owner = ($conversationData['userId'] ?? '');
		$uids = [];
		if (is_string($owner) === true && $owner !== '') {
			$uids[] = $owner;
		}

		foreach ($this->roster(conversationData: $conversationData) as $participant) {
			if (in_array($participant, $uids, true) === false) {
				$uids[] = $participant;
			}
		}

		return $uids;
	}//end permittedUids()
}//end class

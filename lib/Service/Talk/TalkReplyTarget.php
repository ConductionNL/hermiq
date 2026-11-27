<?php

/**
 * Hermiq TalkReplyTarget
 *
 * Where and how one Talk answer is delivered: the room, the answering agent's
 * bot identity, whether this Talk can edit bot messages, and the least time
 * between two edits.
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
 * @spec openspec/changes/instant-talk/tasks.md#7-verify
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Talk;

/**
 * The room, bot identity and edit behaviour of one reply.
 *
 * @psalm-api
 *
 * @spec openspec/changes/instant-talk/specs/instant-talk/spec.md#requirement-replies-stream-progressively-where-talk-supports-message-editing
 */
final class TalkReplyTarget {

	/**
	 * Constructor.
	 *
	 * @param string      $roomToken       The room to answer in.
	 * @param string|null $agentId         The answering agent's bot identity; null for the shared bot.
	 * @param bool        $canEdit         Whether this Talk can edit bot messages.
	 * @param float       $throttleSeconds The least time between two edits.
	 *
	 * @spec openspec/changes/instant-talk/tasks.md#7-verify
	 */
	public function __construct(
		public readonly string $roomToken,
		public readonly ?string $agentId,
		public readonly bool $canEdit,
		public readonly float $throttleSeconds,
	) {
	}//end __construct()
}//end class

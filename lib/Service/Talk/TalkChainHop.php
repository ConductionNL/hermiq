<?php

/**
 * Hermiq TalkChainHop
 *
 * One step of an agent-to-agent chain: the answer that mentions the next agent,
 * the Talk message it was posted as, and the descriptor of the next hop.
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
 * @spec openspec/changes/instant-talk/specs/instant-talk/spec.md#requirement-agent-to-agent-turns-are-mention-only-bounded-and-human-rooted
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Talk;

/**
 * The answer and the next hop a chained turn is handed off with.
 *
 * @psalm-api
 *
 * @spec openspec/changes/instant-talk/specs/instant-talk/spec.md#requirement-agent-to-agent-turns-are-mention-only-bounded-and-human-rooted
 */
final class TalkChainHop {

	/**
	 * Constructor.
	 *
	 * @param string                                                  $answer    The answer, the chained turn's message.
	 * @param string                                                  $messageId The posted answer's message id, or ''.
	 * @param array{chainId: string, hop: int, rootSpeakerUid: string} $next      The next hop's descriptor.
	 *
	 * @spec openspec/changes/instant-talk/tasks.md#7-verify
	 */
	public function __construct(
		public readonly string $answer,
		public readonly string $messageId,
		public readonly array $next,
	) {
	}//end __construct()

	/**
	 * The key the chained turn's id is derived from: the posted answer's
	 * message id, else the chain id and hop number.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/instant-talk/specs/instant-talk/spec.md#requirement-agent-to-agent-turns-are-mention-only-bounded-and-human-rooted
	 */
	public function turnKey(): string {
		if ($this->messageId !== '') {
			return $this->messageId;
		}

		return $this->next['chainId'] . '-' . $this->next['hop'];
	}//end turnKey()
}//end class

<?php

/**
 * Hermiq PublicAnswer.
 *
 * What a public chat turn answers: the reply, its sources as links, the token for the rest of the visit and the disclosure.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\PublicChat
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/delivery-public-web-chat/specs/public-chat-channel/spec.md#requirement-a-visitor-chats-without-signing-in-and-gets-answers-only-from-public-content-req-pubchat-002
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\PublicChat;

/**
 * One public chat answer.
 *
 * Carries no identity: nothing in it names who asked.
 *
 * @spec openspec/changes/delivery-public-web-chat/specs/public-chat-channel/spec.md#requirement-a-visitor-chats-without-signing-in-and-gets-answers-only-from-public-content-req-pubchat-002
 */
final class PublicAnswer {

	/**
	 * Constructor.
	 *
	 * @param string                                       $answer            The reply text.
	 * @param list<array{title: string, url: string}>      $sources           The sources the reply was drawn from.
	 * @param string                                       $conversationToken The token for the next turn, '' when none was kept.
	 * @param string                                       $disclosure        The channel's disclosure text.
	 * @param bool                                         $available         False when the channel did not answer (stopped, off, or over its limit).
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) A value object's field, not a mode switch.
	 *
	 * @spec openspec/changes/delivery-public-web-chat/specs/public-chat-channel/spec.md#requirement-a-visitor-chats-without-signing-in-and-gets-answers-only-from-public-content-req-pubchat-002
	 */
	public function __construct(
		public readonly string $answer,
		public readonly array $sources,
		public readonly string $conversationToken,
		public readonly string $disclosure,
		public readonly bool $available=true,
	) {
	}//end __construct()

	/**
	 * The answer as the public endpoint and sibling apps read it.
	 *
	 * @return array{answer: string, sources: list<array{title: string, url: string}>, conversationToken: string, disclosure: string, available: bool}
	 *
	 * @spec openspec/changes/delivery-public-web-chat/specs/public-chat-channel/spec.md#requirement-a-visitor-chats-without-signing-in-and-gets-answers-only-from-public-content-req-pubchat-002
	 */
	public function toArray(): array {
		return [
			'answer'            => $this->answer,
			'sources'           => $this->sources,
			'conversationToken' => $this->conversationToken,
			'disclosure'        => $this->disclosure,
			'available'         => $this->available,
		];
	}//end toArray()
}//end class

<?php

/**
 * Hermiq PublicChatRefusedException.
 *
 * A public chat turn that was not answered, with the HTTP status and the sentence the visitor reads.
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
 * @spec openspec/changes/delivery-public-web-chat/specs/public-chat-channel/spec.md#requirement-the-public-endpoint-is-limited-and-origin-bound-req-pubchat-003
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\PublicChat;

use RuntimeException;

/**
 * A refused public chat turn.
 *
 * The message is always one a visitor may read: it never says why a channel
 * is stopped (feature off, kill switch, agent off), only that it is.
 *
 * @spec openspec/changes/delivery-public-web-chat/specs/public-chat-channel/spec.md#requirement-the-public-endpoint-is-limited-and-origin-bound-req-pubchat-003
 */
class PublicChatRefusedException extends RuntimeException {

	/**
	 * What a stopped channel answers.
	 */
	public const NOT_AVAILABLE = 'This assistant is not available right now.';

	/**
	 * What a channel over its daily limit answers.
	 */
	public const LIMIT_REACHED = 'This assistant has answered all the questions it can today. Try again tomorrow.';

	/**
	 * Constructor.
	 *
	 * @param string $message The sentence the visitor reads.
	 * @param int    $status  The HTTP status.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/delivery-public-web-chat/specs/public-chat-channel/spec.md#requirement-the-public-endpoint-is-limited-and-origin-bound-req-pubchat-003
	 */
	public function __construct(string $message=self::NOT_AVAILABLE, private readonly int $status=503) {
		parent::__construct(message: $message, code: $status);
	}//end __construct()

	/**
	 * The HTTP status.
	 *
	 * @return int
	 *
	 * @spec openspec/changes/delivery-public-web-chat/specs/public-chat-channel/spec.md#requirement-the-public-endpoint-is-limited-and-origin-bound-req-pubchat-003
	 */
	public function getStatus(): int {
		return $this->status;
	}//end getStatus()

	/**
	 * Whether this refusal means the channel is stopped, rather than the request being wrong.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/delivery-public-web-chat/specs/public-chat-channel/spec.md#requirement-the-public-endpoint-is-limited-and-origin-bound-req-pubchat-003
	 */
	public function isUnavailable(): bool {
		return in_array($this->status, [429, 503], true);
	}//end isUnavailable()
}//end class

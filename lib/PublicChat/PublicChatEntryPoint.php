<?php

/**
 * Hermiq PublicChatEntryPoint.
 *
 * The in-process entry point sibling apps call to put a question to an app
 * channel on behalf of an anonymous visitor, for example portaliq's "Ask a
 * question" widget. A sibling checks `class_exists()` and that hermiq is
 * enabled, then asks by its channel key (`app:portaliq:<portal-slug>`).
 *
 * ⚠️ A PUBLISHED CONTRACT. Sibling apps call `ask()` with named arguments, so
 * its parameter names, order, types and defaults do not change. A new need is
 * a new method, or an extra optional parameter at the end.
 *
 * @category PublicChat
 * @package  OCA\Hermiq\PublicChat
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
 * @spec openspec/changes/delivery-public-web-chat/specs/public-chat-channel/spec.md#requirement-sibling-apps-reach-the-same-channel-in-process-without-an-identity-req-pubchat-004
 */

declare(strict_types=1);

namespace OCA\Hermiq\PublicChat;

use OCA\Hermiq\Service\PublicChat\PublicAnswer;
use OCA\Hermiq\Service\PublicChat\PublicChannelStore;
use OCA\Hermiq\Service\PublicChat\PublicChatRefusedException;
use OCA\Hermiq\Service\PublicChat\PublicChatService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Ask an app channel a question, with no identity.
 *
 * The call takes no user and passes none on: the channel's turn reads only
 * what an anonymous caller may read, whoever is signed in to the request the
 * sibling app is serving. The scope the app passes only narrows the channel's
 * declared scope.
 *
 * @spec openspec/changes/delivery-public-web-chat/specs/public-chat-channel/spec.md#requirement-sibling-apps-reach-the-same-channel-in-process-without-an-identity-req-pubchat-004
 */
class PublicChatEntryPoint {

	/**
	 * Constructor.
	 *
	 * @param PublicChannelStore $store  Finds the app channel by its key.
	 * @param PublicChatService  $chat   Runs the turn.
	 * @param LoggerInterface    $logger Records a failed turn.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/delivery-public-web-chat/specs/public-chat-channel/spec.md#requirement-sibling-apps-reach-the-same-channel-in-process-without-an-identity-req-pubchat-004
	 */
	public function __construct(
		private readonly PublicChannelStore $store,
		private readonly PublicChatService $chat,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Put a visitor's question to an app channel.
	 *
	 * Never throws for a stopped channel: the answer then says "This assistant
	 * is not available right now." with `available` false, so the sibling app
	 * can show it as it is. An unknown channel key answers the same, so a key
	 * cannot be probed.
	 *
	 * @param string                                             $channelKey        The app channel's key, for example `app:portaliq:wonen`.
	 * @param string                                             $question          The visitor's question.
	 * @param string                                             $locale            The visitor's locale.
	 * @param array<int, array<string, string>>                  $sourceScope       The registers and schemas to answer from; only narrows the channel's.
	 * @param string|null                                        $conversationToken The token from the previous answer, or null for a new visit.
	 *
	 * @return PublicAnswer
	 *
	 * @spec openspec/changes/delivery-public-web-chat/specs/public-chat-channel/spec.md#requirement-sibling-apps-reach-the-same-channel-in-process-without-an-identity-req-pubchat-004
	 */
	public function ask(string $channelKey, string $question, string $locale, array $sourceScope, ?string $conversationToken): PublicAnswer {
		$channel = $this->store->channelByKey(key: $channelKey);
		if ($channel === null) {
			return self::refused(
				message: PublicChatRefusedException::NOT_AVAILABLE,
				disclosure: PublicChatService::DEFAULT_DISCLOSURE,
				token: (string)$conversationToken
			);
		}

		$disclosure = $this->chat->disclosureFor(channel: $channel);
		try {
			return $this->chat->answer(
				channel: $channel,
				question: $question,
				locale: $locale,
				sourceScope: $sourceScope,
				conversationToken: $conversationToken
			);
		} catch (PublicChatRefusedException $e) {
			return self::refused(
				message: $e->getMessage(),
				disclosure: $disclosure,
				token: (string)$conversationToken,
				available: ($e->isUnavailable() === false)
			);
		} catch (Throwable $e) {
			$this->logger->error(
				message: '[PublicChatEntryPoint] A public chat turn failed',
				context: ['file' => __FILE__, 'line' => __LINE__, 'error' => $e->getMessage()]
			);
			return self::refused(message: PublicChatRefusedException::NOT_AVAILABLE, disclosure: $disclosure, token: (string)$conversationToken);
		}
	}//end ask()

	/**
	 * An answer that is a refusal.
	 *
	 * @param string $message    The sentence the visitor reads.
	 * @param string $disclosure The channel's disclosure.
	 * @param string $token      The token the app passed, kept so the visit continues.
	 * @param bool   $available  Whether the channel itself is available (a wrong question, not a stopped channel).
	 *
	 * @return PublicAnswer
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Whether the channel itself is up is what the sibling app shows, not a mode.
	 */
	private static function refused(string $message, string $disclosure, string $token, bool $available=false): PublicAnswer {
		return new PublicAnswer(answer: $message, sources: [], conversationToken: $token, disclosure: $disclosure, available: $available);
	}//end refused()
}//end class

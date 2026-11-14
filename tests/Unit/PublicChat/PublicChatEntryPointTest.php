<?php

/**
 * Sibling apps ask an app channel in-process, and a stopped channel answers as such.
 *
 * Pins delivery-public-web-chat task 3 (REQ-PUBCHAT-004).
 *
 * @category Tests
 * @package  OCA\Hermiq\Tests\Unit\PublicChat
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

namespace OCA\Hermiq\Tests\Unit\PublicChat;

use OCA\Hermiq\PublicChat\PublicChatEntryPoint;
use OCA\Hermiq\Service\PublicChat\PublicAnswer;
use OCA\Hermiq\Service\PublicChat\PublicChannelStore;
use OCA\Hermiq\Service\PublicChat\PublicChatRefusedException;
use OCA\Hermiq\Service\PublicChat\PublicChatService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * @covers \OCA\Hermiq\PublicChat\PublicChatEntryPoint
 * @uses   \OCA\Hermiq\Service\PublicChat\PublicAnswer
 * @uses   \OCA\Hermiq\Service\PublicChat\PublicChatRefusedException
 */
class PublicChatEntryPointTest extends TestCase {

	/**
	 * The entry point over a store that knows one app channel.
	 *
	 * @param PublicChatService    $chat   The turn.
	 * @param LoggerInterface|null $logger The logger.
	 *
	 * @return PublicChatEntryPoint
	 */
	private function entryPoint(PublicChatService $chat, ?LoggerInterface $logger=null): PublicChatEntryPoint {
		$store = $this->createMock(PublicChannelStore::class);
		$store->method('channelByKey')->willReturnCallback(
			fn (string $key): ?array => ($key === 'app:portaliq:wonen' ? ['id' => 'ch-9', 'kind' => 'app', 'disclosureText' => 'AI here.'] : null)
		);
		return new PublicChatEntryPoint($store, $chat, ($logger ?? $this->createMock(LoggerInterface::class)));
	}//end entryPoint()

	/**
	 * The question, locale, scope and token reach the turn, and its answer comes back as it is.
	 *
	 * @return void
	 */
	public function testAskPassesTheCallThroughWithoutAnIdentity(): void {
		$answer = new PublicAnswer(answer: 'Yes.', sources: [['title' => 'Wonen', 'url' => 'https://portal/wonen']], conversationToken: 'tok', disclosure: 'AI here.');
		$chat   = $this->createMock(PublicChatService::class);
		$chat->method('disclosureFor')->willReturn('AI here.');
		$chat->expects(self::once())->method('answer')
			->with(['id' => 'ch-9', 'kind' => 'app', 'disclosureText' => 'AI here.'], 'Can I rent?', 'nl', [['register' => 'portal', 'schema' => 'page']], null)
			->willReturn($answer);

		$result = $this->entryPoint($chat)->ask(channelKey: 'app:portaliq:wonen', question: 'Can I rent?', locale: 'nl', sourceScope: [['register' => 'portal', 'schema' => 'page']], conversationToken: null);

		self::assertSame($answer, $result);
	}//end testAskPassesTheCallThroughWithoutAnIdentity()

	/**
	 * An unknown key and a stopped channel answer "not available" without throwing.
	 *
	 * @return void
	 */
	public function testAStoppedOrUnknownChannelAnswersNotAvailable(): void {
		$chat = $this->createMock(PublicChatService::class);
		$chat->method('disclosureFor')->willReturn('AI here.');
		$chat->method('answer')->willThrowException(new PublicChatRefusedException());

		$unknown = $this->entryPoint($chat)->ask(channelKey: 'app:other:x', question: 'Hi', locale: 'en', sourceScope: [], conversationToken: 'tok');
		self::assertSame(['answer' => 'This assistant is not available right now.', 'sources' => [], 'conversationToken' => 'tok', 'disclosure' => PublicChatService::DEFAULT_DISCLOSURE, 'available' => false], $unknown->toArray());

		$stopped = $this->entryPoint($chat)->ask(channelKey: 'app:portaliq:wonen', question: 'Hi', locale: 'en', sourceScope: [], conversationToken: null);
		self::assertSame('This assistant is not available right now.', $stopped->answer);
		self::assertSame('AI here.', $stopped->disclosure);
		self::assertFalse($stopped->available);
	}//end testAStoppedOrUnknownChannelAnswersNotAvailable()

	/**
	 * A wrong question keeps the channel available; an unexpected failure is logged and answers "not available".
	 *
	 * @return void
	 */
	public function testAWrongQuestionAndAFailure(): void {
		$chat = $this->createMock(PublicChatService::class);
		$chat->method('disclosureFor')->willReturn('AI here.');
		$chat->method('answer')->willReturnOnConsecutiveCalls(
			self::throwException(new PublicChatRefusedException(message: 'Ask a question of at most 2000 characters.', status: 400)),
			self::throwException(new RuntimeException('model down'))
		);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('error');
		$entry = $this->entryPoint($chat, $logger);

		$wrong = $entry->ask(channelKey: 'app:portaliq:wonen', question: '', locale: 'en', sourceScope: [], conversationToken: null);
		self::assertSame('Ask a question of at most 2000 characters.', $wrong->answer);
		self::assertTrue($wrong->available);

		$failed = $entry->ask(channelKey: 'app:portaliq:wonen', question: 'Hi', locale: 'en', sourceScope: [], conversationToken: null);
		self::assertSame('This assistant is not available right now.', $failed->answer);
		self::assertFalse($failed->available);
	}//end testAWrongQuestionAndAFailure()

	/**
	 * The published signature: names, order and types are what sibling apps call with.
	 *
	 * @return void
	 */
	public function testThePublishedSignature(): void {
		$method = new ReflectionMethod(PublicChatEntryPoint::class, 'ask');
		$params = array_map(static fn ($p): string => (string)$p->getType() . ' $' . $p->getName(), $method->getParameters());

		self::assertSame(['string $channelKey', 'string $question', 'string $locale', 'array $sourceScope', '?string $conversationToken'], $params);
		self::assertSame(PublicAnswer::class, (string)$method->getReturnType());
	}//end testThePublishedSignature()
}//end class

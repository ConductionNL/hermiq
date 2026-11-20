<?php

/**
 * The public chat page: the channel's disclosure and endpoint, framed only by its allowed origins.
 *
 * Pins delivery-public-web-chat task 5 (REQ-PUBCHAT-002).
 *
 * @category Tests
 * @package  OCA\Hermiq\Tests\Unit\Controller
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

namespace OCA\Hermiq\Tests\Unit\Controller;

use OCA\Hermiq\Controller\PublicChatPageController;
use OCA\Hermiq\Service\PublicChat\PublicChannelStore;
use OCA\Hermiq\Service\PublicChat\PublicChatService;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IRequest;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Hermiq\Controller\PublicChatPageController
 * @uses   \OCA\Hermiq\Service\PublicChat\PublicChannelStore
 */
class PublicChatPageControllerTest extends TestCase {

	/**
	 * The channel token in every test URL.
	 */
	private const TOKEN = 'Chan0123456789abcdef';

	/**
	 * The initial state the page provided.
	 *
	 * @var array<string, mixed>
	 */
	private array $state = [];

	/**
	 * The controller over one known channel.
	 *
	 * @return PublicChatPageController
	 */
	private function controller(): PublicChatPageController {
		$store = $this->getMockBuilder(PublicChannelStore::class)->disableOriginalConstructor()->onlyMethods(['channelByToken'])->getMock();
		$store->method('channelByToken')->willReturnCallback(
			fn (string $token): ?array => ($token === self::TOKEN ? ['id' => 'ch-1', 'kind' => 'website', 'name' => 'Website', 'enabled' => true, 'disclosureText' => 'You chat with an AI.', 'allowedOrigins' => ['https://WWW.example.nl/', 'not an origin']] : null)
		);

		$chat = $this->createMock(PublicChatService::class);
		$chat->method('disclosureFor')->willReturnCallback(fn (array $channel): string => (string)($channel['disclosureText'] ?? 'Default disclosure.'));

		$state = $this->createMock(IInitialState::class);
		$state->method('provideInitialState')->willReturnCallback(function (string $key, mixed $data): void {
			$this->state[$key] = $data;
		});
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('linkToRoute')->willReturnCallback(fn (string $route, array $args): string => '/index.php/apps/hermiq/api/public/' . $args['channelToken'] . '/messages#' . $route);

		return new PublicChatPageController($this->createMock(IRequest::class), $store, $chat, $state, $urls);
	}//end controller()

	/**
	 * The chat page carries the channel's disclosure and endpoint, and only the allowed origins may frame it.
	 *
	 * @return void
	 */
	public function testThePageIsFramedOnlyByAllowedOrigins(): void {
		$page = $this->controller()->page(channelToken: self::TOKEN);

		self::assertInstanceOf(TemplateResponse::class, $page);
		self::assertSame(200, $page->getStatus());
		self::assertSame('public-chat', $page->getTemplateName());
		self::assertSame('base', $page->getRenderAs());
		self::assertSame(
			['available' => true, 'disclosure' => 'You chat with an AI.', 'endpoint' => '/index.php/apps/hermiq/api/public/' . self::TOKEN . '/messages#hermiq.publicChat.message', 'title' => 'Website'],
			$this->state['public-chat']
		);
		$policy = $page->getContentSecurityPolicy()->buildPolicy();
		self::assertStringContainsString("frame-ancestors 'self' https://www.example.nl", $policy);
		self::assertStringNotContainsString('not an origin', $policy);

		$unknown = $this->controller()->page(channelToken: 'Unknown0123456789abc');
		self::assertSame(404, $unknown->getStatus());
		self::assertFalse($this->state['public-chat']['available']);
		self::assertSame('Default disclosure.', $this->state['public-chat']['disclosure']);
		self::assertStringNotContainsString('example.nl', $unknown->getContentSecurityPolicy()->buildPolicy());
	}//end testThePageIsFramedOnlyByAllowedOrigins()
}//end class

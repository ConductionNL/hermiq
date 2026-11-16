<?php

/**
 * ConversationManagementHandler routes free text to the configured provider
 * and never lets a failed summary escape.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\Engine
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Engine;

use OCA\Hermiq\Service\Engine\ConversationManagementHandler;
use OCA\Hermiq\Service\Llm\ChatDriver;
use OCA\Hermiq\Service\Llm\ProviderFactory;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Pins `generateText()` for the Anthropic and Nextcloud drivers, and the
 * error log when a summary cannot be saved.
 *
 * @covers \OCA\Hermiq\Service\Engine\ConversationManagementHandler
 * @uses   \OCA\Hermiq\Service\Llm\ChatDriver
 */
class ConversationManagementHandlerProvidersTest extends TestCase {

	/**
	 * A provider factory resolved to the given driver for the given organisation.
	 *
	 * @param ChatDriver  $driver       The driver to resolve.
	 * @param string|null $organisation The organisation the driver must be asked for.
	 *
	 * @return ProviderFactory&\PHPUnit\Framework\MockObject\MockObject
	 */
	private function factory(ChatDriver $driver, ?string $organisation): ProviderFactory {
		$factory = $this->createMock(ProviderFactory::class);
		$factory->method('getLlmConfig')->willReturn(['chatProvider' => $driver->provider]);
		$factory->expects($this->once())->method('createChatDriver')
			->with(['chatProvider' => $driver->provider], null, null, $organisation)
			->willReturn($driver);

		return $factory;
	}//end factory()

	/**
	 * An Anthropic driver is called with its credential, model, base URL, auth and execution mode.
	 *
	 * @return void
	 */
	public function testGenerateTextCallsAnthropicWithTheDriversSettings(): void {
		$driver = new ChatDriver(
			provider: 'anthropic',
			chat: null,
			model: 'claude-x',
			credentialId: 'cred-a',
			baseUrl: 'https://api.anthropic.test',
			authMode: 'oauth',
			executionMode: 'cli'
		);
		$factory = $this->factory(driver: $driver, organisation: 'org-1');
		$factory->expects($this->never())->method('callFireworksChat');
		$factory->expects($this->once())->method('callAnthropicChat')->willReturnCallback(
			function (string $credentialId, string $model, string $baseUrl, array $messageHistory, string $authMode = 'api_key', ?int $maxTokens = null, array $functions = [], ?callable $toolExecutor = null, string $executionMode = 'http'): string {
				$this->assertSame(['cred-a', 'claude-x', 'https://api.anthropic.test', 'oauth', 'cli'], [$credentialId, $model, $baseUrl, $authMode, $executionMode]);
				$this->assertCount(1, $messageHistory);
				$this->assertSame('Summarise this', $messageHistory[0]->content);
				return 'An answer';
			}
		);

		$handler = new ConversationManagementHandler($this->createMock(ObjectService::class), $factory, new NullLogger());

		$this->assertSame('An answer', $handler->generateText(prompt: 'Summarise this', organisation: 'org-1'));
	}//end testGenerateTextCallsAnthropicWithTheDriversSettings()

	/**
	 * A Nextcloud driver hands the prompt to the TaskProcessing path.
	 *
	 * @return void
	 */
	public function testGenerateTextUsesNextcloudForTheNextcloudDriver(): void {
		$factory = $this->factory(driver: new ChatDriver(provider: 'nextcloud', chat: null, model: ''), organisation: null);
		$factory->expects($this->never())->method('callAnthropicChat');
		$factory->expects($this->once())->method('generateViaNextcloud')->with('Name this')->willReturn('A title');

		$handler = new ConversationManagementHandler($this->createMock(ObjectService::class), $factory, new NullLogger());

		$this->assertSame('A title', $handler->generateText(prompt: 'Name this'));
	}//end testGenerateTextUsesNextcloudForTheNextcloudDriver()

	/**
	 * A summary that cannot be saved is logged as an error and does not escape.
	 *
	 * @return void
	 */
	public function testAFailedSummaryIsLoggedAndSwallowed(): void {
		$messages = [];
		for ($i = 1; $i <= 12; $i++) {
			$entity = new ObjectEntity();
			$entity->setUuid('msg-' . $i);
			$entity->setObject(['conversationId' => 'conv-1', 'role' => 'user', 'content' => 'Turn ' . $i]);
			$messages[] = $entity;
		}

		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('setRegister')->willReturnSelf();
		$objectService->method('setSchema')->willReturnSelf();
		$objectService->method('findAll')->willReturn($messages);
		$objectService->expects($this->once())->method('saveObject')->willThrowException(new RuntimeException('locked'));

		$factory = $this->createMock(ProviderFactory::class);
		$factory->method('getLlmConfig')->willReturn([]);
		$factory->method('createChatDriver')->willReturn(new ChatDriver(provider: 'fireworks', chat: null, model: 'm', credentialId: 'c', baseUrl: 'https://f.test'));
		$factory->method('callFireworksChat')->willReturn('Summary.');

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with(
			'[ConversationManagementHandler] Failed to summarise conversation',
			$this->callback(static fn (array $context): bool => $context['error'] === 'locked')
		);

		$conversation = new ObjectEntity();
		$conversation->setUuid('conv-1');
		$conversation->setObject(['metadata' => ['token_count' => 5000]]);

		(new ConversationManagementHandler($objectService, $factory, $logger))->checkAndSummarize(conversation: $conversation);
	}//end testAFailedSummaryIsLoggedAndSwallowed()
}//end class

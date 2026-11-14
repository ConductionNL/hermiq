<?php

/**
 * The context usage is kept on the session as `metadata.contextUsage`, next to
 * the metadata already there, and never as `metadata.token_count`, which would
 * switch on the summariser (chat-how-an-answer-came-about D6).
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\Engine
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
 * @spec openspec/changes/chat-how-an-answer-came-about/specs/answer-transparency/spec.md#requirement-a-person-sees-how-full-the-models-context-is-req-atrn-004
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Engine;

use OCA\Hermiq\Service\Engine\ConversationManagementHandler;
use OCA\Hermiq\Service\Llm\ProviderFactory;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Tests for ConversationManagementHandler::recordContextUsage().
 */
class ConversationContextUsageTest extends TestCase {

	/**
	 * The usage is merged into the stored metadata and saved on the session.
	 *
	 * @return void
	 */
	public function testTheUsageIsKeptNextToTheExistingMetadata(): void {
		$session = new ObjectEntity();
		$session->setUuid('conv-1');
		$session->setObject(['title' => 'Offer', 'metadata' => ['summary' => 'Earlier.', 'subject' => ['id' => 's']]]);

		$saved = [];
		$objectService = $this->createMock(ObjectService::class);
		$found = [];
		$objectService->expects($this->once())->method('find')->willReturnCallback(
			function (...$args) use (&$found, $session): ObjectEntity {
				$found = $args;
				return $session;
			}
		);
		$objectService->method('saveObject')->willReturnCallback(
			function (...$args) use (&$saved): ObjectEntity {
				$saved[] = $args;
				return new ObjectEntity();
			}
		);

		$usage = ['usedTokens' => 850, 'windowTokens' => 1000, 'estimated' => false];
		$handler = new ConversationManagementHandler($objectService, $this->createMock(ProviderFactory::class), new NullLogger());
		$handler->recordContextUsage(conversationId: 'conv-1', contextUsage: $usage);

		$this->assertSame(['conv-1', [], false, 'hermiq', 'agentsession'], array_slice($found, 0, 5));
		$this->assertCount(1, $saved);
		$object = $saved[0][0];
		$this->assertSame('Offer', $object['title']);
		$this->assertSame(['summary' => 'Earlier.', 'subject' => ['id' => 's'], 'contextUsage' => $usage], $object['metadata']);
		$this->assertArrayNotHasKey('token_count', $object['metadata']);
		$this->assertContains('conv-1', $saved[0]);
		$this->assertContains('agentsession', $saved[0]);
	}//end testTheUsageIsKeptNextToTheExistingMetadata()

	/**
	 * A missing session saves nothing; a failing save is logged and never thrown.
	 *
	 * @return void
	 */
	public function testAMissingSessionOrAFailedSaveNeverFailsTheTurn(): void {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('find')->willReturn(null);
		$objectService->expects($this->never())->method('saveObject');
		(new ConversationManagementHandler($objectService, $this->createMock(ProviderFactory::class), new NullLogger()))
			->recordContextUsage(conversationId: 'gone', contextUsage: ['usedTokens' => 1, 'windowTokens' => null, 'estimated' => true]);

		$session = new ObjectEntity();
		$session->setUuid('conv-1');
		$session->setObject(['metadata' => 'not an object']);
		$failing = $this->createMock(ObjectService::class);
		$failing->method('find')->willReturn($session);
		$failing->method('saveObject')->willThrowException(new RuntimeException('database down'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with(
			'[ConversationManagementHandler] Could not record the context usage',
			$this->callback(static fn (array $context): bool => $context['error'] === 'database down')
		);

		(new ConversationManagementHandler($failing, $this->createMock(ProviderFactory::class), $logger))
			->recordContextUsage(conversationId: 'conv-1', contextUsage: ['usedTokens' => 1, 'windowTokens' => null, 'estimated' => true]);
	}//end testAMissingSessionOrAFailedSaveNeverFailsTheTurn()
}//end class

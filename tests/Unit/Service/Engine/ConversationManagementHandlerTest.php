<?php

/**
 * Unit tests for ConversationManagementHandler (agent-engine-port).
 *
 * Covers title generation (LLM path with trimming/truncation, fallback when no
 * provider is available), title uniqueness suffixing over ObjectService-fetched
 * conversations, and the summarisation trigger (token threshold, once-per-hour
 * throttle, persisted metadata via sanitised save).
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\Engine
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/agent-engine-port/tasks.md#task-1-1
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Engine;

use OCA\Hermiq\Service\Engine\ConversationManagementHandler;
use OCA\Hermiq\Service\Llm\ChatDriver;
use OCA\Hermiq\Service\Llm\ProviderFactory;
use OCA\Hermiq\Service\Llm\ProviderUnavailableException;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests for conversation titles and summarisation.
 *
 * @spec openspec/changes/agent-engine-port/tasks.md#task-1-1
 */
class ConversationManagementHandlerTest extends TestCase {

	/**
	 * A ProviderFactory mock resolved to the Fireworks direct-HTTP path whose
	 * generation returns $generated (the simplest driver to fake — no LLPhant
	 * chat instance to stub).
	 *
	 * @param string $generated The text the "LLM" returns.
	 *
	 * @return ProviderFactory
	 */
	private function providerFactoryReturning(string $generated): ProviderFactory {
		$factory = $this->createMock(ProviderFactory::class);
		$factory->method('getLlmConfig')->willReturn(['chatProvider' => 'fireworks']);
		$factory->method('createChatDriver')->willReturn(
			new ChatDriver(
				provider: 'fireworks',
				chat: null,
				model: 'test-model',
				credentialId: 'cred-uuid-fireworks',
				baseUrl: 'https://api.fireworks.ai/inference/v1'
			)
		);
		$factory->method('callFireworksChat')->willReturn($generated);
		return $factory;
	}//end providerFactoryReturning()

	/**
	 * A Conversation ObjectEntity.
	 *
	 * @param array<string, mixed> $payload The conversation payload.
	 *
	 * @return ObjectEntity
	 */
	private function conversation(array $payload): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('conv-1');
		$entity->setObject($payload);
		return $entity;
	}//end conversation()

	/**
	 * The LLM-generated title is trimmed of quotes and length-capped at 60 chars.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agent-engine-port/tasks.md#task-1-1
	 */
	public function testGenerateTitleTrimsAndCaps(): void {
		$long = '"' . str_repeat('An extremely descriptive conversation title ', 3) . '"';
		$handler = new ConversationManagementHandler(
			$this->createMock(ObjectService::class),
			$this->providerFactoryReturning($long),
			new NullLogger()
		);

		$title = $handler->generateConversationTitle(firstMessage: 'Tell me about leave policy');

		$this->assertStringNotContainsString('"', $title);
		$this->assertLessThanOrEqual(60, strlen($title));
		$this->assertStringEndsWith('...', $title);

	}//end testGenerateTitleTrimsAndCaps()

	/**
	 * When no provider is available the fallback title (word-boundary truncation
	 * of the message) is used instead of an exception.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agent-engine-port/tasks.md#task-1-1
	 */
	public function testGenerateTitleFallsBackWhenProviderUnavailable(): void {
		$factory = $this->createMock(ProviderFactory::class);
		$factory->method('getLlmConfig')->willReturn(['chatProvider' => null]);
		$factory->method('createChatDriver')->willThrowException(
			new ProviderUnavailableException('Chat provider is not configured', 503)
		);

		$handler = new ConversationManagementHandler(
			$this->createMock(ObjectService::class),
			$factory,
			new NullLogger()
		);

		$message = 'What is the maximum number of vacation days an employee can carry over into the next year?';
		$title = $handler->generateConversationTitle(firstMessage: $message);

		$this->assertStringEndsWith('...', $title);
		$this->assertLessThanOrEqual(63, strlen($title));
		$this->assertStringStartsWith('What is the maximum', $title);

	}//end testGenerateTitleFallsBackWhenProviderUnavailable()

	/**
	 * ensureUniqueTitle appends the next free numeric suffix when the base title
	 * (and numbered variants) already exist for this user + agent.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agent-engine-port/tasks.md#task-1-1
	 */
	public function testEnsureUniqueTitleAppendsNextSuffix(): void {
		$existing = [
			$this->conversation(['title' => 'Leave policy', 'userId' => 'alice', 'agentId' => 'agent-1']),
			$this->conversation(['title' => 'Leave policy (2)', 'userId' => 'alice', 'agentId' => 'agent-1']),
			$this->conversation(['title' => 'Unrelated', 'userId' => 'alice', 'agentId' => 'agent-1']),
		];

		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('setRegister')->willReturnSelf();
		$objectService->method('setSchema')->willReturnSelf();
		$objectService->method('findAll')->willReturnCallback(
			function (array $config) use ($existing): array {
				$this->assertSame('alice', $config['filters']['userId']);
				$this->assertSame('agent-1', $config['filters']['agentId']);
				return $existing;
			}
		);

		$handler = new ConversationManagementHandler(
			$objectService,
			$this->createMock(ProviderFactory::class),
			new NullLogger()
		);

		$unique = $handler->ensureUniqueTitle(baseTitle: 'Leave policy', userId: 'alice', agentId: 'agent-1');
		$this->assertSame('Leave policy (3)', $unique);

	}//end testEnsureUniqueTitleAppendsNextSuffix()

	/**
	 * A base title with no existing collision passes through unchanged.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agent-engine-port/tasks.md#task-1-1
	 */
	public function testEnsureUniqueTitlePassesThroughWhenFree(): void {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('setRegister')->willReturnSelf();
		$objectService->method('setSchema')->willReturnSelf();
		$objectService->method('findAll')->willReturn([]);

		$handler = new ConversationManagementHandler(
			$objectService,
			$this->createMock(ProviderFactory::class),
			new NullLogger()
		);

		$this->assertSame(
			'Fresh title',
			$handler->ensureUniqueTitle(baseTitle: 'Fresh title', userId: 'alice', agentId: 'agent-1')
		);

	}//end testEnsureUniqueTitlePassesThroughWhenFree()

	/**
	 * Below the token threshold summarisation is a no-op (no fetch, no save).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agent-engine-port/tasks.md#task-1-1
	 */
	public function testCheckAndSummarizeNoOpUnderThreshold(): void {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->expects($this->never())->method('findAll');
		$objectService->expects($this->never())->method('saveObject');

		$handler = new ConversationManagementHandler(
			$objectService,
			$this->createMock(ProviderFactory::class),
			new NullLogger()
		);

		$handler->checkAndSummarize(
			conversation: $this->conversation(['metadata' => ['token_count' => 100]])
		);

		// Reaching this point without any interaction is the assertion (mock
		// expectations above enforce it).
		$this->addToAssertionCount(1);

	}//end testCheckAndSummarizeNoOpUnderThreshold()

	/**
	 * Over the threshold, older messages are summarised and the summary +
	 * bookkeeping persisted on the conversation metadata.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agent-engine-port/tasks.md#task-1-1
	 */
	public function testCheckAndSummarizePersistsSummary(): void {
		// 12 messages: the newest 10 are kept, the oldest 2 summarised.
		$messages = [];
		for ($i = 1; $i <= 12; $i++) {
			$entity = new ObjectEntity();
			$entity->setUuid('msg-' . $i);
			$entity->setObject(
				[
					'conversationId' => 'conv-1',
					'role' => (($i % 2) === 1) ? 'user' : 'assistant',
					'content' => 'Turn ' . $i,
				]
			);
			$messages[] = $entity;
		}

		$savedPayload = null;
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('setRegister')->willReturnSelf();
		$objectService->method('setSchema')->willReturnSelf();
		$objectService->method('findAll')->willReturn($messages);
		$objectService->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend = [], mixed $register = null, mixed $schema = null, ?string $uuid = null) use (&$savedPayload): ObjectEntity {
				$savedPayload = ['object' => $object, 'uuid' => $uuid];
				return new ObjectEntity();
			}
		);

		$handler = new ConversationManagementHandler(
			$objectService,
			$this->providerFactoryReturning('Old turns summarised.'),
			new NullLogger()
		);

		$handler->checkAndSummarize(
			conversation: $this->conversation(['metadata' => ['token_count' => 5000]])
		);

		$this->assertNotNull($savedPayload);
		$this->assertSame('conv-1', $savedPayload['uuid']);
		$metadata = $savedPayload['object']['metadata'];
		$this->assertSame('Old turns summarised.', $metadata['summary']);
		$this->assertSame(2, $metadata['summarized_messages']);
		// The timestamp is ISO-8601 (sanitised save contract).
		$this->assertStringContainsString('T', $metadata['last_summary_at']);

	}//end testCheckAndSummarizePersistsSummary()

	/**
	 * A summary from less than an hour ago suppresses re-summarisation.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agent-engine-port/tasks.md#task-1-1
	 */
	public function testCheckAndSummarizeThrottledWithinAnHour(): void {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->expects($this->never())->method('findAll');
		$objectService->expects($this->never())->method('saveObject');

		$handler = new ConversationManagementHandler(
			$objectService,
			$this->createMock(ProviderFactory::class),
			new NullLogger()
		);

		$handler->checkAndSummarize(
			conversation: $this->conversation(
				[
					'metadata' => [
						'token_count' => 5000,
						'last_summary_at' => (new \DateTime('-10 minutes'))->format('c'),
					],
				]
			)
		);

		$this->addToAssertionCount(1);

	}//end testCheckAndSummarizeThrottledWithinAnHour()
}//end class

<?php

/**
 * The engine stores the model's reasoning filtered and redacted on the answer
 * and returns it, marks a withheld one, and keeps the context usage on the
 * session without writing `metadata.token_count`.
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
 * @spec openspec/changes/chat-how-an-answer-came-about/specs/answer-transparency/spec.md#requirement-the-models-reasoning-is-shown-only-when-the-owner-allows-it-and-the-provider-returns-it-req-atrn-003
 * @spec openspec/changes/chat-how-an-answer-came-about/specs/answer-transparency/spec.md#requirement-a-person-sees-how-full-the-models-context-is-req-atrn-004
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Engine;

use OCA\Hermiq\Service\Engine\ContextAssembler;
use OCA\Hermiq\Service\Engine\ContextRetrievalHandler;
use OCA\Hermiq\Service\Engine\ConversationManagementHandler;
use OCA\Hermiq\Service\Engine\Engine;
use OCA\Hermiq\Service\Engine\MessageHistoryHandler;
use OCA\Hermiq\Service\Engine\ResponseGenerationHandler;
use OCA\Hermiq\Service\GuardrailPolicyService;
use OCA\Hermiq\Service\Llm\ModelContextWindows;
use OCA\Hermiq\Service\RedactionService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IAppConfig;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests for the reasoning and context usage of Engine::processMessage().
 */
class EngineReasoningAndContextTest extends TestCase {

	/**
	 * What each storeMessage() call was given, by role.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $stored = [];

	/**
	 * The context usage recordContextUsage() was given.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $recorded = [];

	/**
	 * Run one turn whose model returns `$reasoning` and reports `$usage`.
	 *
	 * @param string               $reasoning The provider's reasoning.
	 * @param array<string, mixed> $usage     The provider's usage.
	 *
	 * @return array<string, mixed> The envelope.
	 */
	private function turn(string $reasoning, array $usage): array {
		$conversation = new ObjectEntity();
		$conversation->setUuid('conv-1');
		$conversation->setObject(['userId' => 'alice', 'agentId' => null]);

		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('find')->willReturn($conversation);
		$objectService->method('findAll')->willReturn([1, 2, 3]);

		$contextHandler = $this->createMock(ContextRetrievalHandler::class);
		$contextHandler->method('retrieveContext')->willReturn(['text' => '', 'sources' => []]);

		$responseHandler = $this->createMock(ResponseGenerationHandler::class);
		$responseHandler->method('generateResponse')->willReturn('The answer.');
		$responseHandler->lastUsage = $usage;
		$responseHandler->lastReasoning = $reasoning;
		$responseHandler->lastPrompt = ['provider' => 'ollama', 'model' => 'qwen3:8b', 'promptCharacters' => 400];

		$conversationHandler = $this->createMock(ConversationManagementHandler::class);
		$conversationHandler->method('recordContextUsage')->willReturnCallback(
			function (string $conversationId, array $contextUsage): void {
				$this->recorded[] = ['conversationId' => $conversationId, 'contextUsage' => $contextUsage];
			}
		);

		$historyHandler = $this->createMock(MessageHistoryHandler::class);
		$historyHandler->method('buildTurnHistory')->willReturn(['history' => [], 'turnText' => 'Q']);
		$historyHandler->method('storeMessage')->willReturnCallback(
			function (string $conversationId, string $role, string $content, ...$rest): ObjectEntity {
				$this->stored[$role] = $rest;
				$message = new ObjectEntity();
				$message->setUuid('msg-' . $role);
				return $message;
			}
		);

		$policies = $this->createMock(GuardrailPolicyService::class);
		$policies->method('effectivePolicyFor')->willReturn(['outputFilters' => ['piiAction' => 'block']]);
		$policies->method('filterInput')->willReturnCallback(
			static fn (array $policy, string $text): array => ['text' => $text, 'blocked' => false, 'reason' => null]
		);
		$policies->method('filterOutput')->willReturnCallback(
			static fn (array $policy, string $text): array => [
				'text' => $text,
				'blocked' => str_contains($text, 'BSN 123456782'),
				'reason' => null,
			]
		);

		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturn('yes');
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('{"ollama/qwen3:8b":1000}');

		$assembler = $this->createMock(ContextAssembler::class);
		$assembler->method('assembleForAgent')->willReturn('');

		$engine = new Engine(
			objectService: $objectService,
			contextHandler: $contextHandler,
			responseHandler: $responseHandler,
			conversationHandler: $conversationHandler,
			historyHandler: $historyHandler,
			contextAssembler: $assembler,
			logger: new NullLogger(),
			guardrailPolicyService: $policies,
			redaction: new RedactionService($config),
			contextWindows: new ModelContextWindows($appConfig)
		);

		return $engine->processMessage(conversationId: 'conv-1', userId: 'alice', userMessage: 'Q');
	}//end turn()

	/**
	 * The reasoning is redacted, stored on the answer and returned; the context
	 * usage is the provider's count with the declared window, kept on the session.
	 *
	 * @return void
	 */
	public function testTheReasoningIsStoredRedactedAndTheUsageRecorded(): void {
		$secret = 'ghp_abcdefghijklmnopqrstuvwxyz0123456789';
		$result = $this->turn(reasoning: 'Look it up with ' . $secret . '.', usage: ['promptTokens' => 800, 'completionTokens' => 50]);

		$this->assertIsString($result['reasoning']);
		$this->assertStringStartsWith('Look it up with ', $result['reasoning']);
		$this->assertStringNotContainsString($secret, $result['reasoning']);
		$this->assertFalse($result['reasoningWithheld']);
		$this->assertStringNotContainsString('Look it up', $result['message']);

		// storeMessage(conversationId, role, content, sources, context, authorId, authorDisplayName, attachments, provenance, reasoning, reasoningWithheld)
		$this->assertSame($result['reasoning'], $this->stored['assistant'][6]);
		$this->assertFalse($this->stored['assistant'][7]);
		$this->assertNull($this->stored['user'][6]);

		$usage = ['usedTokens' => 850, 'windowTokens' => 1000, 'estimated' => false];
		$this->assertSame($usage, $result['contextUsage']);
		$this->assertSame([['conversationId' => 'conv-1', 'contextUsage' => $usage]], $this->recorded);
	}//end testTheReasoningIsStoredRedactedAndTheUsageRecorded()

	/**
	 * A reasoning the filter blocks is not stored, and the answer says it was withheld.
	 *
	 * @return void
	 */
	public function testABlockedReasoningIsWithheld(): void {
		$result = $this->turn(reasoning: 'The BSN 123456782 matches.', usage: []);

		$this->assertNull($result['reasoning']);
		$this->assertTrue($result['reasoningWithheld']);
		$this->assertNull($this->stored['assistant'][6]);
		$this->assertTrue($this->stored['assistant'][7]);
		// No provider count: an estimate of (400 + 11 characters) / 4.
		$this->assertSame(['usedTokens' => 103, 'windowTokens' => 1000, 'estimated' => true], $result['contextUsage']);
	}//end testABlockedReasoningIsWithheld()

	/**
	 * A turn without reasoning stores none and withholds none.
	 *
	 * @return void
	 */
	public function testATurnWithoutReasoningStoresNone(): void {
		$result = $this->turn(reasoning: '', usage: []);

		$this->assertNull($result['reasoning']);
		$this->assertFalse($result['reasoningWithheld']);
	}//end testATurnWithoutReasoningStoresNone()
}//end class

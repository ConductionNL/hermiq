<?php

/**
 * Hermiq: a scoped answer cites numbered sources, or says nothing matched.
 *
 * The engine with a stub model: the stored sources keep the citation order, a
 * scope with nothing in it never reaches the model, the system prompt numbers
 * the passages and forbids general knowledge, and a scope the speaker cannot
 * open is refused before anything is stored.
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
 * @spec openspec/changes/chat-about-a-document-cites-its-sources/tasks.md#task-3-the-answer-cites-numbered-sources-or-says-nothing-matched
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Engine;

use OCA\Hermiq\Service\Chat\ChatRefusalFrame;
use OCA\Hermiq\Service\Chat\TurnScopeException;
use OCA\Hermiq\Service\Engine\ContextAssembler;
use OCA\Hermiq\Service\Engine\ContextRetrievalHandler;
use OCA\Hermiq\Service\Engine\ConversationManagementHandler;
use OCA\Hermiq\Service\Engine\Engine;
use OCA\Hermiq\Service\Engine\MessageHistoryHandler;
use OCA\Hermiq\Service\Engine\ResponseGenerationHandler;
use OCA\Hermiq\Service\Engine\ResponseTurnRun;
use OCA\Hermiq\Service\Engine\ToolLoop;
use OCA\Hermiq\Service\Engine\TurnSystemPrompt;
use OCA\Hermiq\Service\Llm\ProviderFactory;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests for the citations of a scoped turn.
 */
class ScopedCitationTest extends TestCase {

	/**
	 * The scope every turn here carries.
	 *
	 * @var array<string, mixed>
	 */
	private const RAG = ['scope' => ['type' => 'file', 'fileId' => 42]];

	/**
	 * The scoped context of two matching passages, numbered 1 and 2.
	 *
	 * @return array<string, mixed>
	 */
	private function twoPassages(): array {
		return [
			'text' => "[1] Source: besluit.pdf, page 1\nDe beslistermijn is acht weken.\n\n[2] Source: besluit.pdf, page 3\nVerlenging met zes weken is mogelijk.\n\n",
			'sources' => [
				['id' => 42, 'type' => 'file', 'name' => 'besluit.pdf', 'file_id' => 42, 'file_path' => '/besluit.pdf', 'page' => 1, 'passage' => 1, 'text' => 'De beslistermijn is acht weken.', 'similarity' => 1.0],
				['id' => 42, 'type' => 'file', 'name' => 'besluit.pdf', 'file_id' => 42, 'file_path' => '/besluit.pdf', 'page' => 3, 'passage' => 2, 'text' => 'Verlenging met zes weken is mogelijk.', 'similarity' => 0.5],
			],
			'scoped' => true,
			'noMatch' => 'This document does not answer this question.',
		];
	}//end twoPassages()

	/**
	 * Alice's conversation with an active agent.
	 *
	 * @return ObjectService
	 */
	private function objects(): ObjectService {
		$objects = $this->createMock(ObjectService::class);
		$objects->method('find')->willReturnCallback(
			static function (int|string $id): ObjectEntity {
				$entity = new ObjectEntity();
				$entity->setUuid((string)$id);
				$entity->setObject(['userId' => 'alice', 'agentId' => 'agent-1', 'name' => 'Agent', 'active' => true]);
				return $entity;
			}
		);
		$objects->method('findAll')->willReturn([1, 2, 3]);
		return $objects;
	}//end objects()

	/**
	 * A history handler that records each stored message.
	 *
	 * @param list<array<string, mixed>> $stored The stored messages, filled in place.
	 *
	 * @return MessageHistoryHandler
	 */
	private function history(array &$stored): MessageHistoryHandler {
		$history = $this->createMock(MessageHistoryHandler::class);
		$history->method('storeMessage')->willReturnCallback(
			static function (string $conversationId, string $role, string $content, ?array $sources = null) use (&$stored): ObjectEntity {
				$stored[] = ['role' => $role, 'content' => $content, 'sources' => $sources];
				$entity = new ObjectEntity();
				$entity->setUuid('msg-' . $role);
				return $entity;
			}
		);
		$history->method('buildTurnHistory')->willReturnCallback(
			static fn (string $conversationId, ObjectEntity $turn, string $text): array => ['history' => [], 'turnText' => $text]
		);
		return $history;
	}//end history()

	/**
	 * Two passages used: the answer's markers [1] and [2] are sources[0] and
	 * sources[1], stored in that order with their file ids and pages; the
	 * retrieval reads the files as the speaker.
	 *
	 * @return void
	 */
	public function testClaimsPointAtTheStoredSourcesInOrder(): void {
		$context = $this->createMock(ContextRetrievalHandler::class);
		$context->expects($this->once())->method('assertScopeReadable')->with(self::RAG, 'alice');
		$context->expects($this->once())->method('retrieveContext')
			->with('Wat is de beslistermijn?', $this->isInstanceOf(ObjectEntity::class), [], self::RAG, 'alice')
			->willReturn($this->twoPassages());
		$response = $this->createMock(ResponseGenerationHandler::class);
		$response->method('generateResponse')->willReturn('Acht weken [1], te verlengen met zes weken [2].');
		$stored = [];

		$engine = new Engine(
			$this->objects(),
			$context,
			$response,
			$this->createMock(ConversationManagementHandler::class),
			$this->history(stored: $stored),
			$this->createMock(ContextAssembler::class),
			new NullLogger()
		);
		$result = $engine->processMessage(conversationId: 'conv-1', userId: 'alice', userMessage: 'Wat is de beslistermijn?', ragSettings: self::RAG);

		$answer = $stored[1];
		$this->assertSame('assistant', $answer['role']);
		preg_match_all('/\[(\d+)\]/', $answer['content'], $markers);
		foreach ($markers[1] as $marker) {
			$this->assertSame((int)$marker, $answer['sources'][(int)$marker - 1]['passage']);
		}

		$this->assertSame([1, 3], array_column($answer['sources'], 'page'));
		$this->assertSame([42, 42], array_column($answer['sources'], 'file_id'));
		$this->assertSame($answer['sources'], $result['sources']);
	}

	/**
	 * A scope the speaker cannot open: 404, nothing stored, no model call.
	 *
	 * @return void
	 */
	public function testARefusedScopeStoresNothingAndCallsNoModel(): void {
		$context = $this->createMock(ContextRetrievalHandler::class);
		$context->method('assertScopeReadable')->willThrowException(new TurnScopeException(status: 404));
		$context->expects($this->never())->method('retrieveContext');
		$response = $this->createMock(ResponseGenerationHandler::class);
		$response->expects($this->never())->method('generateResponse');
		$history = $this->createMock(MessageHistoryHandler::class);
		$history->expects($this->never())->method('storeMessage');

		$engine = new Engine(
			$this->objects(),
			$context,
			$response,
			$this->createMock(ConversationManagementHandler::class),
			$history,
			$this->createMock(ContextAssembler::class),
			new NullLogger()
		);

		$this->expectException(TurnScopeException::class);
		$this->expectExceptionCode(404);
		$engine->processMessage(conversationId: 'conv-1', userId: 'alice', userMessage: 'Wat is de beslistermijn?', ragSettings: self::RAG);
	}

	/**
	 * Nothing in scope: the reply says the document does not answer, and no
	 * provider is ever resolved, so the model cannot answer from general knowledge.
	 *
	 * @return void
	 */
	public function testNothingInScopeNeverAsksTheModel(): void {
		$providers = $this->createMock(ProviderFactory::class);
		$providers->expects($this->never())->method($this->anything());
		$handler = new ResponseGenerationHandler($providers, $this->createMock(ToolLoop::class), new NullLogger());
		$handler->lastUsage = ['promptTokens' => 9];

		$reply = $handler->generateResponse(
			userMessage: 'Hoe hoog is de parkeerboete?',
			context: ['text' => '', 'sources' => [], 'scoped' => true, 'noMatch' => 'This document does not answer this question.'],
			messageHistory: [],
			agent: null
		);

		$this->assertSame('This document does not answer this question.', $reply);
		$this->assertSame([], $handler->lastUsage);
		$this->assertSame([], $handler->attachmentNotices);
	}

	/**
	 * The system prompt of a scoped turn numbers the passages and asks for a
	 * marker per claim; an unscoped turn keeps the general context block.
	 *
	 * @return void
	 */
	public function testTheScopedPromptAsksForNumberedCitationsOnly(): void {
		$scoped = (new TurnSystemPrompt())->compose(run: new ResponseTurnRun('Wat is de beslistermijn?', $this->twoPassages()));
		$empty = (new TurnSystemPrompt())->compose(
			run: new ResponseTurnRun('x', ['text' => '', 'sources' => [], 'scoped' => true, 'noMatch' => ''])
		);
		$unscoped = (new TurnSystemPrompt())->compose(run: new ResponseTurnRun('x', ['text' => 'CONTEXT', 'sources' => []]));

		$this->assertStringContainsString('in square brackets, for example [1] or [2]', $scoped);
		$this->assertStringContainsString('Do not answer from general knowledge.', $scoped);
		$this->assertStringEndsWith("PASSAGES:\n" . $this->twoPassages()['text'], $scoped);
		$this->assertStringContainsString('say that the document does not answer it', $empty);
		$this->assertStringNotContainsString('square brackets', $unscoped);
		$this->assertStringContainsString("CONTEXT:\nCONTEXT", $unscoped);
	}

	/**
	 * The stream tells the person why a scope was refused, by its stable code.
	 *
	 * @return void
	 */
	public function testTheStreamFramesNameTheRefusedScope(): void {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => $text);
		$frames = new ChatRefusalFrame(l10n: $l10n);

		$this->assertSame(
			['code' => 'scope_not_found', 'message' => 'The document or folder this question is about cannot be opened.'],
			$frames->forCause(cause: new TurnScopeException(status: 404))
		);
		$this->assertSame('scope_invalid', $frames->forCause(cause: new TurnScopeException(status: 400))['code']);
	}
}

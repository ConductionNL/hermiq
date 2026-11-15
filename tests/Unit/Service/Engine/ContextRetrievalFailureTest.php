<?php

/**
 * Unit tests for ContextRetrievalHandler's failure paths.
 *
 * Retrieval is best-effort context for a chat turn: a search that throws, or
 * a scope source that throws, must degrade to "no context" (logged) rather
 * than fail the turn — and a scoped turn without a passage source must answer
 * with an empty scoped context, never fall through to an unscoped search.
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

use OCA\Hermiq\Service\Chat\ScopedPassageSource;
use OCA\Hermiq\Service\Chat\TurnScope;
use OCA\Hermiq\Service\Engine\ContextRetrievalHandler;
use OCA\Hermiq\Service\RetrievalScope\ValidatedMode;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for the degraded outcomes of context retrieval.
 */
#[CoversClass(ContextRetrievalHandler::class)]
#[UsesClass(TurnScope::class)]
#[UsesClass(ValidatedMode::class)]
class ContextRetrievalFailureTest extends TestCase {

	/**
	 * A search that throws yields an empty context and an error log line.
	 *
	 * @return void
	 */
	public function testAFailingSearchDegradesToNoContext(): void {
		$objects = $this->createMock(ObjectService::class);
		$objects->expects($this->once())
			->method('searchObjectsPaginated')
			->willThrowException(new RuntimeException('index offline'));

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())
			->method('error')
			->with(
				'[ContextRetrievalHandler] Failed to retrieve context',
				$this->callback(static fn (array $context): bool => $context['error'] === 'index offline')
			);

		$agent = new ObjectEntity();
		$agent->setObject(['views' => ['view-1'], 'ragSearchMode' => 'keyword']);

		$handler = new ContextRetrievalHandler(objectService: $objects, logger: $logger);
		$context = $handler->retrieveContext(query: 'parking fine', agent: $agent);

		$this->assertSame(
			[
				'text' => '',
				'sources' => [],
				'retrievalScope' => [
					'fileFilters' => null,
					'validated' => false,
					'floor' => null,
					'passed' => 0,
					'bestSimilarity' => null,
					'abstained' => false,
				],
			],
			$context
		);

	}//end testAFailingSearchDegradesToNoContext()

	/**
	 * A scope source that throws keeps the no-match reply and logs the error.
	 *
	 * @return void
	 */
	public function testAFailingScopeReadKeepsTheNoMatchReply(): void {
		$objects = $this->createMock(ObjectService::class);
		$objects->expects($this->never())->method('searchObjectsPaginated');

		$source = $this->createMock(ScopedPassageSource::class);
		$source->method('noMatchReply')->willReturn('This document does not answer this question.');
		$source->expects($this->once())
			->method('matching')
			->with($this->anything(), 'q', 'alice', 1)
			->willThrowException(new RuntimeException('unreadable'));

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())
			->method('error')
			->with(
				'[ContextRetrievalHandler] Failed to read the scope of the turn',
				$this->callback(static fn (array $context): bool => $context['error'] === 'unreadable')
			);

		$handler = new ContextRetrievalHandler(objectService: $objects, logger: $logger, scopedSource: $source);
		$context = $handler->retrieveContext(
			query: 'q',
			agent: null,
			ragSettings: ['scope' => ['type' => 'file', 'fileId' => 42], 'numSourcesFiles' => 0],
			speaker: 'alice'
		);

		$this->assertSame(
			['text' => '', 'sources' => [], 'scoped' => true, 'noMatch' => 'This document does not answer this question.'],
			$context
		);

	}//end testAFailingScopeReadKeepsTheNoMatchReply()

	/**
	 * A scoped turn without a passage source answers an empty scoped context.
	 *
	 * @return void
	 */
	public function testAScopeWithoutASourceNeverSearchesUnscoped(): void {
		$objects = $this->createMock(ObjectService::class);
		$objects->expects($this->never())->method('searchObjectsPaginated');

		$handler = new ContextRetrievalHandler(objectService: $objects, logger: $this->createMock(LoggerInterface::class));
		$context = $handler->retrieveContext(
			query: 'q',
			agent: null,
			ragSettings: ['scope' => ['type' => 'file', 'fileId' => 42]],
			speaker: 'alice'
		);

		$this->assertSame(['text' => '', 'sources' => [], 'scoped' => true, 'noMatch' => ''], $context);

	}//end testAScopeWithoutASourceNeverSearchesUnscoped()
}//end class

<?php

/**
 * Tests for semantic and hybrid RAG through OpenRegister's vector facade (vector-rag).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
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
 * @spec openspec/changes/vector-rag/tasks.md#task-5-1
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Engine;

use OCA\Hermiq\Service\Engine\ContextRetrievalHandler;
use OCA\Hermiq\Service\Engine\RetrievalSourceCollector;
use OCA\Hermiq\Service\Engine\VectorSearch;
use OCA\Hermiq\Service\RetrievalScope\ValidatedMode;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\Search\VectorSearchFacadeStub;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * Semantic and hybrid retrieval reach the facade with the views, and fall back to keyword.
 */
#[CoversClass(ContextRetrievalHandler::class)]
#[UsesClass(VectorSearch::class)]
#[UsesClass(RetrievalSourceCollector::class)]
#[UsesClass(ValidatedMode::class)]
final class ContextRetrievalVectorTest extends TestCase {

	/**
	 * The keyword search's object service.
	 *
	 * @var ObjectService&MockObject
	 */
	private $objects;

	/**
	 * The stub facade.
	 *
	 * @var VectorSearchFacadeStub
	 */
	private VectorSearchFacadeStub $facade;

	/**
	 * Set up the object service and the facade.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->objects = $this->createMock(originalClassName: ObjectService::class);
		$this->facade  = new VectorSearchFacadeStub();
		$this->facade->rows = [
			[
				'entity_id' => 'obj-1',
				'entity_type' => 'object',
				'chunk_text' => 'Een parkeervergunning kost 120 euro per jaar.',
				'similarity' => 0.81,
				'metadata' => ['uuid' => 'obj-1', 'register_id' => 3, 'schema_id' => 9],
			],
			[
				'entity_id' => 42,
				'entity_type' => 'file',
				'chunk_text' => 'Tarieven 2026: bewonersvergunning.',
				'similarity' => 0.74,
				'metadata' => ['file_id' => 42, 'file_path' => '/Tarieven.pdf', 'mime_type' => 'application/pdf'],
			],
		];
	}//end setUp()

	/**
	 * The handler with vector search over the stub.
	 *
	 * @return ContextRetrievalHandler
	 */
	private function handler(): ContextRetrievalHandler {
		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->willReturn($this->facade);

		return new ContextRetrievalHandler(
			objectService: $this->objects,
			logger: new NullLogger(),
			vectorSearch: new VectorSearch(container: $container, logger: new NullLogger(), facadeClass: VectorSearchFacadeStub::class)
		);
	}//end handler()

	/**
	 * An agent with the given RAG fields.
	 *
	 * @param array<string, mixed> $fields The fields.
	 *
	 * @return ObjectEntity
	 */
	private function agent(array $fields): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('agent-uuid');
		$entity->setObject(array_merge(['name' => 'RAG agent', 'views' => ['view-1', 'view-2'], 'ragIncludeFiles' => true, 'ragIncludeObjects' => true], $fields));

		return $entity;
	}//end agent()

	/**
	 * Semantic mode reads the facade with the resolved views, and both its object and file rows become sources.
	 *
	 * @return void
	 */
	public function testSemanticReadsTheFacadeWithTheViews(): void {
		$this->objects->expects($this->never())->method('searchObjectsPaginated');

		$context = $this->handler()->retrieveContext(query: 'wat kost een parkeervergunning', agent: $this->agent(['ragSearchMode' => 'semantic']));

		$this->assertSame(expected: 'searchSemantic', actual: $this->facade->calls[0][0]);
		$this->assertSame(expected: ['view-1', 'view-2'], actual: $this->facade->calls[0][1]['views']);
		$this->assertSame(expected: 'wat kost een parkeervergunning', actual: $this->facade->calls[0][1]['query']);
		$this->assertEqualsCanonicalizing(expected: ['object', 'file'], actual: array_column($context['sources'], 'type'));
		$this->assertStringContainsString(needle: 'Een parkeervergunning kost 120 euro', haystack: $context['text']);
		$this->assertStringContainsString(needle: 'Tarieven 2026', haystack: $context['text']);
	}//end testSemanticReadsTheFacadeWithTheViews()

	/**
	 * Hybrid mode reads searchHybrid.
	 *
	 * @return void
	 */
	public function testHybridReadsSearchHybrid(): void {
		$this->handler()->retrieveContext(query: 'vergunning', agent: $this->agent(['ragSearchMode' => 'hybrid']));

		$this->assertSame(expected: ['searchHybrid'], actual: array_column($this->facade->calls, 0));
	}//end testHybridReadsSearchHybrid()

	/**
	 * Without a view set the facade is never asked, in either mode.
	 *
	 * @return void
	 */
	public function testNoViewsNeverReachesTheFacade(): void {
		$this->objects->expects($this->never())->method('searchObjectsPaginated');

		foreach (['semantic', 'hybrid'] as $mode) {
			$context = $this->handler()->retrieveContext(query: 'q', agent: $this->agent(['ragSearchMode' => $mode, 'views' => []]));
			$this->assertSame(expected: [], actual: $context['sources']);
		}

		$this->assertSame(expected: [], actual: $this->facade->calls);
	}//end testNoViewsNeverReachesTheFacade()

	/**
	 * A facade without a backend, or one that throws, leaves the turn on the keyword path.
	 *
	 * @return void
	 */
	public function testAnUnavailableOrFailingFacadeFallsBackToKeyword(): void {
		$views = [];
		$this->objects->method('searchObjectsPaginated')->willReturnCallback(
			function (array $query, bool $rbac = true, bool $multitenancy = true, bool $deleted = false, ?array $ids = null, ?string $uses = null, ?array $scope = null) use (&$views): array {
				$views[] = $scope;
				return ['results' => [['id' => 'kw-1', 'name' => 'Keyword hit', '_score' => 0.9]], 'total' => 1];
			}
		);

		$this->facade->available = false;
		$offline = $this->handler()->retrieveContext(query: 'q', agent: $this->agent(['ragSearchMode' => 'semantic']));

		$this->facade->available = true;
		$this->facade->throw     = 'index offline';
		$failing = $this->handler()->retrieveContext(query: 'q', agent: $this->agent(['ragSearchMode' => 'hybrid']));

		$this->assertSame(expected: [['view-1', 'view-2'], ['view-1', 'view-2']], actual: $views);
		$this->assertSame(expected: ['object'], actual: array_column($offline['sources'], 'type'));
		$this->assertSame(expected: ['object'], actual: array_column($failing['sources'], 'type'));
	}//end testAnUnavailableOrFailingFacadeFallsBackToKeyword()

	/**
	 * Keyword mode never asks the facade.
	 *
	 * @return void
	 */
	public function testKeywordModeNeverAsksTheFacade(): void {
		$this->objects->method('searchObjectsPaginated')->willReturn(['results' => []]);

		$this->handler()->retrieveContext(query: 'q', agent: $this->agent(['ragSearchMode' => 'keyword']));

		$this->assertSame(expected: [], actual: $this->facade->calls);
	}//end testKeywordModeNeverAsksTheFacade()
}//end class

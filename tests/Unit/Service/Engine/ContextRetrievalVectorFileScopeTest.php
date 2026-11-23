<?php

/**
 * Tests that a semantic or hybrid turn hands the agent's file set to the vector
 * facade and still drops file rows outside it (memory-retrieval-scope).
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
 * @spec openspec/changes/memory-retrieval-scope/specs/retrieval-scope/spec.md#requirement-an-owner-can-limit-file-retrieval-by-owner-file-name-and-modified-date-req-rscope-001
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Engine;

use OCA\Hermiq\Service\Engine\ContextRetrievalHandler;
use OCA\Hermiq\Service\Engine\VectorSearch;
use OCA\Hermiq\Service\RetrievalScope\FileScopeResolver;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\Search\FileScopedVectorSearchFacadeStub;
use OCA\OpenRegister\Service\Search\VectorSearchFacadeStub;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * The file set on the vector path.
 */
final class ContextRetrievalVectorFileScopeTest extends TestCase {

	/**
	 * A retrieval in the given mode with the given file set, over the given facade.
	 *
	 * @param VectorSearchFacadeStub $facade      The facade stub.
	 * @param string                 $facadeClass Its class name.
	 * @param list<int>|null         $fileSet     What the resolver answers.
	 * @param string                 $mode        `semantic` or `hybrid`.
	 *
	 * @return array{text: string, sources: list<array>}
	 */
	private function retrieve(VectorSearchFacadeStub $facade, string $facadeClass, ?array $fileSet, string $mode='semantic'): array {
		$facade->rows = [
			['entity_type' => 'file', 'entity_id' => 12, 'chunk_text' => 'Mandaatregister 2025', 'similarity' => 0.8, 'metadata' => ['file_id' => 12]],
			['entity_type' => 'file', 'entity_id' => 99, 'chunk_text' => 'Oud mandaatregister', 'similarity' => 0.7, 'metadata' => ['file_id' => 99]],
			['entity_type' => 'object', 'entity_id' => 'obj-1', 'chunk_text' => 'Besluit', 'similarity' => 0.6, 'metadata' => ['uuid' => 'obj-1']],
		];

		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->willReturn($facade);

		$resolver = $this->createMock(originalClassName: FileScopeResolver::class);
		$resolver->method('resolve')->willReturn($fileSet);

		$agent = new ObjectEntity();
		$agent->setUuid('agent-uuid');
		$agent->setObject(
			[
				'name' => 'Jurist',
				'views' => ['view-1'],
				'ragSearchMode' => $mode,
				'ragIncludeFiles' => true,
				'ragIncludeObjects' => true,
				'ragFileFilters' => ['namePattern' => 'mandaat'],
			]
		);

		$handler = new ContextRetrievalHandler(
			objectService: $this->createMock(originalClassName: ObjectService::class),
			logger: new NullLogger(),
			fileScope: $resolver,
			vectorSearch: new VectorSearch(container: $container, logger: new NullLogger(), facadeClass: $facadeClass)
		);

		return $handler->retrieveContext(query: 'mandaatregister', agent: $agent);
	}//end retrieve()

	/**
	 * A facade with a file scope is asked for the agent's files only, in both vector modes.
	 *
	 * @return void
	 */
	public function testTheFacadeIsAskedForTheAgentsFilesOnly(): void {
		foreach (['semantic' => 'searchSemantic', 'hybrid' => 'searchHybrid'] as $mode => $method) {
			$facade = new FileScopedVectorSearchFacadeStub();
			$this->retrieve(facade: $facade, facadeClass: FileScopedVectorSearchFacadeStub::class, fileSet: [12], mode: $mode);

			$this->assertSame(expected: $method, actual: $facade->calls[0][0]);
			$this->assertSame(expected: [12], actual: $facade->calls[0][1]['fileIds']);
		}
	}//end testTheFacadeIsAskedForTheAgentsFilesOnly()

	/**
	 * A facade without a file scope is asked as published, and file rows outside the set are still dropped.
	 *
	 * @return void
	 */
	public function testWithoutAFacadeScopeFileRowsOutsideTheSetAreDropped(): void {
		$facade  = new VectorSearchFacadeStub();
		$context = $this->retrieve(facade: $facade, facadeClass: VectorSearchFacadeStub::class, fileSet: [12]);

		$this->assertArrayNotHasKey(key: 'fileIds', array: $facade->calls[0][1]);
		$sources = array_map(static fn (array $s): array => [$s['id'], $s['type']], $context['sources']);
		$this->assertSame(expected: [[12, 'file'], ['obj-1', 'object']], actual: $sources);
	}//end testWithoutAFacadeScopeFileRowsOutsideTheSetAreDropped()

	/**
	 * Filters that match nothing: no file scope is handed over and no file row comes back.
	 *
	 * @return void
	 */
	public function testAnEmptyFileSetHandsNoScopeAndKeepsNoFile(): void {
		$facade  = new FileScopedVectorSearchFacadeStub();
		$context = $this->retrieve(facade: $facade, facadeClass: FileScopedVectorSearchFacadeStub::class, fileSet: []);

		$this->assertSame(expected: [], actual: $facade->calls[0][1]['fileIds']);
		$this->assertSame(expected: ['object'], actual: array_column($context['sources'], 'type'));
	}//end testAnEmptyFileSetHandsNoScopeAndKeepsNoFile()

	/**
	 * Without filters the facade is asked unscoped and every row passes.
	 *
	 * @return void
	 */
	public function testNoFiltersLeaveTheFacadeUnscoped(): void {
		$facade  = new FileScopedVectorSearchFacadeStub();
		$context = $this->retrieve(facade: $facade, facadeClass: FileScopedVectorSearchFacadeStub::class, fileSet: null);

		$this->assertSame(expected: [], actual: $facade->calls[0][1]['fileIds']);
		$this->assertSame(expected: [12, 99, 'obj-1'], actual: array_column($context['sources'], 'id'));
	}//end testNoFiltersLeaveTheFacadeUnscoped()
}//end class

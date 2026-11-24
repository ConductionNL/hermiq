<?php

/**
 * Tests that VectorSearch hands the agent's file set to a facade that takes one
 * (memory-retrieval-scope), and keeps the published call for one that does not.
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

use OCA\Hermiq\Service\Engine\VectorSearch;
use OCA\OpenRegister\Service\Search\FileScopedVectorSearchFacadeStub;
use OCA\OpenRegister\Service\Search\VectorSearchFacadeStub;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * The file set on the facade call.
 */
#[CoversClass(VectorSearch::class)]
final class VectorSearchFileScopeTest extends TestCase {

	/**
	 * VectorSearch over the given stub facade class.
	 *
	 * @param VectorSearchFacadeStub $facade      The stub the container hands out.
	 * @param string                 $facadeClass Its class name.
	 *
	 * @return VectorSearch
	 */
	private function search(VectorSearchFacadeStub $facade, string $facadeClass): VectorSearch {
		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->with($facadeClass)->willReturn($facade);

		return new VectorSearch(container: $container, logger: new NullLogger(), facadeClass: $facadeClass);
	}//end search()

	/**
	 * A facade with a file scope is asked for exactly the agent's files, in both modes.
	 *
	 * @return void
	 */
	public function testAFileScopedFacadeGetsTheFileSet(): void {
		$facade = new FileScopedVectorSearchFacadeStub();
		$search = $this->search(facade: $facade, facadeClass: FileScopedVectorSearchFacadeStub::class);

		$search->search(mode: 'semantic', query: 'mandaat', limit: 6, scope: ['views' => ['v-1'], 'fileIds' => [3 => 12, 7 => 40]]);
		$search->search(mode: 'hybrid', query: 'mandaat', limit: 6, scope: ['views' => ['v-1'], 'fileIds' => [12]]);

		$this->assertSame(
			expected: [
				['searchSemantic', ['query' => 'mandaat', 'limit' => 6, 'views' => ['v-1'], 'fileIds' => [12, 40]]],
				['searchHybrid', ['query' => 'mandaat', 'limit' => 6, 'views' => ['v-1'], 'fileIds' => [12]]],
			],
			actual: $facade->calls
		);
	}//end testAFileScopedFacadeGetsTheFileSet()

	/**
	 * Without a file set, or with an empty one, no file scope is passed: an empty
	 * `fileIds` could read as "every file".
	 *
	 * @return void
	 */
	public function testNoOrAnEmptyFileSetPassesNoScope(): void {
		$facade = new FileScopedVectorSearchFacadeStub();
		$search = $this->search(facade: $facade, facadeClass: FileScopedVectorSearchFacadeStub::class);

		$search->search(mode: 'semantic', query: 'q', limit: 4, scope: ['views' => ['v-1']]);
		$search->search(mode: 'semantic', query: 'q', limit: 4, scope: ['views' => ['v-1'], 'fileIds' => []]);

		$this->assertSame(expected: [[], []], actual: array_map(static fn (array $call): array => $call[1]['fileIds'], $facade->calls));
	}//end testNoOrAnEmptyFileSetPassesNoScope()

	/**
	 * The published facade, without a `fileIds` argument, is asked as before.
	 *
	 * @return void
	 */
	public function testAFacadeWithoutAFileScopeIsAskedAsPublished(): void {
		$facade       = new VectorSearchFacadeStub();
		$facade->rows = [['entity_id' => 12, 'entity_type' => 'file', 'chunk_text' => 'a', 'similarity' => 0.8, 'metadata' => []]];

		$rows = $this->search(facade: $facade, facadeClass: VectorSearchFacadeStub::class)
			->search(mode: 'hybrid', query: 'q', limit: 4, scope: ['views' => ['v-1'], 'fileIds' => [12]]);

		$this->assertSame(expected: [['searchHybrid', ['query' => 'q', 'limit' => 4, 'views' => ['v-1']]]], actual: $facade->calls);
		$this->assertSame(expected: $facade->rows, actual: $rows);
	}//end testAFacadeWithoutAFileScopeIsAskedAsPublished()
}//end class

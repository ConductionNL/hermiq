<?php

/**
 * Tests for VectorSearch: OpenRegister's vector facade, reached lazily, with three
 * distinct keyword tiers (vector-rag).
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

use OCA\Hermiq\Service\Engine\VectorSearch;
use OCA\OpenRegister\Service\Search\VectorSearchFacadeStub;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The facade's tiers, routing and embeddings.
 */
#[CoversClass(VectorSearch::class)]
final class VectorSearchTest extends TestCase {

	/**
	 * Log lines by level.
	 *
	 * @var array<string, array<int, string>>
	 */
	private array $logs = ['info' => [], 'warning' => []];

	/**
	 * A logger that records info and warning lines.
	 *
	 * @return LoggerInterface
	 */
	private function logger(): LoggerInterface {
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->method('info')->willReturnCallback(function (string|\Stringable $message): void {
			$this->logs['info'][] = (string)$message;
		});
		$logger->method('warning')->willReturnCallback(function (string|\Stringable $message): void {
			$this->logs['warning'][] = (string)$message;
		});

		return $logger;
	}//end logger()

	/**
	 * VectorSearch over the stub facade.
	 *
	 * @param VectorSearchFacadeStub $facade The stub the container hands out.
	 *
	 * @return VectorSearch
	 */
	private function search(VectorSearchFacadeStub $facade): VectorSearch {
		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->with(VectorSearchFacadeStub::class)->willReturn($facade);

		return new VectorSearch(container: $container, logger: $this->logger(), facadeClass: VectorSearchFacadeStub::class);
	}//end search()

	/**
	 * Semantic mode asks searchSemantic with the views passed through, and answers its rows.
	 *
	 * @return void
	 */
	public function testSemanticRoutesToTheFacadeWithTheViews(): void {
		$facade       = new VectorSearchFacadeStub();
		$facade->rows = [['entity_id' => 7, 'entity_type' => 'object', 'chunk_text' => 'a', 'similarity' => 0.82, 'metadata' => []], 'not a row'];

		$rows = $this->search(facade: $facade)->search(mode: 'semantic', query: 'parkeervergunning', limit: 6, views: ['v-1', 'v-2']);

		$this->assertSame(expected: [['searchSemantic', ['query' => 'parkeervergunning', 'limit' => 6, 'views' => ['v-1', 'v-2']]]], actual: $facade->calls);
		$this->assertSame(expected: [$facade->rows[0]], actual: $rows);
	}//end testSemanticRoutesToTheFacadeWithTheViews()

	/**
	 * Hybrid mode asks searchHybrid.
	 *
	 * @return void
	 */
	public function testHybridRoutesToSearchHybrid(): void {
		$facade = new VectorSearchFacadeStub();

		$this->assertSame(expected: [], actual: $this->search(facade: $facade)->search(mode: 'hybrid', query: 'q', limit: 4, views: ['v-1']));
		$this->assertSame(expected: 'searchHybrid', actual: $facade->calls[0][0]);
	}//end testHybridRoutesToSearchHybrid()

	/**
	 * Without the facade class every call stays on the keyword tier, and says the facade is not present.
	 *
	 * @return void
	 */
	public function testAnAbsentFacadeIsTheKeywordTier(): void {
		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->expects($this->never())->method('get');
		$search = new VectorSearch(container: $container, logger: $this->logger(), facadeClass: 'OCA\\OpenRegister\\Service\\Search\\NoSuchFacade');

		$this->assertSame(expected: ['tier' => 'keyword-degraded', 'reason' => 'facade-not-present'], actual: $search->tier());
		$this->assertNull(actual: $search->search(mode: 'semantic', query: 'q', limit: 4, views: ['v-1']));
		$this->assertNull(actual: $search->embedTexts(texts: ['a']));
		$this->assertSame(expected: 'default', actual: $search->modelId());
		$this->assertStringContainsString(needle: 'has no vector facade', haystack: $this->logs['info'][0]);
	}//end testAnAbsentFacadeIsTheKeywordTier()

	/**
	 * A facade without an embedding backend is not asked to search, and says so in its own words.
	 *
	 * @return void
	 */
	public function testAFacadeWithoutABackendIsNotAsked(): void {
		$facade            = new VectorSearchFacadeStub();
		$facade->available = false;
		$search            = $this->search(facade: $facade);

		$this->assertSame(expected: ['tier' => 'keyword-degraded', 'reason' => 'no-embedding-backend'], actual: $search->tier());
		$this->assertNull(actual: $search->search(mode: 'hybrid', query: 'q', limit: 4, views: ['v-1']));
		$this->assertSame(expected: [], actual: $facade->calls);
		$this->assertStringContainsString(needle: 'has no embedding backend', haystack: $this->logs['info'][0]);
		$this->assertSame(expected: [], actual: $this->logs['warning']);
	}//end testAFacadeWithoutABackendIsNotAsked()

	/**
	 * A facade that throws is logged as a warning and answers null, never throwing.
	 *
	 * @return void
	 */
	public function testAThrowingFacadeDegradesWithAWarning(): void {
		$facade        = new VectorSearchFacadeStub();
		$facade->throw = 'index offline';
		$search        = $this->search(facade: $facade);

		$this->assertNull(actual: $search->search(mode: 'semantic', query: 'q', limit: 4, views: ['v-1']));
		$this->assertNull(actual: $search->embedTexts(texts: ['a']));
		$this->assertCount(expectedCount: 2, haystack: $this->logs['warning']);
		$this->assertStringContainsString(needle: 'failed; degrading to keyword search', haystack: $this->logs['warning'][0]);
		$this->assertSame(expected: [], actual: $this->logs['info']);
	}//end testAThrowingFacadeDegradesWithAWarning()

	/**
	 * A live facade is the vector tier, embeds in order and names its model.
	 *
	 * @return void
	 */
	public function testALiveFacadeEmbedsAndNamesItsModel(): void {
		$facade          = new VectorSearchFacadeStub();
		$facade->vectors = ['a' => [1.0, 0.0], 'b' => [0.0, 1.0]];
		$search          = $this->search(facade: $facade);

		$this->assertSame(expected: ['tier' => 'vector-live', 'reason' => ''], actual: $search->tier());
		$this->assertSame(expected: [[1.0, 0.0], [0.0, 1.0]], actual: $search->embedTexts(texts: ['a', 'b']));
		$this->assertSame(expected: [], actual: $search->embedTexts(texts: []));
		$this->assertSame(expected: 'nomic-embed-text', actual: $search->modelId());
	}//end testALiveFacadeEmbedsAndNamesItsModel()

	/**
	 * A container that cannot build the facade is the not-present tier.
	 *
	 * @return void
	 */
	public function testAFacadeTheContainerCannotBuildIsNotPresent(): void {
		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->willThrowException(new RuntimeException('no service'));
		$search = new VectorSearch(container: $container, logger: $this->logger(), facadeClass: VectorSearchFacadeStub::class);

		$this->assertSame(expected: 'facade-not-present', actual: $search->tier()['reason']);
	}//end testAFacadeTheContainerCannotBuildIsNotPresent()
}//end class

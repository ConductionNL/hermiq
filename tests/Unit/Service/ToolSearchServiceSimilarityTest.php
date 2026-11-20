<?php

/**
 * Tests for similarity-ranked tool search over the resolved set (vector-rag).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service
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

namespace OCA\Hermiq\Tests\Unit\Service;

use OCA\Hermiq\Service\Engine\VectorSearch;
use OCA\Hermiq\Service\ToolSearchService;
use OCA\OpenRegister\Service\Search\VectorSearchFacadeStub;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * Ranking by embedding similarity, its floor, cap, cache and the substring fallback.
 */
#[CoversClass(ToolSearchService::class)]
#[UsesClass(VectorSearch::class)]
final class ToolSearchServiceSimilarityTest extends TestCase {

	private const TALK = ['name' => 'hermiq_talk_post', 'mcpId' => 'hermiq.talkPost', 'description' => 'Post into a Talk conversation'];

	private const LEADS = ['name' => 'pipelinq_lead_search', 'mcpId' => 'pipelinq.lead.search', 'description' => 'Search leads by name or status'];

	private const SCHEMA = ['name' => 'openregister_schemas_get', 'mcpId' => 'openregister.schemas.get', 'description' => 'Get a schema definition'];

	/**
	 * The stub facade.
	 *
	 * @var VectorSearchFacadeStub
	 */
	private VectorSearchFacadeStub $facade;

	/**
	 * Set up the facade with vectors per descriptor text and per query.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->facade          = new VectorSearchFacadeStub();
		$this->facade->vectors = [
			self::text(self::TALK) => [1.0, 0.0, 0.0],
			self::text(self::LEADS) => [0.0, 1.0, 0.0],
			self::text(self::SCHEMA) => [0.6, 0.0, 0.8],
			'send a chat message' => [0.9, 0.1, 0.0],
			'something unrelated' => [0.0, 0.0, -1.0],
		];
	}//end setUp()

	/**
	 * The text a descriptor is embedded by.
	 *
	 * @param array<string, string> $descriptor The descriptor.
	 *
	 * @return string
	 */
	private static function text(array $descriptor): string {
		return $descriptor['mcpId'] . ' ' . $descriptor['name'] . ' ' . $descriptor['description'];
	}//end text()

	/**
	 * The service over the stub facade, with a floor setting.
	 *
	 * @param string $floor The stored floor, '' for unset.
	 *
	 * @return ToolSearchService
	 */
	private function service(string $floor = ''): ToolSearchService {
		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->willReturn($this->facade);
		$config = $this->createMock(originalClassName: IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ($key === ToolSearchService::FLOOR_SETTING ? $floor : $default)
		);

		return new ToolSearchService(
			vectors: new VectorSearch(container: $container, logger: new NullLogger(), facadeClass: VectorSearchFacadeStub::class),
			appConfig: $config
		);
	}//end service()

	/**
	 * A paraphrase finds the tool whose words it does not share, best first, above the floor only.
	 *
	 * @return void
	 */
	public function testAParaphraseFindsTheToolBestFirst(): void {
		$service = $this->service();
		$service->registerResolved([self::LEADS, self::SCHEMA, self::TALK]);

		$this->assertSame(expected: [self::TALK, self::SCHEMA], actual: $service->search('send a chat message'));
	}//end testAParaphraseFindsTheToolBestFirst()

	/**
	 * The floor is read from the app setting and clamped to 0..1.
	 *
	 * @return void
	 */
	public function testTheFloorComesFromTheSetting(): void {
		$strict = $this->service(floor: '0.9');
		$strict->registerResolved([self::LEADS, self::SCHEMA, self::TALK]);
		$this->assertSame(expected: [self::TALK], actual: $strict->search('send a chat message'));

		$open = $this->service(floor: '-3');
		$open->registerResolved([self::LEADS, self::SCHEMA, self::TALK]);
		$this->assertCount(expectedCount: 3, haystack: $open->search('send a chat message'));
	}//end testTheFloorComesFromTheSetting()

	/**
	 * Nothing similar enough answers no match.
	 *
	 * @return void
	 */
	public function testNothingAboveTheFloorAnswersNothing(): void {
		$service = $this->service();
		$service->registerResolved([self::LEADS, self::TALK]);

		$this->assertSame(expected: [], actual: $service->search('something unrelated'));
	}//end testNothingAboveTheFloorAnswersNothing()

	/**
	 * At most ten matches, and never a descriptor outside the resolved set.
	 *
	 * @return void
	 */
	public function testTheCapHoldsAndOnlyResolvedToolsAreReturned(): void {
		$descriptors = [];
		for ($i = 0; $i < 14; $i++) {
			$descriptor  = ['name' => 'tool_' . $i, 'mcpId' => 'app.tool' . $i, 'description' => 'Tool ' . $i];
			$descriptors[] = $descriptor;
			$this->facade->vectors[self::text($descriptor)] = [1.0, 0.0, 0.0];
		}

		$service = $this->service();
		$service->registerResolved($descriptors);
		$found = $service->search('send a chat message');

		$this->assertCount(expectedCount: 10, haystack: $found);
		$this->assertNotContains(needle: self::TALK, haystack: $found);
		foreach ($found as $descriptor) {
			$this->assertTrue(condition: $service->isGranted($descriptor['mcpId']));
		}
	}//end testTheCapHoldsAndOnlyResolvedToolsAreReturned()

	/**
	 * The descriptor embeddings are cached until the resolved set or the model changes.
	 *
	 * @return void
	 */
	public function testTheCacheIsKeptUntilTheSetOrTheModelChanges(): void {
		$service = $this->service();
		$service->registerResolved([self::LEADS, self::TALK]);
		$service->search('send a chat message');
		$service->search('send a chat message');
		$this->assertSame(expected: 1, actual: $this->descriptorEmbeds());

		$this->facade->model = 'bge-m3';
		$service->search('send a chat message');
		$this->assertSame(expected: 2, actual: $this->descriptorEmbeds());

		$service->registerResolved([self::LEADS, self::TALK, self::SCHEMA]);
		$service->search('send a chat message');
		$this->assertSame(expected: 3, actual: $this->descriptorEmbeds());
	}//end testTheCacheIsKeptUntilTheSetOrTheModelChanges()

	/**
	 * Without an embedding backend the substring path runs, unchanged.
	 *
	 * @return void
	 */
	public function testNoBackendFallsBackToSubstringMatching(): void {
		$this->facade->available = false;
		$service = $this->service();
		$service->registerResolved([self::LEADS, self::TALK]);

		$this->assertSame(expected: [self::LEADS], actual: $service->search('leads'));
		$this->assertSame(expected: [], actual: $service->search('send a chat message'));
		$this->assertSame(expected: [], actual: $this->facade->calls);
	}//end testNoBackendFallsBackToSubstringMatching()

	/**
	 * A failing embedding also falls back to substring matching.
	 *
	 * @return void
	 */
	public function testAFailingEmbeddingFallsBackToSubstringMatching(): void {
		$this->facade->throw = 'model not loaded';
		$service = $this->service();
		$service->registerResolved([self::LEADS, self::TALK]);

		$this->assertSame(expected: [self::TALK], actual: $service->search('talk'));
	}//end testAFailingEmbeddingFallsBackToSubstringMatching()

	/**
	 * Cosine similarity of two vectors, 0 for an empty, zero or mismatched pair.
	 *
	 * @return void
	 */
	public function testCosine(): void {
		$this->assertEqualsWithDelta(expected: 1.0, actual: ToolSearchService::cosine(a: [2, 0], b: [5, 0]), delta: 1e-9);
		$this->assertEqualsWithDelta(expected: 0.6, actual: ToolSearchService::cosine(a: [1, 0, 0], b: [0.6, 0, 0.8]), delta: 1e-9);
		$this->assertSame(expected: 0.0, actual: ToolSearchService::cosine(a: [], b: []));
		$this->assertSame(expected: 0.0, actual: ToolSearchService::cosine(a: [0, 0], b: [1, 0]));
		$this->assertSame(expected: 0.0, actual: ToolSearchService::cosine(a: [1, 0], b: [1, 0, 0]));
	}//end testCosine()

	/**
	 * How many times the descriptors were embedded (one call with more than one text, or the set's size).
	 *
	 * @return int
	 */
	private function descriptorEmbeds(): int {
		return count(array_filter(
			$this->facade->calls,
			static fn (array $call): bool => $call[0] === 'embedTexts' && $call[1]['texts'] !== ['send a chat message']
		));
	}//end descriptorEmbeds()
}//end class

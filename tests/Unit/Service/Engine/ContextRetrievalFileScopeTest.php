<?php

/**
 * Unit tests for the file scope in ContextRetrievalHandler (memory-retrieval-scope, task 1).
 *
 * An agent's file filters that match nothing switch file search off for the
 * turn, a file set drops file rows outside it, and an agent without filters
 * searches as before.
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
use OCA\Hermiq\Service\Graph\GraphContextRetriever;
use OCA\Hermiq\Service\RetrievalScope\FileScopeResolver;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The file set applied to a retrieval run.
 */
class ContextRetrievalFileScopeTest extends TestCase {

	/**
	 * Three graph rows: two files and one object.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function rows(): array {
		return [
			['entity_type' => 'file', 'entity_id' => 12, 'chunk_text' => 'Mandaatregister 2025', 'metadata' => ['file_name' => 'mandaat.pdf']],
			['entity_type' => 'file', 'entity_id' => 99, 'chunk_text' => 'Oud mandaatregister', 'metadata' => ['file_name' => 'oud.pdf']],
			['entity_type' => 'object', 'entity_id' => 'obj-1', 'chunk_text' => 'Besluit', 'metadata' => []],
		];
	}//end rows()

	/**
	 * A retrieval over the graph rows with the given file set.
	 *
	 * @param list<int>|null $fileSet  What the resolver answers.
	 * @param mixed          $filters  The agent's ragFileFilters.
	 *
	 * @return array{text: string, sources: list<array>}
	 */
	private function retrieve(?array $fileSet, mixed $filters): array {
		$graph = $this->createMock(GraphContextRetriever::class);
		$graph->method('retrieve')->willReturn(['results' => self::rows(), 'relations' => '']);

		$resolver = $this->createMock(FileScopeResolver::class);
		$resolver->expects($this->once())->method('resolve')->with($filters)->willReturn($fileSet);

		$agent = new ObjectEntity();
		$agent->setUuid('agent-uuid');
		$agent->setObject(['name' => 'Jurist', 'views' => ['view-1'], 'ragSearchMode' => 'graph', 'graphEnabled' => true, 'ragFileFilters' => $filters]);

		$handler = new ContextRetrievalHandler($this->createMock(ObjectService::class), new NullLogger(), $graph, null, $resolver);

		return $handler->retrieveContext(query: 'mandaatregister', agent: $agent);
	}//end retrieve()

	/**
	 * A file set keeps the files in it and every object row.
	 *
	 * @return void
	 */
	public function testAFileSetKeepsOnlyItsFiles(): void {
		$context = $this->retrieve(fileSet: [12], filters: ['namePattern' => 'mandaat']);

		$this->assertSame([[12, 'file'], ['obj-1', 'object']], array_map(static fn (array $s): array => [$s['id'], $s['type']], $context['sources']));
	}//end testAFileSetKeepsOnlyItsFiles()

	/**
	 * Filters that match nothing: no file source at all, objects still found.
	 *
	 * @return void
	 */
	public function testAnEmptyFileSetSearchesNoFile(): void {
		$context = $this->retrieve(fileSet: [], filters: ['owners' => [['type' => 'group', 'id' => 'nobody']]]);

		$this->assertSame(['object'], array_column($context['sources'], 'type'));
	}//end testAnEmptyFileSetSearchesNoFile()

	/**
	 * No filters: every row passes, as before.
	 *
	 * @return void
	 */
	public function testNoFiltersLeaveRetrievalUnscoped(): void {
		$context = $this->retrieve(fileSet: null, filters: null);

		$this->assertSame([12, 99, 'obj-1'], array_column($context['sources'], 'id'));
	}//end testNoFiltersLeaveRetrievalUnscoped()
}//end class

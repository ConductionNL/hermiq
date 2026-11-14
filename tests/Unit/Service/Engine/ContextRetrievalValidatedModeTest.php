<?php

/**
 * Context retrieval in validated mode: only tagged files above the floor, no
 * objects, no session widening, and the scope each answer records.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/memory-retrieval-scope/specs/retrieval-scope/spec.md#requirement-an-agent-in-validated-mode-refuses-without-asking-the-model-when-nothing-validated-matches-req-rscope-003
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Engine;

use OCA\Hermiq\Service\Engine\ContextRetrievalHandler;
use OCA\Hermiq\Service\Graph\GraphContextRetriever;
use OCA\Hermiq\Service\RetrievalScope\FileScopeResolver;
use OCA\Hermiq\Service\RetrievalScope\ValidatedFileSet;
use OCA\Hermiq\Service\RetrievalScope\ValidatedMode;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @covers \OCA\Hermiq\Service\Engine\ContextRetrievalHandler
 * @uses \OCA\Hermiq\Service\RetrievalScope\ValidatedMode
 */
class ContextRetrievalValidatedModeTest extends TestCase {

	/**
	 * The candidate rows: a validated file, an unvalidated one, a weak validated one and an object.
	 *
	 * @return list<array<string, mixed>>
	 */
	private static function rows(): array {
		return [
			['entity_type' => 'file', 'entity_id' => 12, 'similarity' => 0.82, 'chunk_text' => 'Openingstijden KCC', 'metadata' => ['file_name' => 'kcc.pdf']],
			['entity_type' => 'file', 'entity_id' => 99, 'similarity' => 0.9, 'chunk_text' => 'Concept, niet gevalideerd', 'metadata' => ['file_name' => 'concept.pdf']],
			['entity_type' => 'file', 'entity_id' => 13, 'similarity' => 0.41, 'chunk_text' => 'Vuurwerk', 'metadata' => ['file_name' => 'oud.pdf']],
			['entity_type' => 'object', 'entity_id' => 'obj-1', 'similarity' => 0.95, 'chunk_text' => 'Besluit', 'metadata' => []],
		];
	}//end rows()

	/**
	 * Retrieve as an agent.
	 *
	 * @param array<string, mixed> $agentData   The agent.
	 * @param list<int>            $tagged      The validated files.
	 * @param array<string, mixed> $ragSettings The session's settings.
	 *
	 * @return array<string, mixed>
	 */
	private function retrieve(array $agentData, array $tagged, array $ragSettings = []): array {
		$graph = $this->createMock(GraphContextRetriever::class);
		$graph->method('retrieve')->willReturn(['results' => self::rows(), 'relations' => 'relations of every object']);
		$resolver = $this->createMock(FileScopeResolver::class);
		$resolver->method('resolve')->willReturn(null);
		$validated = $this->createMock(ValidatedFileSet::class);
		$validated->method('fileIds')->willReturn($tagged);

		$agent = new ObjectEntity();
		$agent->setUuid('agent-uuid');
		$agent->setObject($agentData + ['name' => 'Informatiepunt', 'views' => ['view-1'], 'ragSearchMode' => 'graph', 'graphEnabled' => true]);

		$handler = new ContextRetrievalHandler($this->createMock(ObjectService::class), new NullLogger(), $graph, null, $resolver, null, $validated);

		return $handler->retrieveContext(query: 'openingstijden', agent: $agent, selectedViews: ['extra-view'], ragSettings: $ragSettings);
	}//end retrieve()

	public function testOnlyValidatedFilesAboveTheFloorAreUsed(): void {
		$context = $this->retrieve(['answerOnlyFromValidated' => true, 'ragMinSimilarity' => 0.55], [12, 13]);

		$this->assertSame([12], array_column($context['sources'], 'id'));
		$this->assertStringNotContainsString('relations of every object', $context['text']);
		$this->assertSame(
			['fileFilters' => null, 'validated' => true, 'floor' => 0.55, 'passed' => 1, 'bestSimilarity' => 0.82, 'abstained' => false],
			$context['retrievalScope']
		);
	}//end testOnlyValidatedFilesAboveTheFloorAreUsed()

	public function testNothingValidatedAboveTheFloorAbstains(): void {
		$context = $this->retrieve(['answerOnlyFromValidated' => true, 'ragMinSimilarity' => 0.55], [13]);

		$this->assertSame([], $context['sources']);
		$this->assertTrue($context['retrievalScope']['abstained']);
		$this->assertSame(0.41, $context['retrievalScope']['bestSimilarity']);
	}//end testNothingValidatedAboveTheFloorAbstains()

	public function testNoValidatedFileAtAllAbstains(): void {
		$context = $this->retrieve(['answerOnlyFromValidated' => true], []);

		$this->assertSame([], $context['sources']);
		$this->assertTrue($context['retrievalScope']['abstained']);
	}//end testNoValidatedFileAtAllAbstains()

	public function testASessionCannotSwitchObjectsBackOn(): void {
		$context = $this->retrieve(['answerOnlyFromValidated' => true, 'ragMinSimilarity' => 0.5], [12], ['includeObjects' => true]);

		$this->assertNotContains('object', array_column($context['sources'], 'type'));
	}//end testASessionCannotSwitchObjectsBackOn()

	public function testOutsideValidatedModeEveryRowStillCounts(): void {
		$context = $this->retrieve([], [12]);

		$this->assertSame([12, 99, 13, 'obj-1'], array_column($context['sources'], 'id'));
		$this->assertFalse($context['retrievalScope']['validated']);
		$this->assertFalse($context['retrievalScope']['abstained']);
		$this->assertNull($context['retrievalScope']['floor']);
	}//end testOutsideValidatedModeEveryRowStillCounts()

	public function testASessionScopeToAnotherFileIsIgnored(): void {
		$context = $this->retrieve(['answerOnlyFromValidated' => true, 'ragMinSimilarity' => 0.5], [12], ['scope' => ['type' => 'file', 'fileId' => 99]]);

		$this->assertSame([12], array_column($context['sources'], 'id'));
		$this->assertTrue($context['retrievalScope']['validated']);
	}//end testASessionScopeToAnotherFileIsIgnored()

	public function testTheDefaultFloorApplies(): void {
		$this->assertSame(ValidatedMode::DEFAULT_FLOOR, $this->retrieve(['answerOnlyFromValidated' => true], [12])['retrievalScope']['floor']);
	}//end testTheDefaultFloorApplies()
}//end class

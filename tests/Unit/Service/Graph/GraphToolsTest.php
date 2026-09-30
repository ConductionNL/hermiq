<?php

/**
 * The knowledge-graph tools: RBAC-filtered, reference-only results, and reachable
 * only through the agent's resolved grant set (knowledge-graph).
 *
 * GraphTools runs on the real GraphService over the in-memory graph; the grant test
 * uses OpenRegister's real ToolGrantResolver against the provider's real catalogue.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\Graph
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/knowledge-graph/specs/knowledge-graph/spec.md#requirement-graph-traversal-is-exposed-as-governed-agent-tools
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Graph;

use OCA\Hermiq\Mcp\GraphToolDescriptors;
use OCA\Hermiq\Service\Graph\GraphTools;
use OCA\OpenRegister\Service\Capability\ToolGrantResolver;
use PHPUnit\Framework\TestCase;

/**
 * Tests for GraphTools and the graph tools' governance.
 *
 * @spec openspec/changes/knowledge-graph/specs/knowledge-graph/spec.md#requirement-graph-traversal-is-exposed-as-governed-agent-tools
 */
class GraphToolsTest extends TestCase {

	use InMemoryGraph;

	/**
	 * A small graph: Jan worksFor Acme; Acme partOf Holding (protected record);
	 * Jan mentions Report (a file).
	 *
	 * @return array{0: GraphTools, 1: array<string, string>}
	 */
	private function tools(): array {
		$graph = $this->graph();
		$ids = [
			'jan' => $this->node($graph, 'Jan Jansen', 'rec-jan'),
			'acme' => $this->node($graph, 'Acme', 'rec-acme'),
			'holding' => $this->node($graph, 'Secret Holding', 'rec-holding'),
		];
		$ids['report'] = $graph->upsertEntity(
			['label' => 'Report', 'entityType' => 'document', 'sourceType' => 'file', 'sourceRef' => ['fileId' => 7, 'path' => '/r.pdf']]
		);
		$graph->upsertRelation(['fromEntity' => $ids['jan'], 'predicate' => 'worksFor', 'toEntity' => $ids['acme']]);
		$graph->upsertRelation(['fromEntity' => $ids['acme'], 'predicate' => 'partOf', 'toEntity' => $ids['holding']]);
		$graph->upsertRelation(['fromEntity' => $ids['jan'], 'predicate' => 'mentions', 'toEntity' => $ids['report']]);
		$this->readable = ['rec-jan', 'rec-acme', 'file:7'];

		return [new GraphTools($graph), $ids];

	}//end tools()

	/**
	 * graphNeighbors, by label or uuid, returns only visible nodes and edges, each node
	 * with its sourceRef and nothing else of the record.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/knowledge-graph/specs/knowledge-graph/spec.md#scenario-graphneighbors-returns-an-rbac-filtered-neighborhood
	 */
	public function testNeighboursAreFilteredAndReferenceOnly(): void {
		[$tools, $ids] = $this->tools();

		$result = $tools->invoke(uid: 'alice', toolId: GraphToolDescriptors::NEIGHBORS, arguments: ['entity' => 'jan jansen', 'depth' => 2]);
		$labels = array_column($result['nodes'], 'label');
		sort($labels);
		$this->assertSame(['Acme', 'Jan Jansen', 'Report'], $labels);
		$this->assertSame(['worksFor', 'mentions'], array_column($result['edges'], 'predicate'));
		$this->assertStringNotContainsString('Holding', (string)json_encode($result));
		foreach ($result['nodes'] as $node) {
			$this->assertSame(['uuid', 'label', 'entityType', 'sourceType', 'sourceRef', 'aliases', 'confidence'], array_keys($node));
		}

		$byUuid = $tools->invoke(uid: 'alice', toolId: GraphToolDescriptors::NEIGHBORS, arguments: ['entity' => $ids['jan'], 'predicates' => ['mentions']]);
		$this->assertSame(['mentions'], array_column($byUuid['edges'], 'predicate'));
		$this->assertSame(['fileId' => 7, 'path' => '/r.pdf'], $byUuid['nodes'][1]['sourceRef']);

	}//end testNeighboursAreFilteredAndReferenceOnly()

	/**
	 * A hidden entity is "not found", by label and by uuid, so its existence does not leak.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/knowledge-graph/specs/knowledge-graph/spec.md#scenario-one-protected-endpoint-hides-the-edge
	 */
	public function testAHiddenEntityIsNotFound(): void {
		[$tools, $ids] = $this->tools();

		foreach (['Secret Holding', $ids['holding'], 'Nobody'] as $entity) {
			$result = $tools->invoke(uid: 'alice', toolId: GraphToolDescriptors::NEIGHBORS, arguments: ['entity' => $entity]);
			$this->assertSame('entity_not_found', $result['error']['code']);
		}

		$this->assertSame('invalid_argument', $tools->invoke(uid: 'alice', toolId: GraphToolDescriptors::NEIGHBORS, arguments: [])['error']['code']);

	}//end testAHiddenEntityIsNotFound()

	/**
	 * graphPath returns a visible chain, and "no path" rather than a chain with a hole.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/knowledge-graph/specs/knowledge-graph/spec.md#scenario-a-path-with-a-hidden-link-is-not-returned
	 */
	public function testPathIsVisibleOrAbsent(): void {
		[$tools, $ids] = $this->tools();

		$path = $tools->invoke(uid: 'alice', toolId: GraphToolDescriptors::PATH, arguments: ['from' => 'Acme', 'to' => 'Report']);
		$this->assertSame(['Acme', 'Jan Jansen', 'Report'], array_column($path['nodes'], 'label'));

		$this->readable = ['rec-jan', 'rec-acme', 'rec-holding', 'file:7'];
		$this->assertCount(3, $tools->invoke(uid: 'alice', toolId: GraphToolDescriptors::PATH, arguments: ['from' => 'Report', 'to' => $ids['holding']])['edges']);

		$this->readable = ['rec-acme', 'file:7'];
		$none = $tools->invoke(uid: 'alice', toolId: GraphToolDescriptors::PATH, arguments: ['from' => 'Acme', 'to' => 'Report']);
		$this->assertNull($none['path']);
		$this->assertArrayNotHasKey('nodes', $none);

	}//end testPathIsVisibleOrAbsent()

	/**
	 * The graph tools are read-only and reach an agent only through its grants: an agent
	 * granted other tools gets neither, a grant names exactly the one it grants.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/knowledge-graph/specs/knowledge-graph/spec.md#scenario-an-ungranted-graph-tool-is-not-invocable
	 */
	public function testTheGraphToolsReachAnAgentOnlyThroughItsGrants(): void {
		foreach (GraphToolDescriptors::ALL as $descriptor) {
			$this->assertTrue($descriptor['readOnlyHint']);
			$this->assertFalse($descriptor['destructiveHint']);
			$this->assertSame('read', $descriptor['scope']);
		}

		$resolver = new ToolGrantResolver();
		$catalog = array_merge(
			GraphToolDescriptors::ALL,
			[['id' => 'hermiq.recallMemory', 'readOnlyHint' => true, 'destructiveHint' => false, 'scope' => 'read']]
		);

		$this->assertSame(['hermiq.recallMemory'], array_values($resolver->resolve(['hermiq.recallMemory'], $catalog)));
		$this->assertSame([GraphToolDescriptors::NEIGHBORS], array_values($resolver->resolve([GraphToolDescriptors::NEIGHBORS], $catalog)));
		$this->assertSame([], array_values($resolver->resolve([], $catalog)));

		[$tools] = $this->tools();
		$this->assertSame('unknown_tool', $tools->invoke(uid: 'alice', toolId: 'hermiq.graphWrite', arguments: [])['error']['code']);

	}//end testTheGraphToolsReachAnAgentOnlyThroughItsGrants()
}//end class

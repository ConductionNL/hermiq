<?php

/**
 * Unit tests for GraphTraversal: bounded neighbourhood walks and shortest
 * paths over the graph, where every node and edge is checked against the
 * acting user's visibility of its source.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\Graph
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Graph;

use OCA\Hermiq\Service\Graph\GraphStore;
use OCA\Hermiq\Service\Graph\GraphTraversal;
use OCA\Hermiq\Service\Graph\GraphVisibility;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * Tests for GraphTraversal.
 *
 * @covers \OCA\Hermiq\Service\Graph\GraphTraversal
 */
class GraphTraversalTest extends TestCase {

	/**
	 * Entity uuid → payload.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $entities = [];

	/**
	 * Relation uuid → payload.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $relations = [];

	/**
	 * Source refs (JSON) the user may not see.
	 *
	 * @var list<string>
	 */
	private array $hidden = [];

	/**
	 * App config overrides.
	 *
	 * @var array<string, int>
	 */
	private array $config = [];

	/**
	 * Add a node.
	 *
	 * @param string               $uuid  The uuid.
	 * @param array<string, mixed> $extra Extra payload.
	 *
	 * @return void
	 */
	private function addNode(string $uuid, array $extra = []): void {
		$this->entities[$uuid] = $extra + ['label' => strtoupper($uuid), 'entityType' => 'person', 'sourceType' => 'mail', 'sourceRef' => ['id' => $uuid]];
	}

	/**
	 * Add an edge.
	 *
	 * @param string               $uuid  The relation uuid.
	 * @param string               $from  From entity.
	 * @param string               $to    To entity.
	 * @param array<string, mixed> $extra Extra payload.
	 *
	 * @return void
	 */
	private function addEdge(string $uuid, string $from, string $to, array $extra = []): void {
		$this->relations[$uuid] = $extra + ['fromEntity' => $from, 'toEntity' => $to, 'predicate' => 'knows'];
	}

	/**
	 * An object entity.
	 *
	 * @param string               $uuid The uuid.
	 * @param array<string, mixed> $data The payload.
	 *
	 * @return ObjectEntity
	 */
	private function object(string $uuid, array $data): ObjectEntity {
		$e = new ObjectEntity();
		$e->setUuid($uuid);
		$e->setObject($data);
		return $e;
	}

	/**
	 * The traversal over the current fixture.
	 *
	 * @return GraphTraversal
	 */
	private function traversal(): GraphTraversal {
		$store = $this->createMock(GraphStore::class);
		$store->method('entity')->willReturnCallback(fn (string $uuid): ?ObjectEntity => isset($this->entities[$uuid]) ? $this->object($uuid, $this->entities[$uuid]) : null);
		$store->method('find')->willReturnCallback(function (string $schema, array $filters): array {
			self::assertSame(GraphStore::RELATION_SCHEMA, $schema);
			$side = array_key_first($filters);
			$out = [];
			foreach ($this->relations as $uuid => $data) {
				if (($data[$side] ?? null) === $filters[$side]) {
					$out[] = $this->object($uuid, $data);
				}
			}
			return $out;
		});
		$visibility = $this->createMock(GraphVisibility::class);
		$visibility->method('isVisible')->willReturnCallback(
			fn (string $type, array $ref, string $uid): bool => $uid === 'alice' && in_array(json_encode($ref), $this->hidden, true) === false
		);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueInt')->willReturnCallback(fn (string $app, string $key, int $default): int => ($this->config[$key] ?? $default));
		return new GraphTraversal($store, $visibility, $appConfig);
	}

	/**
	 * Uuids of a node list.
	 *
	 * @param array<array-key, array<string, mixed>> $nodes The nodes.
	 *
	 * @return list<string>
	 */
	private static function uuids(array $nodes): array {
		return array_values(array_map(static fn (array $n): string => $n['uuid'], $nodes));
	}

	/**
	 * A visible node is described with its fields; aliases keep only strings.
	 *
	 * @return void
	 */
	public function testVisibleDescribesTheNode(): void {
		$this->addNode('a', ['aliases' => ['Al', 3, 'Ally'], 'confidence' => '0.5']);

		self::assertSame([
			'uuid' => 'a',
			'label' => 'A',
			'entityType' => 'person',
			'sourceType' => 'mail',
			'sourceRef' => ['id' => 'a'],
			'aliases' => ['Al', 'Ally'],
			'confidence' => 0.5,
		], $this->traversal()->visible('a', 'alice'));
	}

	/**
	 * An unknown, empty or invisible node is null.
	 *
	 * @return void
	 */
	public function testInvisibleNodesAreNull(): void {
		$this->addNode('a');
		$t = $this->traversal();

		self::assertNull($t->visible('missing', 'alice'));
		self::assertNull($t->visible('', 'alice'));
		self::assertNull($t->visible('a', 'bob'));
	}

	/**
	 * Neighbours follow edges both ways, one hop by default cap of depth.
	 *
	 * @return void
	 */
	public function testNeighboursOneHopBothDirections(): void {
		foreach (['a', 'b', 'c', 'd'] as $n) {
			$this->addNode($n);
		}
		$this->addEdge('ab', 'a', 'b', ['confidence' => 0.9]);
		$this->addEdge('ca', 'c', 'a');
		$this->addEdge('bd', 'b', 'd');

		$result = $this->traversal()->neighbors('a', 'alice', 1, []);

		self::assertSame(['a', 'b', 'c'], self::uuids($result['nodes']));
		self::assertSame([
			['uuid' => 'ab', 'from' => 'a', 'predicate' => 'knows', 'to' => 'b', 'confidence' => 0.9],
			['uuid' => 'ca', 'from' => 'c', 'predicate' => 'knows', 'to' => 'a', 'confidence' => 1.0],
		], $result['edges']);
	}

	/**
	 * Depth is capped by graph_max_depth (default 2) and floored at 1.
	 *
	 * @return void
	 */
	public function testDepthIsClamped(): void {
		foreach (['a', 'b', 'c', 'd'] as $n) {
			$this->addNode($n);
		}
		$this->addEdge('ab', 'a', 'b');
		$this->addEdge('bc', 'b', 'c');
		$this->addEdge('cd', 'c', 'd');

		self::assertSame(['a', 'b', 'c'], self::uuids($this->traversal()->neighbors('a', 'alice', 9, [])['nodes']));
		self::assertSame(['a', 'b'], self::uuids($this->traversal()->neighbors('a', 'alice', 0, [])['nodes']));

		$this->config['graph_max_depth'] = 3;
		self::assertSame(['a', 'b', 'c', 'd'], self::uuids($this->traversal()->neighbors('a', 'alice', 9, [])['nodes']));
	}

	/**
	 * Predicates filter edges; low-confidence edges fall under the configured floor.
	 *
	 * @return void
	 */
	public function testPredicateAndConfidenceFilters(): void {
		foreach (['a', 'b', 'c', 'd'] as $n) {
			$this->addNode($n);
		}
		$this->addEdge('ab', 'a', 'b', ['predicate' => 'employs']);
		$this->addEdge('ac', 'a', 'c', ['predicate' => 'knows', 'confidence' => 0.3]);
		$this->addEdge('ad', 'a', 'd', ['predicate' => 'knows', 'confidence' => 0.8]);

		self::assertSame(['a', 'b'], self::uuids($this->traversal()->neighbors('a', 'alice', 1, ['employs'])['nodes']));

		$this->config['graph_min_confidence_percent'] = 50;
		self::assertSame(['a', 'b', 'd'], self::uuids($this->traversal()->neighbors('a', 'alice', 1, [])['nodes']));
	}

	/**
	 * An edge to an invisible node, or from an invisible source, is left out.
	 *
	 * @return void
	 */
	public function testVisibilityPrunesNodesAndEdges(): void {
		foreach (['a', 'b', 'c', 'd'] as $n) {
			$this->addNode($n);
		}
		$this->addEdge('ab', 'a', 'b');
		$this->addEdge('ac', 'a', 'c', ['sourceType' => 'mail', 'sourceRef' => ['id' => 'secret-mail']]);
		$this->addEdge('ad', 'a', 'd', ['sourceRef' => []]);
		$this->hidden = [json_encode(['id' => 'b']), json_encode(['id' => 'secret-mail'])];

		$result = $this->traversal()->neighbors('a', 'alice', 1, []);

		self::assertSame(['a', 'd'], self::uuids($result['nodes']));
		self::assertSame(['ad'], array_column($result['edges'], 'uuid'));
	}

	/**
	 * A start the user cannot see yields an empty walk.
	 *
	 * @return void
	 */
	public function testAnInvisibleStartIsEmpty(): void {
		$this->addNode('a');
		self::assertSame(['nodes' => [], 'edges' => []], $this->traversal()->neighbors('a', 'bob', 2, []));
	}

	/**
	 * Node and edge caps bound the walk.
	 *
	 * @return void
	 */
	public function testNodeAndEdgeCaps(): void {
		$this->addNode('a');
		foreach (['b', 'c', 'd'] as $n) {
			$this->addNode($n);
			$this->addEdge('a' . $n, 'a', $n);
		}
		$this->config['graph_max_nodes'] = 3;
		$this->config['graph_max_edges'] = 1;

		$result = $this->traversal()->neighbors('a', 'alice', 1, []);

		self::assertSame(['a', 'b', 'c'], self::uuids($result['nodes']));
		self::assertSame(['ab'], array_column($result['edges'], 'uuid'));
	}

	/**
	 * Path returns the shortest chain of nodes and edges between two nodes.
	 *
	 * @return void
	 */
	public function testPathFindsTheShortestChain(): void {
		foreach (['a', 'b', 'c', 'd'] as $n) {
			$this->addNode($n);
		}
		$this->addEdge('ab', 'a', 'b');
		$this->addEdge('cb', 'c', 'b');
		$this->addEdge('cd', 'c', 'd');
		$this->addEdge('ax', 'a', 'x');

		$path = $this->traversal()->path('a', 'd', 'alice', 5);

		self::assertSame(['a', 'b', 'c', 'd'], self::uuids($path['nodes']));
		self::assertSame(['ab', 'cb', 'cd'], array_column($path['edges'], 'uuid'));
	}

	/**
	 * Too few hops, no connection, or an invisible end means no path.
	 *
	 * @return void
	 */
	public function testNoPath(): void {
		foreach (['a', 'b', 'c', 'z'] as $n) {
			$this->addNode($n);
		}
		$this->addEdge('ab', 'a', 'b');
		$this->addEdge('bc', 'b', 'c');

		self::assertNull($this->traversal()->path('a', 'c', 'alice', 1));
		self::assertNull($this->traversal()->path('a', 'z', 'alice', 6));
		self::assertNull($this->traversal()->path('a', 'c', 'bob', 6));
		self::assertNull($this->traversal()->path('a', 'c', 'alice', 0), 'zero hops is clamped to one');
		self::assertSame(['a', 'b', 'c'], self::uuids($this->traversal()->path('a', 'c', 'alice', 2)['nodes']));
	}

	/**
	 * The node cap also bounds a path search.
	 *
	 * @return void
	 */
	public function testPathRespectsTheNodeCap(): void {
		foreach (['a', 'b', 'c'] as $n) {
			$this->addNode($n);
		}
		$this->addEdge('ab', 'a', 'b');
		$this->addEdge('bc', 'b', 'c');
		$this->config['graph_max_nodes'] = 2;

		self::assertNull($this->traversal()->path('a', 'c', 'alice', 6));
	}
}

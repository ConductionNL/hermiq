<?php

/**
 * GraphService: reference-only upserts, deterministic resolution, and traversal that
 * returns only what the acting user's records allow (knowledge-graph).
 *
 * The store is an in-memory double of ObjectService that honours the register,
 * schema, filters and uuids GraphService passes; visibility is decided per record
 * (sourceRef uuid or fileId) through a GraphVisibility double.
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
 * @spec openspec/changes/knowledge-graph/specs/knowledge-graph/spec.md#requirement-an-edge-is-visible-only-when-both-endpoints-are
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Graph;

use InvalidArgumentException;
use OCA\Hermiq\Service\Graph\GraphService;
use OCA\Hermiq\Service\Graph\GraphVisibility;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for GraphService.
 *
 * @spec openspec/changes/knowledge-graph/specs/knowledge-graph/spec.md#requirement-an-edge-is-visible-only-when-both-endpoints-are
 */
class GraphServiceTest extends TestCase {

	/**
	 * Stored objects per schema slug, keyed by uuid.
	 *
	 * @var array<string, array<string, array<string, mixed>>>
	 */
	private array $store = [];

	/**
	 * Every saveObject call, in order: schema, uuid and payload.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $writes = [];

	/**
	 * Every read call's RBAC flag.
	 *
	 * @var array<int, bool>
	 */
	private array $readRbac = [];

	/**
	 * Record keys (sourceRef uuid, or "file:<id>") the acting user can read.
	 *
	 * @var array<int, string>
	 */
	private array $readable = [];

	/**
	 * Build the service over the in-memory store.
	 *
	 * @param array<string, int> $config App config integers by key.
	 *
	 * @return GraphService
	 */
	private function graph(array $config = []): GraphService {
		$schema = '';
		$objects = $this->createMock(ObjectService::class);
		$objects->method('setRegister')->willReturnSelf();
		$objects->method('setSchema')->willReturnCallback(
			static function (mixed $slug) use (&$schema, $objects): ObjectService {
				$schema = (string)$slug;

				return $objects;
			}
		);
		$objects->method('findAll')->willReturnCallback(
			function (array $config = [], bool $_rbac = true) use (&$schema): array {
				$this->readRbac[] = $_rbac;
				$out = [];
				foreach (($this->store[$schema] ?? []) as $uuid => $data) {
					foreach (($config['filters'] ?? []) as $field => $value) {
						if (($data[$field] ?? null) !== $value) {
							continue 2;
						}
					}

					$out[] = $this->entity(uuid: $uuid, data: $data);
				}

				return array_slice($out, 0, (int)($config['limit'] ?? 1000));
			}
		);
		$objects->method('find')->willReturnCallback(
			function (int|string $id, ?array $_extend = [], bool $files = false, mixed $register = null, mixed $schemaArg = null, bool $_rbac = true): ?ObjectEntity {
				$this->readRbac[] = $_rbac;
				$data = ($this->store[(string)$schemaArg][(string)$id] ?? null);

				return ($data === null) ? null : $this->entity(uuid: (string)$id, data: $data);
			}
		);
		$objects->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend = [], mixed $register = null, mixed $schemaArg = null, ?string $uuid = null): ObjectEntity {
				$uuid = ($uuid ?? sprintf('00000000-0000-4000-8000-%012d', count($this->writes) + 1));
				$this->writes[] = ['schema' => $schemaArg, 'uuid' => $uuid, 'object' => $object];
				$this->store[(string)$schemaArg][$uuid] = $object;

				return $this->entity(uuid: $uuid, data: $object);
			}
		);

		$visibility = $this->createMock(GraphVisibility::class);
		$visibility->method('isVisible')->willReturnCallback(
			function (string $sourceType, array $sourceRef, string $uid): bool {
				$key = ($sourceType === 'file') ? 'file:' . ($sourceRef['fileId'] ?? '') : (string)($sourceRef['uuid'] ?? '');

				return $uid === 'alice' && in_array($key, $this->readable, true);
			}
		);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueInt')->willReturnCallback(
			static fn (string $app, string $key, int $default = 0): int => ($config[$key] ?? $default)
		);

		return new GraphService($objects, $visibility, $appConfig, $this->createMock(LoggerInterface::class));

	}//end graph()

	/**
	 * An ObjectEntity carrying the data.
	 *
	 * @param string $uuid The uuid.
	 * @param array<string, mixed> $data The payload.
	 *
	 * @return ObjectEntity
	 */
	private function entity(string $uuid, array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setObject($data);

		return $entity;
	}//end entity()

	/**
	 * Upsert an entity referencing an object with the given uuid.
	 *
	 * @param GraphService $graph The service.
	 * @param string $label The label.
	 * @param string $record The referenced object uuid.
	 *
	 * @return string The entity uuid.
	 */
	private function node(GraphService $graph, string $label, string $record): string {
		return $graph->upsertEntity(
			[
				'label' => $label,
				'entityType' => 'person',
				'sourceType' => 'object',
				'sourceRef' => ['register' => 'crm', 'schema' => 'contact', 'uuid' => $record],
				'confidence' => 0.9,
				'extractedBy' => 'test@1',
			]
		);
	}//end node()

	/**
	 * A node from an object is a typed reference: only label, type, aliases and
	 * provenance are stored, and sourceRef keeps only the pointer keys of its type.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/knowledge-graph/specs/knowledge-graph/spec.md#requirement-graph-nodes-reference-records-and-never-copy-them
	 */
	public function testANodeIsAReferenceNeverACopy(): void {
		$this->graph()->upsertEntity(
			[
				'label' => 'Jan Jansen',
				'entityType' => 'person',
				'sourceType' => 'object',
				'sourceRef' => ['register' => 'crm', 'schema' => 'contact', 'uuid' => 'c-1', 'data' => ['bsn' => '123']],
				'text' => 'the whole record body',
				'content' => 'more content',
				'confidence' => 0.8,
				'extractedBy' => 'test@1',
			]
		);

		$this->assertCount(1, $this->writes);
		$this->assertSame(GraphService::ENTITY_SCHEMA, $this->writes[0]['schema']);
		$stored = $this->writes[0]['object'];
		$this->assertSame(
			['label', 'labelKey', 'entityType', 'sourceType', 'sourceRef', 'aliases', 'confidence', 'extractedBy'],
			array_keys($stored)
		);
		$this->assertSame(['register' => 'crm', 'schema' => 'contact', 'uuid' => 'c-1'], $stored['sourceRef']);
		$this->assertStringNotContainsString('123', (string)json_encode($stored));

	}//end testANodeIsAReferenceNeverACopy()

	/**
	 * Upserting the same label (other case and spacing) and type resolves to the same
	 * node and only merges the proposed aliases; a relation upsert is idempotent and
	 * flags a predicate outside the seed vocabulary as custom.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/knowledge-graph/specs/knowledge-graph/spec.md#requirement-extraction-runs-as-the-acting-user-in-audited-background-jobs
	 */
	public function testUpsertsAreIdempotent(): void {
		$graph = $this->graph();
		$first = $this->node($graph, 'Jan Jansen', 'c-1');
		$again = $graph->upsertEntity(
			[
				'label' => '  jan JANSEN ',
				'entityType' => 'person',
				'sourceType' => 'object',
				'sourceRef' => ['register' => 'crm', 'schema' => 'contact', 'uuid' => 'c-1'],
				'aliases' => ['J. Jansen'],
			]
		);
		$this->assertSame($first, $again);
		$this->assertCount(1, $this->store[GraphService::ENTITY_SCHEMA]);
		$this->assertSame(['J. Jansen'], $this->store[GraphService::ENTITY_SCHEMA][$first]['aliases']);

		$writes = count($this->writes);
		$this->assertSame($first, $this->node($graph, 'Jan Jansen', 'c-1'));
		$this->assertCount($writes, $this->writes, 'nothing new to merge, so nothing is written');

		$other = $this->node($graph, 'Acme', 'c-2');
		$edge = $graph->upsertRelation(['fromEntity' => $first, 'predicate' => 'worksFor', 'toEntity' => $other]);
		$this->assertSame($edge, $graph->upsertRelation(['fromEntity' => $first, 'predicate' => 'worksFor', 'toEntity' => $other]));
		$this->assertCount(1, $this->store[GraphService::RELATION_SCHEMA]);
		$this->assertFalse($this->store[GraphService::RELATION_SCHEMA][$edge]['custom']);

		$custom = $graph->upsertRelation(['fromEntity' => $first, 'predicate' => 'playsGolfWith', 'toEntity' => $other]);
		$this->assertTrue($this->store[GraphService::RELATION_SCHEMA][$custom]['custom']);

	}//end testUpsertsAreIdempotent()

	/**
	 * An incomplete proposal is refused before anything is written.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/knowledge-graph/specs/knowledge-graph/spec.md#requirement-graph-nodes-reference-records-and-never-copy-them
	 */
	public function testAnIncompleteProposalIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		try {
			$this->graph()->upsertEntity(['label' => 'X', 'entityType' => 'topic', 'sourceType' => 'web', 'sourceRef' => ['uuid' => 'a']]);
		} finally {
			$this->assertSame([], $this->writes);
		}

	}//end testAnIncompleteProposalIsRefused()

	/**
	 * Both endpoints readable: the edge and both nodes come back, with their sourceRef.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/knowledge-graph/specs/knowledge-graph/spec.md#requirement-an-edge-is-visible-only-when-both-endpoints-are
	 */
	public function testBothEndpointsReadableReturnsTheEdge(): void {
		$graph = $this->graph();
		$a = $this->node($graph, 'Jan', 'rec-a');
		$b = $this->node($graph, 'Acme', 'rec-b');
		$graph->upsertRelation(['fromEntity' => $a, 'predicate' => 'worksFor', 'toEntity' => $b]);
		$this->readable = ['rec-a', 'rec-b'];

		$result = $graph->neighbors(entityUuid: $a, actingUserId: 'alice', depth: 1);

		$this->assertSame([$a, $b], array_keys($result['nodes']));
		$this->assertSame(['register' => 'crm', 'schema' => 'contact', 'uuid' => 'rec-b'], $result['nodes'][$b]['sourceRef']);
		$this->assertCount(1, $result['edges']);
		$this->assertSame(['from' => $a, 'predicate' => 'worksFor', 'to' => $b], array_intersect_key($result['edges'][0], ['from' => 1, 'predicate' => 1, 'to' => 1]));
		$this->assertNotContains(true, $this->readRbac, 'graph objects are read without their own RBAC; the records decide');

	}//end testBothEndpointsReadableReturnsTheEdge()

	/**
	 * One protected endpoint hides the edge, and the hidden node's label, uuid and
	 * sourceRef appear nowhere in the result; an invisible start returns nothing.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/knowledge-graph/specs/knowledge-graph/spec.md#requirement-an-edge-is-visible-only-when-both-endpoints-are
	 */
	public function testOneProtectedEndpointHidesTheEdge(): void {
		$graph = $this->graph();
		$a = $this->node($graph, 'Jan', 'rec-a');
		$b = $this->node($graph, 'Secret Supplier', 'rec-b');
		$graph->upsertRelation(['fromEntity' => $b, 'predicate' => 'relatesTo', 'toEntity' => $a]);
		$this->readable = ['rec-a'];

		$result = $graph->neighbors(entityUuid: $a, actingUserId: 'alice', depth: 2);
		$flat = (string)json_encode($result);

		$this->assertSame([$a], array_keys($result['nodes']));
		$this->assertSame([], $result['edges']);
		$this->assertStringNotContainsString('Secret Supplier', $flat);
		$this->assertStringNotContainsString($b, $flat);
		$this->assertStringNotContainsString('rec-b', $flat);

		$this->assertSame(['nodes' => [], 'edges' => []], $graph->neighbors(entityUuid: $b, actingUserId: 'alice'));
		$this->assertSame(['nodes' => [], 'edges' => []], $graph->neighbors(entityUuid: $a, actingUserId: 'bob'));

	}//end testOneProtectedEndpointHidesTheEdge()

	/**
	 * A relation extracted from a record the user cannot read stays hidden even when
	 * both endpoints are visible; a predicate filter narrows the neighbourhood.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/knowledge-graph/specs/knowledge-graph/spec.md#requirement-an-edge-is-visible-only-when-both-endpoints-are
	 */
	public function testAnEdgeFromAnUnreadableSourceIsHidden(): void {
		$graph = $this->graph();
		$a = $this->node($graph, 'Jan', 'rec-a');
		$b = $this->node($graph, 'Acme', 'rec-b');
		$graph->upsertRelation(['fromEntity' => $a, 'predicate' => 'mentions', 'toEntity' => $b, 'sourceType' => 'file', 'sourceRef' => ['fileId' => 9]]);
		$graph->upsertRelation(['fromEntity' => $a, 'predicate' => 'worksFor', 'toEntity' => $b]);
		$this->readable = ['rec-a', 'rec-b'];

		$result = $graph->neighbors(entityUuid: $a, actingUserId: 'alice');
		$this->assertSame(['worksFor'], array_column($result['edges'], 'predicate'));

		$this->readable[] = 'file:9';
		$this->assertCount(2, $graph->neighbors(entityUuid: $a, actingUserId: 'alice')['edges']);
		$this->assertSame(['mentions'], array_column($graph->neighbors(entityUuid: $a, actingUserId: 'alice', predicates: ['mentions'])['edges'], 'predicate'));

	}//end testAnEdgeFromAnUnreadableSourceIsHidden()

	/**
	 * A path with a hidden link is never returned; a path over visible edges only is.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/knowledge-graph/specs/knowledge-graph/spec.md#requirement-an-edge-is-visible-only-when-both-endpoints-are
	 */
	public function testAPathWithAHiddenLinkIsNotReturned(): void {
		$graph = $this->graph();
		$a = $this->node($graph, 'A', 'rec-a');
		$b = $this->node($graph, 'B', 'rec-b');
		$c = $this->node($graph, 'C', 'rec-c');
		$d = $this->node($graph, 'D', 'rec-d');
		$graph->upsertRelation(['fromEntity' => $a, 'predicate' => 'relatesTo', 'toEntity' => $b]);
		$graph->upsertRelation(['fromEntity' => $b, 'predicate' => 'relatesTo', 'toEntity' => $c, 'sourceType' => 'file', 'sourceRef' => ['fileId' => 5]]);
		$this->readable = ['rec-a', 'rec-b', 'rec-c', 'rec-d'];

		$this->assertNull($graph->path(fromUuid: $a, toUuid: $c, actingUserId: 'alice', maxHops: 4));

		$graph->upsertRelation(['fromEntity' => $a, 'predicate' => 'relatesTo', 'toEntity' => $d]);
		$graph->upsertRelation(['fromEntity' => $d, 'predicate' => 'partOf', 'toEntity' => $c]);
		$path = $graph->path(fromUuid: $a, toUuid: $c, actingUserId: 'alice', maxHops: 4);
		$this->assertNotNull($path);
		$this->assertSame([$a, $d, $c], array_column($path['nodes'], 'uuid'));
		$this->assertSame(['relatesTo', 'partOf'], array_column($path['edges'], 'predicate'));

		$this->assertNull($graph->path(fromUuid: $a, toUuid: $c, actingUserId: 'alice', maxHops: 1));

	}//end testAPathWithAHiddenLinkIsNotReturned()

	/**
	 * Traversal is bounded: depth is capped by config and the node cap stops the walk.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/knowledge-graph/specs/knowledge-graph/spec.md#requirement-graph-traversal-is-available-to-context-assembly
	 */
	public function testTraversalIsBounded(): void {
		$graph = $this->graph(['graph_max_depth' => 1, 'graph_max_nodes' => 3]);
		$hub = $this->node($graph, 'Hub', 'rec-hub');
		$this->readable = ['rec-hub'];
		$previous = $hub;
		for ($i = 1; $i <= 5; $i++) {
			$leaf = $this->node($graph, 'Leaf ' . $i, 'rec-' . $i);
			$graph->upsertRelation(['fromEntity' => $hub, 'predicate' => 'relatesTo', 'toEntity' => $leaf]);
			$this->readable[] = 'rec-' . $i;
			$chain = $this->node($graph, 'Far ' . $i, 'far-' . $i);
			$graph->upsertRelation(['fromEntity' => $leaf, 'predicate' => 'relatesTo', 'toEntity' => $chain]);
			$this->readable[] = 'far-' . $i;
			$previous = $leaf;
		}

		$result = $graph->neighbors(entityUuid: $hub, actingUserId: 'alice', depth: 5);
		$this->assertCount(3, $result['nodes']);
		$this->assertStringNotContainsString('Far', (string)json_encode($result));
		$this->assertNotSame($hub, $previous);

	}//end testTraversalIsBounded()
}//end class

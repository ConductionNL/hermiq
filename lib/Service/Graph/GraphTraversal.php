<?php

/**
 * Hermiq GraphTraversal.
 *
 * Walks the knowledge graph for one acting user and returns only what that user's
 * records allow: a node while its record is readable, an edge only when both
 * endpoints are returned and the record it was extracted from (if any) is readable,
 * and a path only when every edge on it is. Nothing hidden leaks: not a label, not a
 * uuid, not a sourceRef. Depth, node and edge caps come from app config with safe
 * defaults, and a relation below the configured confidence floor is skipped.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Graph
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/knowledge-graph/specs/knowledge-graph/spec.md#requirement-an-edge-is-visible-only-when-both-endpoints-are
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Graph;

use OCA\Hermiq\AppInfo\Application;
use OCP\IAppConfig;

/**
 * Record-derived, bounded graph traversal.
 *
 * @spec openspec/changes/knowledge-graph/specs/knowledge-graph/spec.md#requirement-an-edge-is-visible-only-when-both-endpoints-are
 */
class GraphTraversal {

	/**
	 * Node views of the current read, by uuid; null for a hidden or missing node.
	 *
	 * @var array<string, array<string, mixed>|null>
	 */
	private array $seen = [];

	/**
	 * The neighbourhood being walked: node views by uuid.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $walkNodes = [];

	/**
	 * The neighbourhood being walked: edge views by uuid.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $walkEdges = [];

	/**
	 * The path search's parent links: node uuid to the node and edge it was reached from, null for the start.
	 *
	 * @var array<string, array{node: string, edge: array<string, mixed>}|null>
	 */
	private array $via = [];

	/**
	 * Constructor.
	 *
	 * @param GraphStore $store Graph object access.
	 * @param GraphVisibility $visibility Record-derived visibility.
	 * @param IAppConfig $appConfig App config (caps).
	 */
	public function __construct(
		private readonly GraphStore $store,
		private readonly GraphVisibility $visibility,
		private readonly IAppConfig $appConfig,
	) {
	}//end __construct()

	/**
	 * The visible neighbourhood of an entity, breadth first and bounded.
	 *
	 * @param string $entityUuid The start entity.
	 * @param string $uid The acting user.
	 * @param int $depth Hops to walk; capped by graph_max_depth (default 2).
	 * @param array<int, string> $predicates Only these predicates, when given.
	 *
	 * @return array{nodes: array<string, array<string, mixed>>, edges: array<int, array<string, mixed>>}
	 *
	 * @spec openspec/changes/knowledge-graph/specs/knowledge-graph/spec.md#requirement-an-edge-is-visible-only-when-both-endpoints-are
	 */
	public function neighbors(string $entityUuid, string $uid, int $depth, array $predicates): array {
		$this->seen = [];
		$start = $this->node(uuid: $entityUuid, uid: $uid);
		if ($start === null) {
			return ['nodes' => [], 'edges' => []];
		}

		$depth = max(1, min($depth, $this->cap(key: 'graph_max_depth', default: 2)));
		$this->walkNodes = [$entityUuid => $start];
		$this->walkEdges = [];
		$frontier = [$entityUuid];
		for ($hop = 0; $hop < $depth && $frontier !== []; $hop++) {
			$next = [];
			foreach ($frontier as $uuid) {
				$next = array_merge($next, $this->expand(uuid: $uuid, uid: $uid, predicates: $predicates));
			}

			$frontier = $next;
		}

		return ['nodes' => $this->walkNodes, 'edges' => $this->walkedEdges()];

	}//end neighbors()

	/**
	 * The shortest path between two entities over visible edges only.
	 *
	 * @param string $fromUuid The start entity.
	 * @param string $toUuid The end entity.
	 * @param string $uid The acting user.
	 * @param int $maxHops The longest path to accept (at most 6).
	 *
	 * @return array{nodes: array<int, array<string, mixed>>, edges: array<int, array<string, mixed>>}|null
	 *
	 * @spec openspec/changes/knowledge-graph/specs/knowledge-graph/spec.md#requirement-an-edge-is-visible-only-when-both-endpoints-are
	 */
	public function path(string $fromUuid, string $toUuid, string $uid, int $maxHops): ?array {
		$this->seen = [];
		if ($this->node(uuid: $fromUuid, uid: $uid) === null || $this->node(uuid: $toUuid, uid: $uid) === null) {
			return null;
		}

		$maxHops = max(1, min($maxHops, 6));
		$maxNodes = $this->cap(key: 'graph_max_nodes', default: 50);
		$this->via = [$fromUuid => null];
		$frontier = [$fromUuid];
		for ($hop = 0; $hop < $maxHops && $frontier !== [] && array_key_exists($toUuid, $this->via) === false; $hop++) {
			$next = [];
			foreach ($frontier as $uuid) {
				$next = array_merge($next, $this->link(uuid: $uuid, uid: $uid, maxNodes: $maxNodes));
			}

			$frontier = $next;
		}

		if (array_key_exists($toUuid, $this->via) === false) {
			return null;
		}

		return $this->walkBack(toUuid: $toUuid);

	}//end path()

	/**
	 * Record a parent link for every unvisited visible neighbour of a node.
	 *
	 * @param string $uuid The node being expanded.
	 * @param string $uid The acting user.
	 * @param int $maxNodes The node cap.
	 *
	 * @return array<int, string> The neighbours linked.
	 */
	private function link(string $uuid, string $uid, int $maxNodes): array {
		$added = [];
		foreach ($this->edges(uuid: $uuid, uid: $uid, predicates: []) as $edge) {
			$other = $this->farEnd(edge: $edge, uuid: $uuid);
			if (array_key_exists($other, $this->via) === false && count($this->via) < $maxNodes) {
				$this->via[$other] = ['node' => $uuid, 'edge' => $edge];
				$added[] = $other;
			}
		}

		return $added;

	}//end link()

	/**
	 * Add one node's visible edges and new neighbours to the walk, within the caps.
	 *
	 * @param string $uuid The node being expanded.
	 * @param string $uid The acting user.
	 * @param array<int, string> $predicates Only these predicates, when given.
	 *
	 * @return array<int, string> The neighbours added.
	 */
	private function expand(string $uuid, string $uid, array $predicates): array {
		$maxNodes = $this->cap(key: 'graph_max_nodes', default: 50);
		$maxEdges = $this->cap(key: 'graph_max_edges', default: 100);
		$added = [];
		foreach ($this->edges(uuid: $uuid, uid: $uid, predicates: $predicates) as $edge) {
			$other = $this->farEnd(edge: $edge, uuid: $uuid);
			if (isset($this->walkNodes[$other]) === false && count($this->walkNodes) < $maxNodes) {
				$this->walkNodes[$other] = (array)$this->seen[$other];
				$added[] = $other;
			}

			if (isset($this->walkNodes[$other]) === true && count($this->walkEdges) < $maxEdges) {
				$this->walkEdges[(string)$edge['uuid']] = $edge;
			}
		}

		return $added;

	}//end expand()

	/**
	 * The edges the last neighbourhood walk collected, in order.
	 *
	 * @return array<int, array<string, mixed>> Edge views.
	 */
	private function walkedEdges(): array {
		return array_values($this->walkEdges);

	}//end walkedEdges()

	/**
	 * The endpoint of an edge that is not the given node.
	 *
	 * @param array<string, mixed> $edge The edge view.
	 * @param string $uuid The near node.
	 *
	 * @return string The far node.
	 */
	private function farEnd(array $edge, string $uuid): string {
		if ($edge['from'] === $uuid) {
			return (string)$edge['to'];
		}

		return (string)$edge['from'];

	}//end farEnd()

	/**
	 * Rebuild the path from the breadth-first parent links.
	 *
	 * @param string $toUuid The end entity.
	 *
	 * @return array{nodes: array<int, array<string, mixed>>, edges: array<int, array<string, mixed>>}
	 */
	private function walkBack(string $toUuid): array {
		$nodes = [$this->seen[$toUuid]];
		$edges = [];
		$link = $this->via[$toUuid];
		while ($link !== null) {
			array_unshift($edges, $link['edge']);
			array_unshift($nodes, $this->seen[$link['node']]);
			$link = $this->via[$link['node']];
		}

		return ['nodes' => $nodes, 'edges' => $edges];

	}//end walkBack()

	/**
	 * The visible edges touching a node, each with a visible far endpoint.
	 *
	 * @param string $uuid The node.
	 * @param string $uid The acting user.
	 * @param array<int, string> $predicates Only these predicates, when given.
	 *
	 * @return array<int, array<string, mixed>> Edge views.
	 */
	private function edges(string $uuid, string $uid, array $predicates): array {
		$out = [];
		foreach (['fromEntity' => 'toEntity', 'toEntity' => 'fromEntity'] as $side => $farSide) {
			foreach ($this->store->find(schema: GraphStore::RELATION_SCHEMA, filters: [$side => $uuid]) as $relation) {
				$data = $relation->getObject();
				if ($this->wanted(data: $data, predicates: $predicates) === false
					|| $this->node(uuid: (string)($data[$farSide] ?? ''), uid: $uid) === null
					|| $this->sourceReadable(data: $data, uid: $uid) === false
				) {
					continue;
				}

				$out[] = [
					'uuid' => (string)$relation->getUuid(),
					'from' => (string)($data['fromEntity'] ?? ''),
					'predicate' => (string)($data['predicate'] ?? ''),
					'to' => (string)($data['toEntity'] ?? ''),
					'confidence' => (float)($data['confidence'] ?? 1),
				];
			}
		}

		return $out;

	}//end edges()

	/**
	 * Whether a relation passes the predicate filter and the confidence floor.
	 *
	 * @param array<string, mixed> $data The relation payload.
	 * @param array<int, string> $predicates Only these predicates, when given.
	 *
	 * @return bool Whether it is wanted.
	 */
	private function wanted(array $data, array $predicates): bool {
		if ($predicates !== [] && in_array((string)($data['predicate'] ?? ''), $predicates, true) === false) {
			return false;
		}

		$floor = ($this->cap(key: 'graph_min_confidence_percent', default: 0) / 100);

		return (float)($data['confidence'] ?? 1) >= $floor;

	}//end wanted()

	/**
	 * Whether the record a relation was extracted from, if any, is readable.
	 *
	 * @param array<string, mixed> $data The relation payload.
	 * @param string $uid The acting user.
	 *
	 * @return bool True when there is no source record or it is readable.
	 */
	private function sourceReadable(array $data, string $uid): bool {
		$sourceRef = ($data['sourceRef'] ?? null);
		if (is_array($sourceRef) === false || $sourceRef === []) {
			return true;
		}

		return $this->visibility->isVisible((string)($data['sourceType'] ?? ''), $sourceRef, $uid);

	}//end sourceReadable()

	/**
	 * The node view of an entity while the acting user can read its record, cached per read.
	 *
	 * @param string $uuid The entity uuid.
	 * @param string $uid The acting user.
	 *
	 * @return array<string, mixed>|null The node view, or null when hidden or gone.
	 */
	private function node(string $uuid, string $uid): ?array {
		if (array_key_exists($uuid, $this->seen) === true) {
			return $this->seen[$uuid];
		}

		$this->seen[$uuid] = null;
		$entity = null;
		if ($uuid !== '') {
			$entity = $this->store->entity(uuid: $uuid);
		}

		if ($entity === null) {
			return null;
		}

		$data = $entity->getObject();
		$sourceRef = (array)($data['sourceRef'] ?? []);
		if ($this->visibility->isVisible((string)($data['sourceType'] ?? ''), $sourceRef, $uid) === false) {
			return null;
		}

		$this->seen[$uuid] = [
			'uuid' => $uuid,
			'label' => (string)($data['label'] ?? ''),
			'entityType' => (string)($data['entityType'] ?? ''),
			'sourceType' => (string)($data['sourceType'] ?? ''),
			'sourceRef' => $sourceRef,
			'aliases' => array_values(array_filter((array)($data['aliases'] ?? []), 'is_string')),
			'confidence' => (float)($data['confidence'] ?? 1),
		];

		return $this->seen[$uuid];

	}//end node()

	/**
	 * An app-config integer cap.
	 *
	 * @param string $key The config key.
	 * @param int $default The default.
	 *
	 * @return int The cap.
	 */
	private function cap(string $key, int $default): int {
		return $this->appConfig->getValueInt(Application::APP_ID, $key, $default);

	}//end cap()
}//end class

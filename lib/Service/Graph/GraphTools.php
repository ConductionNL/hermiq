<?php

/**
 * Hermiq GraphTools.
 *
 * Serves `hermiq.graphNeighbors` and `hermiq.graphPath` for the acting user. The
 * provider routes both ids here; the grant set decided before that whether the model
 * could call them at all. Results carry labels, relation names and sourceRef pointers
 * of what GraphService lets this user see, never record content. An entity the user
 * cannot see is "not found", exactly like one that does not exist.
 *
 * Never throws: every failure is `['error' => ['code', 'message']]`.
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
 * @spec openspec/changes/knowledge-graph/specs/knowledge-graph/spec.md#requirement-graph-traversal-is-exposed-as-governed-agent-tools
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Graph;

use OCA\Hermiq\Mcp\GraphToolDescriptors;
use Throwable;

/**
 * The knowledge-graph tool handlers.
 *
 * @spec openspec/changes/knowledge-graph/specs/knowledge-graph/spec.md#requirement-graph-traversal-is-exposed-as-governed-agent-tools
 */
class GraphTools {

	/**
	 * Constructor.
	 *
	 * @param GraphService $graph The graph.
	 */
	public function __construct(
		private readonly GraphService $graph,
	) {
	}//end __construct()

	/**
	 * Invoke one graph tool as the acting user.
	 *
	 * @param string $uid The acting user.
	 * @param string $toolId The tool id.
	 * @param array<string, mixed> $arguments The tool arguments.
	 *
	 * @return array<string, mixed> The result or an error envelope.
	 *
	 * @spec openspec/changes/knowledge-graph/specs/knowledge-graph/spec.md#requirement-graph-traversal-is-exposed-as-governed-agent-tools
	 */
	public function invoke(string $uid, string $toolId, array $arguments): array {
		try {
			return match ($toolId) {
				GraphToolDescriptors::NEIGHBORS => $this->neighbors(uid: $uid, arguments: $arguments),
				GraphToolDescriptors::PATH => $this->path(uid: $uid, arguments: $arguments),
				default => $this->error(code: 'unknown_tool', message: 'That is not a graph tool.'),
			};
		} catch (Throwable) {
			return $this->error(code: 'tool_failed', message: 'The graph could not be read.');
		}

	}//end invoke()

	/**
	 * The visible neighbourhood of one entity.
	 *
	 * @param string $uid The acting user.
	 * @param array<string, mixed> $arguments entity, depth?, predicates?.
	 *
	 * @return array<string, mixed> Nodes and edges, or an error.
	 */
	private function neighbors(string $uid, array $arguments): array {
		$start = $this->entity(value: ($arguments['entity'] ?? null), uid: $uid);
		if (isset($start['error']) === true) {
			return $start;
		}

		$predicates = array_values(array_filter((array)($arguments['predicates'] ?? []), 'is_string'));
		$result = $this->graph->neighbors(
			entityUuid: (string)$start['uuid'],
			actingUserId: $uid,
			depth: (int)($arguments['depth'] ?? 1),
			predicates: $predicates
		);

		return ['nodes' => array_values($result['nodes']), 'edges' => $result['edges']];

	}//end neighbors()

	/**
	 * The shortest visible path between two entities, or "no path".
	 *
	 * @param string $uid The acting user.
	 * @param array<string, mixed> $arguments from, to, maxHops?.
	 *
	 * @return array<string, mixed> Nodes and edges, a null path, or an error.
	 */
	private function path(string $uid, array $arguments): array {
		$from = $this->entity(value: ($arguments['from'] ?? null), uid: $uid);
		if (isset($from['error']) === true) {
			return $from;
		}

		$to = $this->entity(value: ($arguments['to'] ?? null), uid: $uid);
		if (isset($to['error']) === true) {
			return $to;
		}

		$path = $this->graph->path(
			fromUuid: (string)$from['uuid'],
			toUuid: (string)$to['uuid'],
			actingUserId: $uid,
			maxHops: (int)($arguments['maxHops'] ?? 4)
		);
		if ($path === null) {
			return ['path' => null, 'message' => 'There is no path between these entities over records you can read.'];
		}

		return $path;

	}//end path()

	/**
	 * The visible entity an argument names, or an error envelope.
	 *
	 * @param mixed $value A uuid or a label.
	 * @param string $uid The acting user.
	 *
	 * @return array<string, mixed> The node view, or an error.
	 */
	private function entity(mixed $value, string $uid): array {
		if (is_string($value) === false || trim($value) === '') {
			return $this->error(code: 'invalid_argument', message: 'Name an entity by its uuid or its label.');
		}

		$node = $this->graph->named(entity: $value, actingUserId: $uid);
		if ($node === null) {
			return $this->error(code: 'entity_not_found', message: 'No entity with that name or id is visible to you.');
		}

		return $node;

	}//end entity()

	/**
	 * An error envelope.
	 *
	 * @param string $code The error code.
	 * @param string $message The message.
	 *
	 * @return array{error: array{code: string, message: string}}
	 */
	private function error(string $code, string $message): array {
		return ['error' => ['code' => $code, 'message' => $message]];

	}//end error()
}//end class

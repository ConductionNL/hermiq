<?php

/**
 * Hermiq GraphToolDescriptors.
 *
 * The two knowledge-graph tools (knowledge-graph): `hermiq.graphNeighbors` and
 * `hermiq.graphPath`. Both are read-only, reach `user`, and registered through the
 * same provider as every other `hermiq.*` tool, so they reach a model only through
 * the agent's resolved, default-denied grant set. They return labels, predicates and
 * sourceRef pointers of what the acting user's records allow, never record content:
 * the model follows a pointer with the governed record, file and mail tools.
 *
 * @category Mcp
 * @package  OCA\Hermiq\Mcp
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
 * @spec openspec/specs/knowledge-graph/spec.md#requirement-graph-traversal-is-exposed-as-governed-agent-tools
 */

declare(strict_types=1);

namespace OCA\Hermiq\Mcp;

use OCA\Hermiq\AppInfo\Application;
use OCA\OpenRegister\Service\Capability\ToolReachResolver;

/**
 * Descriptors of the knowledge-graph tools.
 *
 * @spec openspec/specs/knowledge-graph/spec.md#requirement-graph-traversal-is-exposed-as-governed-agent-tools
 */
final class GraphToolDescriptors {

	public const NEIGHBORS = Application::APP_ID . '.graphNeighbors';

	public const PATH = Application::APP_ID . '.graphPath';

	public const IDS = [self::NEIGHBORS, self::PATH];

	public const ALL = [
		[
			'id' => self::NEIGHBORS,
			'subject' => 'graphEntity',
			'action' => 'list',
			'reach' => ToolReachResolver::REACH_USER,
			'name' => 'Graph neighbours',
			'description' => 'List the entities related to one entity in the knowledge graph, and how they relate. '
				. 'Returns labels, relation names and a sourceRef pointer per entity, never record content: read a '
				. 'record with the matching record, file or mail tool. Only entities whose records you can read appear.',
			'inputSchema' => [
				'type' => 'object',
				'properties' => [
					'entity' => ['type' => 'string', 'description' => 'The entity uuid, or its exact label.'],
					'depth' => ['type' => 'integer', 'description' => 'How many hops to follow (default 1, at most 2).'],
					'predicates' => [
						'type' => 'array',
						'items' => ['type' => 'string'],
						'description' => 'Only these relation names, e.g. worksFor, partOf, mentions.',
					],
				],
				'required' => ['entity'],
			],
			'readOnlyHint' => true,
			'destructiveHint' => false,
			'idempotentHint' => true,
			'scope' => 'read',
		],
		[
			'id' => self::PATH,
			'subject' => 'graphEntity',
			'action' => 'get',
			'reach' => ToolReachResolver::REACH_USER,
			'name' => 'Graph path',
			'description' => 'Find the shortest chain of relations between two entities in the knowledge graph. '
				. 'Every step is built from records you can read; when no such chain exists the answer is that there '
				. 'is no path. Returns labels, relation names and sourceRef pointers, never record content.',
			'inputSchema' => [
				'type' => 'object',
				'properties' => [
					'from' => ['type' => 'string', 'description' => 'The start entity uuid, or its exact label.'],
					'to' => ['type' => 'string', 'description' => 'The end entity uuid, or its exact label.'],
					'maxHops' => ['type' => 'integer', 'description' => 'The longest chain to accept (default 4, at most 6).'],
				],
				'required' => ['from', 'to'],
			],
			'readOnlyHint' => true,
			'destructiveHint' => false,
			'idempotentHint' => true,
			'scope' => 'read',
		],
	];
}//end class

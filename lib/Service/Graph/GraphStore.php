<?php

/**
 * Hermiq GraphStore.
 *
 * The knowledge graph's only door to OpenRegister: reads and writes of GraphEntity
 * and GraphRelation objects in the hermiq register, all through ObjectService, so
 * every graph write lands in the audit trail.
 *
 * Reads run without the graph objects' own RBAC (multitenancy stays on, so they stay
 * inside the organisation): the graph objects are never the authority on what a
 * user may see. GraphTraversal decides that from the records the nodes point at.
 * A failed read is an empty result, logged at debug, never an exception.
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
 * @spec openspec/changes/knowledge-graph/specs/knowledge-graph/spec.md#requirement-extraction-runs-as-the-acting-user-in-audited-background-jobs
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Graph;

use OCA\Hermiq\Service\Engine\SanitizesForSaveTrait;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * ObjectService access for graph objects.
 *
 * @spec openspec/changes/knowledge-graph/specs/knowledge-graph/spec.md#requirement-extraction-runs-as-the-acting-user-in-audited-background-jobs
 */
class GraphStore {
	use SanitizesForSaveTrait;

	public const REGISTER = 'hermiq';

	public const ENTITY_SCHEMA = 'graphentity';

	public const RELATION_SCHEMA = 'graphrelation';

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService OpenRegister's object service.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Write a new graph object; the caller becomes its owner.
	 *
	 * @param string $schema The schema slug.
	 * @param array<string, mixed> $payload The payload.
	 *
	 * @return string The new uuid.
	 *
	 * @spec openspec/changes/knowledge-graph/specs/knowledge-graph/spec.md#requirement-extraction-runs-as-the-acting-user-in-audited-background-jobs
	 */
	public function create(string $schema, array $payload): string {
		$saved = $this->objectService->saveObject(object: $payload, register: self::REGISTER, schema: $schema);

		return (string)$saved->getUuid();

	}//end create()

	/**
	 * Rewrite an existing graph object (an alias merge may touch another user's node).
	 *
	 * @param string $schema The schema slug.
	 * @param string $uuid The uuid.
	 * @param array<string, mixed> $payload The full payload.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/knowledge-graph/specs/knowledge-graph/spec.md#requirement-extraction-runs-as-the-acting-user-in-audited-background-jobs
	 */
	public function update(string $schema, string $uuid, array $payload): void {
		unset($payload['@self'], $payload['id']);
		$this->objectService->saveObject(
			object: $this->sanitizeForSave(data: $payload),
			register: self::REGISTER,
			schema: $schema,
			uuid: $uuid,
			_rbac: false
		);

	}//end update()

	/**
	 * One graph entity by uuid; null when gone or unreadable.
	 *
	 * @param string $uuid The uuid.
	 *
	 * @return ObjectEntity|null The entity.
	 *
	 * @spec openspec/changes/knowledge-graph/specs/knowledge-graph/spec.md#requirement-graph-nodes-reference-records-and-never-copy-them
	 */
	public function entity(string $uuid): ?ObjectEntity {
		try {
			return $this->objectService->find(id: $uuid, register: self::REGISTER, schema: self::ENTITY_SCHEMA, _rbac: false);
		} catch (Throwable $e) {
			$this->logger->debug('Hermiq graph: an entity could not be read', ['uuid' => $uuid, 'exception' => $e]);

			return null;
		}

	}//end entity()

	/**
	 * The first graph object matching the filters, or null.
	 *
	 * @param string $schema The schema slug.
	 * @param array<string, string> $filters Equality filters.
	 *
	 * @return ObjectEntity|null The object.
	 *
	 * @spec openspec/changes/knowledge-graph/specs/knowledge-graph/spec.md#requirement-extraction-runs-as-the-acting-user-in-audited-background-jobs
	 */
	public function first(string $schema, array $filters): ?ObjectEntity {
		return ($this->find(schema: $schema, filters: $filters, limit: 1)[0] ?? null);

	}//end first()

	/**
	 * Graph objects matching the filters, in the organisation.
	 *
	 * @param string $schema The schema slug.
	 * @param array<string, string> $filters Equality filters.
	 * @param int $limit The most to return.
	 *
	 * @return array<int, ObjectEntity> The objects.
	 *
	 * @spec openspec/changes/knowledge-graph/specs/knowledge-graph/spec.md#requirement-an-edge-is-visible-only-when-both-endpoints-are
	 */
	public function find(string $schema, array $filters, int $limit = 200): array {
		try {
			$objects = $this->objectService
				->setRegister(self::REGISTER)
				->setSchema($schema)
				->findAll(config: ['filters' => $filters, 'limit' => $limit], _rbac: false);
		} catch (Throwable $e) {
			$this->logger->debug('Hermiq graph: a graph query failed; treated as empty', ['schema' => $schema, 'exception' => $e]);

			return [];
		}

		return array_values(array_filter($objects, static fn (mixed $object): bool => $object instanceof ObjectEntity));

	}//end find()
}//end class

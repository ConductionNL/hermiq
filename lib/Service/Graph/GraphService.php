<?php

/**
 * Hermiq GraphService.
 *
 * The single surface of the knowledge graph. Nodes (GraphEntity) and edges
 * (GraphRelation) are ordinary objects in the hermiq register, written only through
 * OpenRegister's ObjectService (GraphStore), so every graph write lands in the audit
 * trail.
 *
 * A node is a typed reference to one record and never a copy of it: the payload is
 * built from an allowlist (label, type, aliases, provenance) and sourceRef keeps only
 * the pointer keys of its source type. Entity resolution is deterministic: the
 * trimmed, case-folded label plus entityType, within the organisation.
 *
 * Reads never trust the graph objects' own authorization (GraphTraversal): a node is
 * returned only while the acting user can read the record behind it, an edge only
 * when both endpoints are, and a path only when every edge on it is.
 *
 * This is the one class to re-point when OpenRegister offers a native traversal API.
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
 * @spec openspec/changes/knowledge-graph/specs/knowledge-graph/spec.md#requirement-graph-nodes-reference-records-and-never-copy-them
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Graph;

use InvalidArgumentException;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Reference-only graph writes and record-derived traversal.
 *
 * @spec openspec/changes/knowledge-graph/specs/knowledge-graph/spec.md#requirement-graph-nodes-reference-records-and-never-copy-them
 */
class GraphService {

	public const ENTITY_SCHEMA = GraphStore::ENTITY_SCHEMA;

	public const RELATION_SCHEMA = GraphStore::RELATION_SCHEMA;

	/**
	 * The seed predicate vocabulary; anything else is stored as custom.
	 */
	public const SEED_PREDICATES = ['worksFor', 'partOf', 'relatesTo', 'mentions', 'authoredBy', 'about'];

	/**
	 * The pointer keys each source type keeps, and their type.
	 */
	private const SOURCE_KEYS = [
		'object' => ['register' => 'string', 'schema' => 'string', 'uuid' => 'string'],
		'file' => ['fileId' => 'int', 'path' => 'string'],
		'mail' => ['accountId' => 'int', 'mailboxId' => 'int', 'messageId' => 'int'],
		'conversation' => ['conversationUuid' => 'string', 'messageId' => 'string'],
	];

	/**
	 * The key per source type without which the pointer means nothing.
	 */
	private const SOURCE_REQUIRED = ['object' => 'uuid', 'file' => 'fileId', 'mail' => 'messageId', 'conversation' => 'conversationUuid'];

	/**
	 * Graph object access.
	 *
	 * @var GraphStore
	 */
	private GraphStore $store;

	/**
	 * Record-derived traversal.
	 *
	 * @var GraphTraversal
	 */
	private GraphTraversal $traversal;

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService OpenRegister's object service.
	 * @param GraphVisibility $visibility Record-derived visibility.
	 * @param IAppConfig $appConfig App config (traversal caps).
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		ObjectService $objectService,
		GraphVisibility $visibility,
		IAppConfig $appConfig,
		LoggerInterface $logger,
	) {
		$this->store = new GraphStore(objectService: $objectService, logger: $logger);
		$this->traversal = new GraphTraversal(store: $this->store, visibility: $visibility, appConfig: $appConfig);
	}//end __construct()

	/**
	 * Create an entity, or merge the proposal's aliases into the one with the same label and type.
	 *
	 * @param array<string, mixed> $proposal label, entityType, sourceType, sourceRef, aliases?, confidence?, extractedBy?.
	 *
	 * @return string The entity uuid.
	 *
	 * @throws InvalidArgumentException When the proposal lacks a label, type or usable pointer.
	 *
	 * @spec openspec/changes/knowledge-graph/specs/knowledge-graph/spec.md#requirement-graph-nodes-reference-records-and-never-copy-them
	 */
	public function upsertEntity(array $proposal): string {
		$label = trim((string)($proposal['label'] ?? ''));
		$type = trim((string)($proposal['entityType'] ?? ''));
		$sourceType = (string)($proposal['sourceType'] ?? '');
		$sourceRef = $this->pointer(sourceType: $sourceType, ref: (array)($proposal['sourceRef'] ?? []));
		if ($label === '' || $type === '' || $sourceRef === null) {
			throw new InvalidArgumentException('A graph entity needs a label, an entity type and a source record.');
		}

		$key = mb_strtolower($label);
		$aliases = $this->strings(values: (array)($proposal['aliases'] ?? []));
		$existing = $this->store->first(schema: self::ENTITY_SCHEMA, filters: ['labelKey' => $key, 'entityType' => $type]);
		if ($existing !== null) {
			return $this->mergeAliases(entity: $existing, aliases: $aliases);
		}

		return $this->store->create(
			schema: self::ENTITY_SCHEMA,
			payload: [
				'label' => $label,
				'labelKey' => $key,
				'entityType' => $type,
				'sourceType' => $sourceType,
				'sourceRef' => $sourceRef,
				'aliases' => $aliases,
				'confidence' => $this->confidence(value: ($proposal['confidence'] ?? null)),
				'extractedBy' => $this->extractor(value: ($proposal['extractedBy'] ?? null)),
			]
		);

	}//end upsertEntity()

	/**
	 * Create a relation between two entities, or return the identical one.
	 *
	 * @param array<string, mixed> $proposal fromEntity, predicate, toEntity, sourceType?, sourceRef?, confidence?, extractedBy?.
	 *
	 * @return string The relation uuid.
	 *
	 * @throws InvalidArgumentException When an endpoint or the predicate is missing.
	 *
	 * @spec openspec/changes/knowledge-graph/specs/knowledge-graph/spec.md#requirement-extraction-runs-as-the-acting-user-in-audited-background-jobs
	 */
	public function upsertRelation(array $proposal): string {
		$from = (string)($proposal['fromEntity'] ?? '');
		$to = (string)($proposal['toEntity'] ?? '');
		$predicate = trim((string)($proposal['predicate'] ?? ''));
		if ($from === '' || $to === '' || $predicate === '') {
			throw new InvalidArgumentException('A graph relation needs two entities and a predicate.');
		}

		$existing = $this->store->first(
			schema: self::RELATION_SCHEMA,
			filters: ['fromEntity' => $from, 'toEntity' => $to, 'predicate' => $predicate]
		);
		if ($existing !== null) {
			return (string)$existing->getUuid();
		}

		$payload = [
			'fromEntity' => $from,
			'predicate' => $predicate,
			'toEntity' => $to,
			'custom' => in_array($predicate, self::SEED_PREDICATES, true) === false,
		];
		$sourceType = (string)($proposal['sourceType'] ?? '');
		$sourceRef = $this->pointer(sourceType: $sourceType, ref: (array)($proposal['sourceRef'] ?? []));
		if ($sourceRef !== null) {
			$payload['sourceType'] = $sourceType;
			$payload['sourceRef'] = $sourceRef;
		}

		$payload['confidence'] = $this->confidence(value: ($proposal['confidence'] ?? null));
		$payload['extractedBy'] = $this->extractor(value: ($proposal['extractedBy'] ?? null));

		return $this->store->create(schema: self::RELATION_SCHEMA, payload: $payload);

	}//end upsertRelation()

	/**
	 * The visible neighbourhood of an entity, breadth first and bounded.
	 *
	 * @param string $entityUuid The start entity.
	 * @param string $actingUserId The acting user.
	 * @param int $depth Hops to walk; capped by graph_max_depth (default 2).
	 * @param array<int, string> $predicates Only these predicates, when given.
	 *
	 * @return array{nodes: array<string, array<string, mixed>>, edges: array<int, array<string, mixed>>}
	 *
	 * @spec openspec/changes/knowledge-graph/specs/knowledge-graph/spec.md#requirement-an-edge-is-visible-only-when-both-endpoints-are
	 */
	public function neighbors(string $entityUuid, string $actingUserId, int $depth = 1, array $predicates = []): array {
		return $this->traversal->neighbors(entityUuid: $entityUuid, uid: $actingUserId, depth: $depth, predicates: $predicates);

	}//end neighbors()

	/**
	 * The shortest path between two entities over visible edges only.
	 *
	 * @param string $fromUuid The start entity.
	 * @param string $toUuid The end entity.
	 * @param string $actingUserId The acting user.
	 * @param int $maxHops The longest path to accept (at most 6).
	 *
	 * @return array{nodes: array<int, array<string, mixed>>, edges: array<int, array<string, mixed>>}|null Null when no visible path exists.
	 *
	 * @spec openspec/changes/knowledge-graph/specs/knowledge-graph/spec.md#requirement-an-edge-is-visible-only-when-both-endpoints-are
	 */
	public function path(string $fromUuid, string $toUuid, string $actingUserId, int $maxHops = 4): ?array {
		return $this->traversal->path(fromUuid: $fromUuid, toUuid: $toUuid, uid: $actingUserId, maxHops: $maxHops);

	}//end path()

	/**
	 * Merge proposed aliases into an existing entity; writes only when something is new.
	 *
	 * @param ObjectEntity $entity The existing entity.
	 * @param array<int, string> $aliases The proposed aliases.
	 *
	 * @return string The entity uuid.
	 */
	private function mergeAliases(ObjectEntity $entity, array $aliases): string {
		$uuid = (string)$entity->getUuid();
		$data = $entity->getObject();
		$known = $this->strings(values: (array)($data['aliases'] ?? []));
		$merged = array_values(array_unique(array_merge($known, $aliases)));
		if ($merged !== $known) {
			$data['aliases'] = $merged;
			$this->store->update(schema: self::ENTITY_SCHEMA, uuid: $uuid, payload: $data);
		}

		return $uuid;

	}//end mergeAliases()

	/**
	 * Keep only the pointer keys of the source type, typed; null when unusable.
	 *
	 * @param string $sourceType The source type.
	 * @param array<string, mixed> $ref The proposed pointer.
	 *
	 * @return array<string, int|string>|null The pointer.
	 */
	private function pointer(string $sourceType, array $ref): ?array {
		$keys = (self::SOURCE_KEYS[$sourceType] ?? []);
		$out = [];
		foreach ($keys as $key => $type) {
			$value = ($ref[$key] ?? null);
			if (is_scalar($value) === true && $value !== '') {
				$out[$key] = (string)$value;
				if ($type === 'int') {
					$out[$key] = (int)$value;
				}
			}
		}

		if ($keys === [] || empty($out[self::SOURCE_REQUIRED[$sourceType]]) === true) {
			return null;
		}

		return $out;

	}//end pointer()

	/**
	 * A confidence between 0 and 1; 1 when none was given.
	 *
	 * @param mixed $value The proposed confidence.
	 *
	 * @return float The confidence.
	 */
	private function confidence(mixed $value): float {
		if (is_numeric($value) === false) {
			return 1.0;
		}

		return max(0.0, min(1.0, (float)$value));

	}//end confidence()

	/**
	 * The extractor identity; "manual" when none was given.
	 *
	 * @param mixed $value The proposed extractor.
	 *
	 * @return string The extractor identity.
	 */
	private function extractor(mixed $value): string {
		if (is_string($value) === false || trim($value) === '') {
			return 'manual';
		}

		return trim($value);

	}//end extractor()

	/**
	 * Non-empty, trimmed, unique strings.
	 *
	 * @param array<int|string, mixed> $values The values.
	 *
	 * @return array<int, string> The strings.
	 */
	private function strings(array $values): array {
		$out = [];
		foreach ($values as $value) {
			if (is_string($value) === true && trim($value) !== '') {
				$out[] = trim($value);
			}
		}

		return array_values(array_unique($out));

	}//end strings()
}//end class

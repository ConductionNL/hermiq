<?php

/**
 * Hermiq GraphLookup.
 *
 * Finds the entities a tool argument or a query names, among the ones the acting
 * user can see: by uuid, by exact label in any case (tools), and by the one to three
 * word phrases of a query (the graph retrieval mode's seeds). Matching is on the
 * stored labelKey, one equality filter per phrase; there is no similarity search in
 * this change (that waits for OpenRegister's vector facade).
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
 * @spec openspec/specs/knowledge-graph/spec.md#requirement-graph-traversal-is-available-to-context-assembly
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Graph;

/**
 * Visible entity lookup by uuid, label or query phrase.
 *
 * @spec openspec/specs/knowledge-graph/spec.md#requirement-graph-traversal-is-available-to-context-assembly
 */
class GraphLookup {

	/**
	 * What a uuid argument looks like; anything else is taken as a label.
	 */
	private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

	/**
	 * Constructor.
	 *
	 * @param GraphStore $store Graph object access.
	 * @param GraphTraversal $traversal Record-derived visibility of one node.
	 */
	public function __construct(
		private readonly GraphStore $store,
		private readonly GraphTraversal $traversal,
	) {
	}//end __construct()

	/**
	 * The first visible entity with this uuid or this label (any case and spacing).
	 *
	 * @param string $entity A uuid or a label.
	 * @param string $uid The acting user.
	 *
	 * @return array<string, mixed>|null The node view, or null when none is visible.
	 *
	 * @spec openspec/specs/knowledge-graph/spec.md#requirement-graph-traversal-is-exposed-as-governed-agent-tools
	 */
	public function named(string $entity, string $uid): ?array {
		$entity = trim($entity);
		if (preg_match(self::UUID_PATTERN, $entity) === 1) {
			return $this->traversal->visible(uuid: $entity, uid: $uid);
		}

		return ($this->visibleNamed(labelKeys: [mb_strtolower($entity)], uid: $uid, limit: 1)[0] ?? null);

	}//end named()

	/**
	 * Visible entities whose label is a phrase of one to three words in the query.
	 *
	 * @param string $query The user's query.
	 * @param string $uid The acting user.
	 * @param int $limit The most seeds to return.
	 *
	 * @return array<int, array<string, mixed>> Node views.
	 *
	 * @spec openspec/specs/knowledge-graph/spec.md#requirement-graph-traversal-is-available-to-context-assembly
	 */
	public function seeds(string $query, string $uid, int $limit): array {
		$words = preg_split('/[^\p{L}\p{N}@._-]+/u', mb_strtolower($query), -1, PREG_SPLIT_NO_EMPTY);
		$phrases = [];
		$total = count($words);
		foreach (array_keys($words) as $start) {
			for ($length = 1; $length <= 3 && $start + $length <= $total; $length++) {
				$phrases[] = implode(' ', array_slice($words, $start, $length));
			}
		}

		$phrases = array_values(array_unique(array_filter($phrases, static fn (string $phrase): bool => mb_strlen($phrase) >= 3)));

		return $this->visibleNamed(labelKeys: array_slice($phrases, 0, 60), uid: $uid, limit: $limit);

	}//end seeds()

	/**
	 * Visible entities for a list of label keys, in the keys' order.
	 *
	 * @param array<int, string> $labelKeys Case-folded labels.
	 * @param string $uid The acting user.
	 * @param int $limit The most to return.
	 *
	 * @return array<int, array<string, mixed>> Node views.
	 */
	private function visibleNamed(array $labelKeys, string $uid, int $limit): array {
		$out = [];
		foreach ($labelKeys as $labelKey) {
			foreach ($this->store->find(schema: GraphStore::ENTITY_SCHEMA, filters: ['labelKey' => $labelKey], limit: 20) as $entity) {
				$node = $this->traversal->visible(uuid: (string)$entity->getUuid(), uid: $uid);
				if ($node !== null && count($out) < $limit) {
					$out[$node['uuid']] = $node;
				}
			}
		}

		return array_values($out);

	}//end visibleNamed()
}//end class

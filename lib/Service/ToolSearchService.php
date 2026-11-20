<?php

/**
 * Hermiq Tool Search Service (agent-tool-governance-and-disclosure).
 *
 * Backs the `hermiq.searchTools` meta-tool and the progressive-disclosure
 * mechanism (design.md §2): when an agent's resolved (grant-filtered,
 * default-denied) tool catalog exceeds `IAppConfig('hermiq','tools.disclosureThreshold')`,
 * `ToolLoop` places only `hermiq.searchTools` in the model's context instead of
 * every descriptor, and registers the FULL resolved set here. The meta-tool call
 * is Hermiq-internal — handled by `FacadeToolInvoker` directly against this
 * service, never round-tripped through `ToolRegistryFacade` (design.md §2: "the
 * invocation never leaves Hermiq").
 *
 * Nextcloud's DI container resolves one instance of this service per HTTP
 * request/run, so the in-memory `$resolved` map naturally scopes "per-run" state
 * (design.md: "Deferred set is held per-run..., never persisted") without any
 * extra plumbing — the SAME instance is threaded from `ToolLoop::listAgentFunctions()`
 * (registration) through `ToolLoop::buildFunctionInfos()` into the
 * `FacadeToolInvoker` that handles the model's later `searchTools(query)` call
 * within that same run.
 *
 * `ToolLoop` ALWAYS registers the resolved set here (whether or not disclosure
 * narrowed the model's immediate context) — `isGranted()` is therefore also the
 * single source of truth `FacadeToolInvoker`'s approval-gate check consults for
 * "was this tool id part of the agent's resolved (already grant-filtered,
 * default-denied) set for this run", independent of whether it happened to be
 * placed directly in context or only reachable via a `searchTools` match.
 *
 * v1 ranking is case-insensitive substring matching over `name`/`description`/id
 * (design.md Open Questions: embedding similarity is deferred, not built here).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\Hermiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/2026-07-13-agent-tool-governance-and-disclosure/tasks.md#task-3
 * @spec openspec/specs/agent-tool-governance/spec.md#requirement-progressive-tool-disclosure-for-large-catalogs
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service;

use OCA\Hermiq\Kernel\AppIdentity;
use OCA\Hermiq\Kernel\Contracts\ToolSearchServiceInterface;
use OCA\Hermiq\Service\Engine\VectorSearch;
use OCP\IAppConfig;

/**
 * Holds one run's resolved tool descriptor set and ranks `searchTools` queries
 * against it.
 *
 * @spec openspec/changes/archive/2026-07-13-agent-tool-governance-and-disclosure/tasks.md#task-3
 */
class ToolSearchService implements ToolSearchServiceInterface {

	/**
	 * Matches returned per `search()` call are capped so a broad query cannot
	 * re-inflate the context back toward the pre-disclosure size.
	 *
	 * @var int
	 */
	private const MAX_MATCHES = 10;

	/**
	 * The similarity a descriptor needs when `tools.searchSimilarityFloor` is not set.
	 *
	 * @var float
	 */
	public const DEFAULT_SIMILARITY_FLOOR = 0.3;

	/**
	 * The app setting holding the similarity floor.
	 *
	 * @var string
	 */
	public const FLOOR_SETTING = 'tools.searchSimilarityFloor';

	/**
	 * This run's resolved descriptor set, keyed by id (`mcpId` or `name`).
	 *
	 * @var array<string, array<string,mixed>>
	 */
	private array $resolved = [];

	/**
	 * Embeddings of the resolved descriptors, keyed by id.
	 *
	 * @var array<string, array<int, float>>
	 */
	private array $descriptorVectors = [];

	/**
	 * What the cached embeddings were made for: a hash of the descriptor ids and the model id.
	 *
	 * @var string
	 */
	private string $vectorKey = '';

	/**
	 * Constructor.
	 *
	 * @param VectorSearch|null $vectors   OpenRegister's embeddings (vector-rag); null keeps substring matching.
	 * @param IAppConfig|null   $appConfig Reads the similarity floor.
	 *
	 * @spec openspec/changes/vector-rag/tasks.md#task-3-1
	 */
	public function __construct(
		private readonly ?VectorSearch $vectors = null,
		private readonly ?IAppConfig $appConfig = null,
	) {
	}//end __construct()

	/**
	 * Register this run's resolved (grant-filtered, default-denied) descriptor
	 * set. Safe to call once per turn; a later call replaces the set (a fresh
	 * `listAgentFunctions()` resolution supersedes the previous one).
	 *
	 * @param array<int, mixed> $descriptors The resolved descriptor list. Typed loosely on
	 *                                       purpose: these cross the OpenRegister tool-facade
	 *                                       boundary, so each entry is re-checked below.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/agent-tool-governance/spec.md#scenario-a-resolved-catalog-exceeds-the-disclosure-threshold
	 */
	public function registerResolved(array $descriptors): void {
		$this->resolved = [];
		foreach ($descriptors as $descriptor) {
			if (is_array($descriptor) === false) {
				continue;
			}

			$id = $this->descriptorId(descriptor: $descriptor);
			if ($id !== null) {
				$this->resolved[$id] = $descriptor;
			}
		}

	}//end registerResolved()

	/**
	 * Whether a tool id is part of this run's resolved (already grant-filtered,
	 * default-denied) set.
	 *
	 * @param string $id A tool id (`mcpId`/dotted form, or bare `name`).
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/human-approval-gate/spec.md#requirement-un-granted-destructive-tool-invocation-routes-through-the-approval-gate
	 */
	public function isGranted(string $id): bool {
		return array_key_exists($id, $this->resolved);
	}//end isGranted()

	/**
	 * Look up a resolved descriptor by id.
	 *
	 * @param string $id A tool id (`mcpId`/dotted form, or bare `name`).
	 *
	 * @return array<string,mixed>|null
	 *
	 * @spec openspec/changes/archive/2026-07-13-agent-tool-governance-and-disclosure/tasks.md#task-3
	 */
	public function descriptor(string $id): ?array {
		return ($this->resolved[$id] ?? null);
	}//end descriptor()

	/**
	 * Rank the resolved set against a free-text query — the `hermiq.searchTools`
	 * meta-tool's implementation. NEVER returns a descriptor outside the
	 * already-resolved (grant-filtered, default-denied) set.
	 *
	 * @param string $query Free-text query from the model.
	 *
	 * @return array<int, array<string,mixed>> Matching descriptors (capped, best-effort ranked).
	 *
	 * @spec openspec/specs/agent-tool-governance/spec.md#scenario-the-model-searches-for-and-then-invokes-a-deferred-tool
	 */
	public function search(string $query): array {
		$needle = mb_strtolower(trim($query));
		if ($needle === '') {
			return [];
		}

		$ranked = $this->rankBySimilarity(query: trim($query));
		if ($ranked !== null) {
			return $ranked;
		}

		$matches = [];
		foreach ($this->resolved as $id => $descriptor) {
			$haystack = mb_strtolower(
				$id . ' ' . (string)($descriptor['name'] ?? '') . ' ' . (string)($descriptor['description'] ?? '')
			);

			if (str_contains($haystack, $needle) === true) {
				$matches[] = $descriptor;
			}

			if (count($matches) >= self::MAX_MATCHES) {
				break;
			}
		}

		return $matches;
	}//end search()

	/**
	 * The resolved descriptors most similar to the query, or null when no embedding can be made.
	 *
	 * Only `$this->resolved` is iterated, so a descriptor outside the resolved set can
	 * never be returned. Descriptors at or above the floor, best first, at most
	 * MAX_MATCHES. A tie keeps the resolved set's order.
	 *
	 * @param string $query The trimmed query.
	 *
	 * @return array<int, array<string,mixed>>|null The matches, or null for the substring path.
	 *
	 * @spec openspec/changes/vector-rag/tasks.md#task-3-1
	 */
	private function rankBySimilarity(string $query): ?array {
		if ($this->vectors === null || $this->resolved === []) {
			return null;
		}

		$descriptors = $this->descriptorVectors();
		if ($descriptors === null) {
			return null;
		}

		$queryVectors = $this->vectors->embedTexts(texts: [$query]);
		if ($queryVectors === null || isset($queryVectors[0]) === false) {
			return null;
		}

		$floor  = $this->similarityFloor();
		$scores = [];
		foreach (array_keys($this->resolved) as $id) {
			$score = self::cosine(a: $queryVectors[0], b: ($descriptors[$id] ?? []));
			if ($score >= $floor) {
				$scores[$id] = $score;
			}
		}

		// Sorting is stable since PHP 8, so a tie keeps the resolved order.
		arsort($scores);

		$matches = [];
		foreach (array_slice(array_keys($scores), 0, self::MAX_MATCHES) as $id) {
			$matches[] = $this->resolved[(string)$id];
		}

		return $matches;
	}//end rankBySimilarity()

	/**
	 * The embeddings of the resolved descriptors, made again when the set or the model changed.
	 *
	 * @return array<string, array<int, float>>|null Vectors by id, or null when they cannot be made.
	 *
	 * @spec openspec/changes/vector-rag/tasks.md#task-3-2
	 */
	private function descriptorVectors(): ?array {
		$ids = array_map('strval', array_keys($this->resolved));
		$key = sha1(implode("\n", $ids)) . '|' . $this->vectors->modelId();
		if ($key === $this->vectorKey) {
			return $this->descriptorVectors;
		}

		$texts = [];
		foreach ($this->resolved as $id => $descriptor) {
			$texts[] = trim($id . ' ' . (string)($descriptor['name'] ?? '') . ' ' . (string)($descriptor['description'] ?? ''));
		}

		$vectors = $this->vectors->embedTexts(texts: $texts);
		if ($vectors === null) {
			return null;
		}

		$this->descriptorVectors = array_combine($ids, $vectors);
		$this->vectorKey         = $key;

		return $this->descriptorVectors;
	}//end descriptorVectors()

	/**
	 * The similarity floor from `tools.searchSimilarityFloor`, between 0 and 1.
	 *
	 * @return float The floor; the default when unset or not a number.
	 *
	 * @spec openspec/changes/vector-rag/tasks.md#task-3-1
	 */
	private function similarityFloor(): float {
		$raw = '';
		if ($this->appConfig !== null) {
			$raw = trim($this->appConfig->getValueString(AppIdentity::APP_ID, self::FLOOR_SETTING, ''));
		}

		if (is_numeric($raw) === false) {
			return self::DEFAULT_SIMILARITY_FLOOR;
		}

		return max(0.0, min(1.0, (float)$raw));
	}//end similarityFloor()

	/**
	 * The cosine similarity of two vectors; 0 when either is empty, zero or of another length.
	 *
	 * @param array<int, float|int> $a One vector.
	 * @param array<int, float|int> $b The other.
	 *
	 * @return float The similarity.
	 *
	 * @spec openspec/changes/vector-rag/tasks.md#task-3-1
	 */
	public static function cosine(array $a, array $b): float {
		$a = array_values($a);
		$b = array_values($b);
		if ($a === [] || count($a) !== count($b)) {
			return 0.0;
		}

		$dot   = 0.0;
		$normA = 0.0;
		$normB = 0.0;
		foreach ($a as $i => $value) {
			$dot   += ((float)$value * (float)$b[$i]);
			$normA += ((float)$value ** 2);
			$normB += ((float)$b[$i] ** 2);
		}

		if ($normA === 0.0 || $normB === 0.0) {
			return 0.0;
		}

		return $dot / (sqrt($normA) * sqrt($normB));
	}//end cosine()

	/**
	 * A descriptor's whitelist-matchable id: the dotted `mcpId` when present
	 * (MCP-bridged/derived tools), else the bare `name`.
	 *
	 * @param array<string,mixed> $descriptor A function descriptor.
	 *
	 * @return string|null
	 */
	private function descriptorId(array $descriptor): ?string {
		$id = ($descriptor['mcpId'] ?? ($descriptor['name'] ?? null));
		if (is_string($id) === true && $id !== '') {
			return $id;
		}

		return null;
	}//end descriptorId()
}//end class

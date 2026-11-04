<?php

/**
 * Hermiq Chat Context Retrieval Handler.
 *
 * Handler for RAG (Retrieval Augmented Generation) context retrieval. Ported from
 * `OCA\OpenRegister\Service\Chat\ContextRetrievalHandler`: query building, source
 * formatting, and ragSearchMode/ragNumSources handling are preserved; the retrieval
 * call itself is re-pointed at OpenRegister's public search surface.
 *
 * GROUND-TRUTH ADAPTATION (agent-engine-port pause protocol — document, continue):
 * the ported original constructed `OCA\OpenRegister\Search\Service\Vectorization\VectorEmbeddings`
 * directly for its `semantic`/`hybrid` modes — an OR-INTERNAL class, not a published
 * cross-app facade (unlike `ToolRegistryFacade`). Reproducing that construction here
 * would recreate exactly the wide, undocumented internal dependency gate-27 exists to
 * catch. design.md assumed "OR's existing vector-search surface" exists as a public
 * facade; at openregister HEAD (verified 2026-07-06) it does not. Resolution:
 * - `keyword` mode calls `ObjectService::searchObjectsPaginated(query: ['_search' =>
 *   ..., '_limit' => ...])` — the same real, public, documented method the original's
 *   own `searchKeywordOnly()` used.
 * - `semantic`/`hybrid` modes DEGRADE GRACEFULLY to that keyword path, with a
 *   `LoggerInterface::info()` note, rather than crashing or reaching into OR
 *   internals. When OR publishes a vector-search facade, only `retrieveContext()`'s
 *   mode branch needs re-pointing — see the agent-engine-port DEFERRED note.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Engine
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/agent-engine-port/tasks.md#task-1-1
 * @spec openspec/changes/agent-engine-port/tasks.md#task-1-3
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Engine;

use Exception;
use OCA\Hermiq\Service\Graph\GraphContextRetriever;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Handles context retrieval for RAG chat responses against OpenRegister's
 * public search surface.
 *
 * @spec openspec/changes/agent-engine-port/tasks.md#task-1-3
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Complex RAG context retrieval, ported structure
 */
class ContextRetrievalHandler {
	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService OpenRegister object search (public surface).
	 * @param LoggerInterface $logger Logger.
	 * @param GraphContextRetriever|null $graphRetriever The graph mode (knowledge-graph); null keeps graph mode on the keyword path.
	 * @param AppRegisterScope|null $appScope The registers of an agent's app (agents-bound-to-their-app).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agent-engine-port/tasks.md#task-1-3
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly LoggerInterface $logger,
		private readonly ?GraphContextRetriever $graphRetriever = null,
		private readonly ?AppRegisterScope $appScope = null,
	) {
	}//end __construct()

	/**
	 * Retrieve context for RAG chat.
	 *
	 * Reads RAG configuration from the Agent object (`ragSearchMode`,
	 * `ragNumSources`, `searchFiles`, `searchObjects`, `views`), applies
	 * `$ragSettings` overrides, retrieves candidate sources, and formats them
	 * into the `{text, sources}` context shape the response handler consumes.
	 * Never throws — retrieval failure returns an empty context.
	 *
	 * @param string $query User query text.
	 * @param ObjectEntity|null $agent Agent object (optional).
	 * @param array $selectedViews View filters for multitenancy (optional).
	 * @param array $ragSettings RAG configuration overrides (optional).
	 *
	 * @return array Retrieved context: `text` (concatenated excerpt block) and
	 *               `sources` (list of source descriptors).
	 *
	 * @psalm-return array{text: string, sources: list<array>}
	 *
	 * @spec openspec/changes/agent-engine-port/tasks.md#task-1-3
	 */
	public function retrieveContext(
		string $query,
		?ObjectEntity $agent,
		array $selectedViews = [],
		array $ragSettings = [],
	): array {
		$agentData = [];
		if ($agent !== null) {
			$agentData = $agent->getObject();
		}

		$this->logger->info(
			message: '[ContextRetrievalHandler] Retrieving context',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'query' => substr($query, 0, 100),
				'hasAgent' => $agent !== null,
				'ragSettings' => $ragSettings,
			]
		);

		$run = [
			'query' => $query,
			'agentData' => $agentData,
			'settings' => $this->retrievalSettings(agentData: $agentData, ragSettings: $ragSettings),
			'viewFilters' => $this->resolveViewFilters(
				agentViews: ($agentData['views'] ?? []),
				selectedViews: $selectedViews
			),
		];

		try {
			$found = $this->candidateResults(run: $run);
			$collected = (new RetrievalSourceCollector(settings: $run['settings']))->collect(found: $found);
			$this->logRetrieved(run: $run, collected: $collected + ['rawResultsCount' => count($found['results'])]);

			return [
				'text' => $collected['text'],
				'sources' => $collected['sources'],
			];
		} catch (Exception $e) {
			$this->logger->error(
				message: '[ContextRetrievalHandler] Failed to retrieve context',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'error' => $e->getMessage(),
				]
			);

			return [
				'text' => '',
				'sources' => [],
			];
		}//end try
	}//end retrieveContext()

	/**
	 * The turn's search mode and, per source type, its include flag and limit:
	 * the agent's settings with the caller's RAG overrides applied.
	 *
	 * @param array<string, mixed> $agentData   The agent payload.
	 * @param array<string, mixed> $ragSettings The caller's overrides.
	 *
	 * @return array{searchMode: mixed, include: array{file: mixed, object: mixed}, limit: array{file: mixed, object: mixed}}
	 *
	 * @spec openspec/changes/agent-engine-port/tasks.md#task-1-3
	 */
	private function retrievalSettings(array $agentData, array $ragSettings): array {
		$numSources = $agentData['ragNumSources'] ?? 5;

		return [
			'searchMode' => $agentData['ragSearchMode'] ?? 'hybrid',
			'include' => [
				'file' => $ragSettings['includeFiles'] ?? ($agentData['searchFiles'] ?? true),
				'object' => $ragSettings['includeObjects'] ?? ($agentData['searchObjects'] ?? true),
			],
			'limit' => [
				'file' => $ragSettings['numSourcesFiles'] ?? $numSources,
				'object' => $ragSettings['numSourcesObjects'] ?? $numSources,
			],
		];
	}//end retrievalSettings()

	/**
	 * The candidate rows and the context text they start from: the graph's
	 * neighbourhood and relations in graph mode, else the scoped keyword search.
	 *
	 * Fetches twice the larger source limit, so the per-type limits can still be
	 * met after rows are filtered by type.
	 *
	 * @param array<string, mixed> $run The query, agent payload, settings and view filters.
	 *
	 * @return array{results: mixed, text: mixed}
	 *
	 * @spec openspec/changes/agent-engine-port/tasks.md#task-1-3
	 */
	private function candidateResults(array $run): array {
		$searchMode = $run['settings']['searchMode'];
		$fetchLimit = max($run['settings']['limit']['file'], $run['settings']['limit']['object']) * 2;

		$graph = null;
		if ($searchMode === 'graph') {
			$graph = $this->graphContext(query: $run['query'], agentData: $run['agentData'], limit: $fetchLimit);
		}

		// Semantic/hybrid degrade to keyword: see the class docblock's
		// ground-truth adaptation note.
		if ($searchMode === 'semantic' || $searchMode === 'hybrid') {
			$this->logger->info(
				message: '[ContextRetrievalHandler] semantic/hybrid RAG mode requested but no public OR '
					. 'vector-search facade exists yet at HEAD; degrading to keyword search — '
					. 'see agent-engine-port DEFERRED note',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'searchMode' => $searchMode,
				]
			);
		}

		if ($graph === null) {
			return [
				'results' => $this->searchScoped(
					query: $run['query'],
					limit: $fetchLimit,
					viewFilters: $run['viewFilters'],
					applicationSlug: (string)($run['agentData']['applicationSlug'] ?? '')
				),
				'text' => '',
			];
		}

		return [
			'results' => ($graph['results'] ?? []),
			'text' => ($graph['relations'] ?? ''),
		];
	}//end candidateResults()

	/**
	 * Log what one retrieval run collected.
	 *
	 * @param array<string, mixed> $run       The run's settings and view filters.
	 * @param array<string, mixed> $collected The collected text, sources and counts, plus `rawResultsCount`.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agent-engine-port/tasks.md#task-1-3
	 */
	private function logRetrieved(array $run, array $collected): void {
		$this->logger->info(
			message: '[ContextRetrievalHandler] Context retrieved',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'numSources' => count($collected['sources']),
				'fileSources' => $collected['fileSources'],
				'objectSources' => $collected['objectSources'],
				'contextLength' => strlen($collected['text']),
				'searchMode' => $run['settings']['searchMode'],
				'includeObjects' => $run['settings']['include']['object'],
				'includeFiles' => $run['settings']['include']['file'],
				'numSourcesFiles' => $run['settings']['limit']['file'],
				'numSourcesObjects' => $run['settings']['limit']['object'],
				'rawResultsCount' => $collected['rawResultsCount'],
				'viewFilters' => count($run['viewFilters']),
			]
		);
	}//end logRetrieved()

	/**
	 * The graph mode's context, or null to degrade to the keyword path.
	 *
	 * Null when the agent has not enabled the graph, no retriever is wired, the graph
	 * names no visible seed, or it fails; each is logged at info, never thrown.
	 *
	 * @param string $query The user's query.
	 * @param array<string, mixed> $agentData The agent payload.
	 * @param int $limit The most records to hydrate.
	 *
	 * @return array{results: array<int, array<string, mixed>>, relations: string}|null
	 *
	 * @spec openspec/specs/knowledge-graph/spec.md#requirement-graph-traversal-is-available-to-context-assembly
	 */
	private function graphContext(string $query, array $agentData, int $limit): ?array {
		$reason = 'the agent has not enabled the knowledge graph';
		$graph = null;
		if (($agentData['graphEnabled'] ?? false) === true && $this->graphRetriever !== null) {
			$reason = 'the graph names no visible entity in the query';
			try {
				$graph = $this->graphRetriever->retrieve(query: $query, limit: $limit);
			} catch (Throwable $e) {
				$reason = 'the graph could not be read: ' . $e->getMessage();
			}
		}

		if ($graph === null) {
			$this->logger->info(
				message: '[ContextRetrievalHandler] graph RAG mode degrades to keyword search: ' . $reason,
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
		}

		return $graph;

	}//end graphContext()

	/**
	 * Resolve the effective view filter set from agent views + user selection.
	 *
	 * Ported from the original's inline branching: agent views intersected with
	 * the user's selection when both are present; agent views when only they
	 * exist; the user selection when the agent has none.
	 *
	 * @param array $agentViews View UUIDs configured on the agent.
	 * @param array $selectedViews View UUIDs the caller selected.
	 *
	 * @return array The effective view filter UUIDs.
	 */
	private function resolveViewFilters(array $agentViews, array $selectedViews): array {
		if (empty($agentViews) === false) {
			if (empty($selectedViews) === false) {
				return array_values(array_intersect($agentViews, $selectedViews));
			}

			return $agentViews;
		}

		return $selectedViews;
	}//end resolveViewFilters()

	/**
	 * Run the keyword search only when the agent has declared a data scope.
	 *
	 * 🔴 GATE. An unscoped keyword search is not a feature — it is the accident
	 * left behind when the resolved view filters were computed and discarded.
	 * Ungated it fans out across every register and schema on the instance:
	 * measured 2026-07-29 at 71s to answer "Say OK", with OpenRegister bailing
	 * to `Cross-schema property superset exceeds the column budget; using
	 * metadata-only` and then failing 24 count queries. The rows it returned
	 * were metadata-only stubs (`{"id":"…"}`, similarity 1) that went on to
	 * pollute the prompt, so the fan-out cost 71s to make answers *worse*.
	 *
	 * No resolved views means the agent has declared no scope, so there is
	 * nothing legitimate to retrieve — retrieval is skipped and said out loud.
	 *
	 * @param string $query Query text.
	 * @param int $limit Result limit.
	 * @param array<string> $viewFilters Resolved view UUIDs; empty disables retrieval.
	 * @param string $applicationSlug The app the agent is tied to; with no views, its registers are the scope.
	 *
	 * @return array Search rows, or an empty list when out of scope.
	 *
	 * @psalm-return list<array<string, mixed>>
	 *
	 * @spec openspec/changes/agent-engine-port/tasks.md#task-1-3
	 * @spec openspec/specs/agent-management-ui/spec.md#requirement-an-apps-agent-answers-from-the-apps-data-first-req-appag-003
	 */
	private function searchScoped(string $query, int $limit, array $viewFilters, string $applicationSlug = ''): array {
		if (empty($viewFilters) === true && trim($applicationSlug) !== '' && $this->appScope !== null) {
			return $this->searchAppRegisters(query: $query, limit: $limit, applicationSlug: $applicationSlug);
		}

		if (empty($viewFilters) === true) {
			$this->logger->info(
				message: '[ContextRetrievalHandler] object retrieval skipped: the agent resolved to no views, '
					. 'and an unscoped search would fan out across every register on the instance. Configure '
					. 'the agent\'s views to enable RAG.',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
				]
			);

			return [];
		}

		return $this->searchKeywordOnly(query: $query, limit: $limit, views: $viewFilters);
	}//end searchScoped()

	/**
	 * Keyword search against OpenRegister's public paginated search.
	 *
	 * Ported from the original's `searchKeywordOnly()` — the one retrieval mode
	 * that already ran on a public surface (`ObjectService::searchObjectsPaginated`,
	 * `_search` full-text term). `_register`/`_schema` are explicitly nulled so a
	 * previous caller's ambient register/schema context on the shared ObjectService
	 * instance cannot silently scope RAG retrieval down to one schema. That
	 * explicit nulling is deliberate and MUST stay: it blocks *ambient* leakage.
	 * Scope is instead declared on purpose, via `$views`.
	 *
	 * 🔴 `$views` is load-bearing for latency, not just correctness. Without it
	 * this search fans out across EVERY register and schema on the instance —
	 * measured 2026-07-29 at 71s over 2,728 magic tables to answer "Say OK",
	 * with OpenRegister bailing out to `Cross-schema property superset exceeds
	 * the column budget; using metadata-only` and then failing 24 count queries.
	 * The caller must not reach this method with an empty view set; see the
	 * gate in `retrieveContext()`.
	 *
	 * @param string $query Query text.
	 * @param int $limit Result limit.
	 * @param array<string> $views View UUIDs scoping the search. Non-empty by
	 *                             contract — `ObjectService` applies them via
	 *                             `applyViewsToQuery()`.
	 *
	 * @return array Search results in the standardized entity shape. Kept wide
	 *               (`list<array<string, mixed>>`) deliberately: the consuming
	 *               loop in retrieveContext() is written against the superset
	 *               shape a future vector-search facade would return
	 *               (`similarity`, `chunk_text`, `metadata`, `entity_type:
	 *               file`), so the keyword rows must not narrow it.
	 *
	 * @psalm-return list<array<string, mixed>>
	 *
	 * @spec openspec/changes/agent-engine-port/tasks.md#task-1-3
	 */
	private function searchKeywordOnly(string $query, int $limit, array $views): array {
		$results = $this->objectService->searchObjectsPaginated(
			query: [
				'_search' => $query,
				'_limit' => $limit,
				'_register' => null,
				'_schema' => null,
			],
			views: $views
		);

		return $this->transform(results: ($results['results'] ?? []), registerName: null);
	}//end searchKeywordOnly()

	/**
	 * Search each of the app's registers and merge the hits by score
	 * (agents-bound-to-their-app): an agent tied to an app, with no views of its
	 * own, answers from that app's data, and each hit names its register.
	 *
	 * @param string $query           The query.
	 * @param int    $limit           The maximum number of hits.
	 * @param string $applicationSlug The app.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/specs/agent-management-ui/spec.md#requirement-an-apps-agent-answers-from-the-apps-data-first-req-appag-003
	 */
	private function searchAppRegisters(string $query, int $limit, string $applicationSlug): array {
		$hits = [];
		foreach ($this->appScope?->registersFor(app: $applicationSlug) ?? [] as $register) {
			$results = $this->objectService->searchObjectsPaginated(
				query: [
					'_search' => $query,
					'_limit' => $limit,
					'_register' => $register['id'],
					'_schema' => null,
				]
			);
			array_push($hits, ...$this->transform(results: ($results['results'] ?? []), registerName: $register['name']));
		}

		usort($hits, fn (array $left, array $right): int => ((float)$right['score'] <=> (float)$left['score']));

		return array_slice($hits, 0, $limit);
	}//end searchAppRegisters()

	/**
	 * Turn search results into retrieval hits.
	 *
	 * @param array<int, mixed> $results      The search results.
	 * @param string|null       $registerName The register they came from, when known.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/specs/agent-management-ui/spec.md#requirement-an-apps-agent-answers-from-the-apps-data-first-req-appag-003
	 */
	private function transform(array $results, ?string $registerName): array {
		$transformed = [];
		foreach ($results as $result) {
			if (($result instanceof ObjectEntity) === true) {
				$result = array_merge(
					['id' => $result->getUuid()],
					$result->getObject()
				);
			}

			if (is_array($result) === false) {
				continue;
			}

			$transformed[] = [
				'entity_id' => $result['id'] ?? $result['uuid'] ?? null,
				'entity_type' => 'object',
				'text' => $result['_source']['data'] ?? json_encode($result),
				'score' => $result['_score'] ?? 1.0,
				'name' => $result['name'] ?? $result['title'] ?? null,
				'register_name' => $registerName,
			];
		}

		return $transformed;
	}//end transform()
}//end class

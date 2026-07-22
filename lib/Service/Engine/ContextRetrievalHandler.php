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
 * the ported original constructed `OCA\OpenRegister\Service\Vectorization\VectorEmbeddings`
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
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use Psr\Log\LoggerInterface;

/**
 * Handles context retrieval for RAG chat responses against OpenRegister's
 * public search surface.
 *
 * @spec openspec/changes/agent-engine-port/tasks.md#task-1-3
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Complex RAG context retrieval, ported structure
 */
class ContextRetrievalHandler
{
    /**
     * Constructor.
     *
     * @param ObjectService   $objectService OpenRegister object search (public surface).
     * @param LoggerInterface $logger        Logger.
     *
     * @return void
     *
     * @spec openspec/changes/agent-engine-port/tasks.md#task-1-3
     */
    public function __construct(
        private readonly ObjectService $objectService,
        private readonly LoggerInterface $logger
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
     * @param string            $query         User query text.
     * @param ObjectEntity|null $agent         Agent object (optional).
     * @param array             $selectedViews View filters for multitenancy (optional).
     * @param array             $ragSettings   RAG configuration overrides (optional).
     *
     * @return array Retrieved context: `text` (concatenated excerpt block) and
     *               `sources` (list of source descriptors).
     *
     * @psalm-return array{text: string, sources: list<array>}
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)  RAG context retrieval requires many search strategies
     * @SuppressWarnings(PHPMD.NPathComplexity)       RAG context retrieval requires many search strategies
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength) Ported RAG logic kept structurally intact
     *
     * @spec openspec/changes/agent-engine-port/tasks.md#task-1-3
     */
    public function retrieveContext(
        string $query,
        ?ObjectEntity $agent,
        array $selectedViews=[],
        array $ragSettings=[]
    ): array {
        $agentData = [];
        if ($agent !== null) {
            $agentData = $agent->getObject();
        }

        $this->logger->info(
            message: '[ContextRetrievalHandler] Retrieving context',
            context: [
                'file'        => __FILE__,
                'line'        => __LINE__,
                'query'       => substr($query, 0, 100),
                'hasAgent'    => $agent !== null,
                'ragSettings' => $ragSettings,
            ]
        );

        // Get search settings from agent or use defaults, then apply RAG settings overrides.
        $searchMode        = $agentData['ragSearchMode'] ?? 'hybrid';
        $numSources        = $agentData['ragNumSources'] ?? 5;
        $includeFiles      = $ragSettings['includeFiles'] ?? ($agentData['searchFiles'] ?? true);
        $includeObjects    = $ragSettings['includeObjects'] ?? ($agentData['searchObjects'] ?? true);
        $numSourcesFiles   = $ragSettings['numSourcesFiles'] ?? $numSources;
        $numSourcesObjects = $ragSettings['numSourcesObjects'] ?? $numSources;

        // Calculate total sources needed (will be filtered by type later).
        $totalSources = max($numSourcesFiles, $numSourcesObjects);

        // Resolve view filters from the agent's views and the caller's selection.
        // NOTE (ported verbatim): the original computed these but did not yet apply
        // them to the search ("TODO: Apply view filters here when view filtering is
        // implemented"); the resolution logic is preserved so the eventual wiring
        // is a one-line change, and the count is logged below.
        $viewFilters = $this->resolveViewFilters(
            agentViews: ($agentData['views'] ?? []),
            selectedViews: $selectedViews
        );

        $sources     = [];
        $contextText = '';

        try {
            // Fetch more results than needed for type filtering.
            $fetchLimit = $totalSources * 2;

            // Semantic/hybrid degrade to keyword — see the class docblock's
            // ground-truth adaptation note.
            if ($searchMode === 'semantic' || $searchMode === 'hybrid') {
                $this->logger->info(
                    message: '[ContextRetrievalHandler] semantic/hybrid RAG mode requested but no public OR '
                        .'vector-search facade exists yet at HEAD; degrading to keyword search — '
                        .'see agent-engine-port DEFERRED note',
                    context: [
                        'file'       => __FILE__,
                        'line'       => __LINE__,
                        'searchMode' => $searchMode,
                    ]
                );
            }

            $results = $this->searchKeywordOnly(query: $query, limit: $fetchLimit);

            // Filter and build context - track file and object counts separately.
            $fileSourceCount   = 0;
            $objectSourceCount = 0;

            foreach ($results as $result) {
                $isFile   = ($result['entity_type'] ?? '') === 'file';
                $isObject = ($result['entity_type'] ?? '') === 'object';

                // Check type filters.
                $skipFile   = ($isFile === true && $includeFiles === false);
                $skipObject = ($isObject === true && $includeObjects === false);
                if ($skipFile === true || $skipObject === true) {
                    continue;
                }

                // Check if we've reached the limit for this source type.
                if ($isFile === true && $fileSourceCount >= $numSourcesFiles) {
                    continue;
                }

                if ($isObject === true && $objectSourceCount >= $numSourcesObjects) {
                    continue;
                }

                // Extract source information.
                $source = [
                    'id'         => $result['entity_id'] ?? null,
                    'type'       => $result['entity_type'] ?? 'unknown',
                    'name'       => $this->extractSourceName(result: $result),
                    'similarity' => $result['similarity'] ?? $result['score'] ?? 1.0,
                    'text'       => $result['chunk_text'] ?? $result['text'] ?? '',
                ];

                // Add type-specific metadata.
                $metadata = $result['metadata'] ?? [];
                if (is_string($metadata) === true) {
                    $metadata = json_decode($metadata, true) ?? [];
                }

                // For objects: add UUID, register, schema.
                if ($source['type'] === 'object') {
                    $source['uuid']     = $metadata['uuid'] ?? null;
                    $source['register'] = $metadata['register_id'] ?? $metadata['register'] ?? null;
                    $source['schema']   = $metadata['schema_id'] ?? $metadata['schema'] ?? null;
                    $source['uri']      = $metadata['uri'] ?? null;
                }

                // For files: add file_id, path.
                if ($source['type'] === 'file') {
                    $source['file_id']   = $metadata['file_id'] ?? $source['id'];
                    $source['file_path'] = $metadata['file_path'] ?? null;
                    $source['mime_type'] = $metadata['mime_type'] ?? null;
                }

                $sources[] = $source;

                // Increment the appropriate counter.
                if ($isFile === true) {
                    $fileSourceCount++;
                } else if ($isObject === true) {
                    $objectSourceCount++;
                }

                // Add to context text.
                $contextText .= "Source: {$source['name']}\n";
                $contextText .= "{$source['text']}\n\n";

                // Stop if we've reached limits for both types.
                if (($includeFiles === false || $fileSourceCount >= $numSourcesFiles)
                    && ($includeObjects === false || $objectSourceCount >= $numSourcesObjects)
                ) {
                    break;
                }
            }//end foreach

            $this->logger->info(
                message: '[ContextRetrievalHandler] Context retrieved',
                context: [
                    'file'              => __FILE__,
                    'line'              => __LINE__,
                    'numSources'        => count($sources),
                    'fileSources'       => $fileSourceCount,
                    'objectSources'     => $objectSourceCount,
                    'contextLength'     => strlen($contextText),
                    'searchMode'        => $searchMode,
                    'includeObjects'    => $includeObjects,
                    'includeFiles'      => $includeFiles,
                    'numSourcesFiles'   => $numSourcesFiles,
                    'numSourcesObjects' => $numSourcesObjects,
                    'rawResultsCount'   => count($results),
                    'viewFilters'       => count($viewFilters),
                ]
            );

            return [
                'text'    => $contextText,
                'sources' => $sources,
            ];
        } catch (Exception $e) {
            $this->logger->error(
                message: '[ContextRetrievalHandler] Failed to retrieve context',
                context: [
                    'file'  => __FILE__,
                    'line'  => __LINE__,
                    'error' => $e->getMessage(),
                ]
            );

            return [
                'text'    => '',
                'sources' => [],
            ];
        }//end try
    }//end retrieveContext()

    /**
     * Resolve the effective view filter set from agent views + user selection.
     *
     * Ported from the original's inline branching: agent views intersected with
     * the user's selection when both are present; agent views when only they
     * exist; the user selection when the agent has none.
     *
     * @param array $agentViews    View UUIDs configured on the agent.
     * @param array $selectedViews View UUIDs the caller selected.
     *
     * @return array The effective view filter UUIDs.
     */
    private function resolveViewFilters(array $agentViews, array $selectedViews): array
    {
        if (empty($agentViews) === false) {
            if (empty($selectedViews) === false) {
                return array_values(array_intersect($agentViews, $selectedViews));
            }

            return $agentViews;
        }

        return $selectedViews;

    }//end resolveViewFilters()

    /**
     * Keyword search against OpenRegister's public paginated search.
     *
     * Ported from the original's `searchKeywordOnly()` — the one retrieval mode
     * that already ran on a public surface (`ObjectService::searchObjectsPaginated`,
     * `_search` full-text term). `_register`/`_schema` are explicitly nulled so a
     * previous caller's ambient register/schema context on the shared ObjectService
     * instance cannot silently scope RAG retrieval down to one schema.
     *
     * @param string $query Query text.
     * @param int    $limit Result limit.
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
    private function searchKeywordOnly(string $query, int $limit): array
    {
        $results = $this->objectService->searchObjectsPaginated(
            query: [
                '_search'   => $query,
                '_limit'    => $limit,
                '_register' => null,
                '_schema'   => null,
            ]
        );

        $transformed = [];
        foreach ($results['results'] ?? [] as $result) {
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
                'entity_id'   => $result['id'] ?? $result['uuid'] ?? null,
                'entity_type' => 'object',
                'text'        => $result['_source']['data'] ?? json_encode($result),
                'score'       => $result['_score'] ?? 1.0,
                'name'        => $result['name'] ?? $result['title'] ?? null,
            ];
        }

        return $transformed;

    }//end searchKeywordOnly()

    /**
     * Extract a human-readable name from a search result.
     *
     * Attempts to find a display name from various fields in the result.
     * Falls back to entity type and ID if no name is found.
     *
     * @param array $result Search result array.
     *
     * @return string Human-readable source name.
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Name extraction requires checking many possible fields
     * @SuppressWarnings(PHPMD.NPathComplexity)      Name extraction requires checking many possible fields
     *
     * @spec openspec/changes/agent-engine-port/tasks.md#task-1-3
     */
    private function extractSourceName(array $result): string
    {
        // First check top-level fields.
        if (empty($result['title']) === false) {
            return $result['title'];
        }

        if (empty($result['name']) === false) {
            return $result['name'];
        }

        if (empty($result['filename']) === false) {
            return $result['filename'];
        }

        // Check metadata for object_title, file_name, etc.
        if (empty($result['metadata']) === false) {
            $metadata = $result['metadata'];
            if (is_string($metadata) === true) {
                $metadata = json_decode($metadata, true);
            }

            if (is_array($metadata) === true) {
                if (empty($metadata['object_title']) === false) {
                    return $metadata['object_title'];
                }

                if (empty($metadata['file_name']) === false) {
                    return $metadata['file_name'];
                }

                if (empty($metadata['name']) === false) {
                    return $metadata['name'];
                }

                if (empty($metadata['title']) === false) {
                    return $metadata['title'];
                }
            }
        }//end if

        // Fallback to entity ID.
        if (empty($result['entity_id']) === false) {
            $type = $result['entity_type'] ?? 'Item';
            // Capitalize first letter for display.
            $type = ucfirst($type);
            return $type.' #'.substr((string) $result['entity_id'], 0, 8);
        }

        // Final fallback.
        return 'Unknown Source';

    }//end extractSourceName()
}//end class

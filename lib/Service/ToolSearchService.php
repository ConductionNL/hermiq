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
 * @spec openspec/changes/agent-tool-governance-and-disclosure/tasks.md#task-3
 * @spec openspec/changes/agent-tool-governance-and-disclosure/specs/agent-tool-governance/spec.md#requirement-progressive-tool-disclosure-for-large-catalogs
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service;

/**
 * Holds one run's resolved tool descriptor set and ranks `searchTools` queries
 * against it.
 *
 * @spec openspec/changes/agent-tool-governance-and-disclosure/tasks.md#task-3
 */
class ToolSearchService
{

    /**
     * Matches returned per `search()` call are capped so a broad query cannot
     * re-inflate the context back toward the pre-disclosure size.
     *
     * @var int
     */
    private const MAX_MATCHES = 10;

    /**
     * This run's resolved descriptor set, keyed by id (`mcpId` or `name`).
     *
     * @var array<string, array<string,mixed>>
     */
    private array $resolved = [];

    /**
     * Register this run's resolved (grant-filtered, default-denied) descriptor
     * set. Safe to call once per turn; a later call replaces the set (a fresh
     * `listAgentFunctions()` resolution supersedes the previous one).
     *
     * @param array<int, array<string,mixed>> $descriptors The resolved descriptor list.
     *
     * @return void
     *
     * @spec openspec/changes/agent-tool-governance-and-disclosure/specs/agent-tool-governance/spec.md#scenario-a-resolved-catalog-exceeds-the-disclosure-threshold
     */
    public function registerResolved(array $descriptors): void
    {
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
     * @spec openspec/changes/agent-tool-governance-and-disclosure/specs/human-approval-gate/spec.md#requirement-un-granted-destructive-tool-invocation-routes-through-the-approval-gate
     */
    public function isGranted(string $id): bool
    {
        return array_key_exists($id, $this->resolved);

    }//end isGranted()

    /**
     * Look up a resolved descriptor by id.
     *
     * @param string $id A tool id (`mcpId`/dotted form, or bare `name`).
     *
     * @return array<string,mixed>|null
     *
     * @spec openspec/changes/agent-tool-governance-and-disclosure/tasks.md#task-3
     */
    public function descriptor(string $id): ?array
    {
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
     * @spec openspec/changes/agent-tool-governance-and-disclosure/specs/agent-tool-governance/spec.md#scenario-the-model-searches-for-and-then-invokes-a-deferred-tool
     */
    public function search(string $query): array
    {
        $needle = mb_strtolower(trim($query));
        if ($needle === '') {
            return [];
        }

        $matches = [];
        foreach ($this->resolved as $id => $descriptor) {
            $haystack = mb_strtolower(
                $id.' '.(string) ($descriptor['name'] ?? '').' '.(string) ($descriptor['description'] ?? '')
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
     * A descriptor's whitelist-matchable id: the dotted `mcpId` when present
     * (MCP-bridged/derived tools), else the bare `name`.
     *
     * @param array<string,mixed> $descriptor A function descriptor.
     *
     * @return string|null
     */
    private function descriptorId(array $descriptor): ?string
    {
        $id = ($descriptor['mcpId'] ?? ($descriptor['name'] ?? null));
        if (is_string($id) === true && $id !== '') {
            return $id;
        }

        return null;

    }//end descriptorId()
}//end class

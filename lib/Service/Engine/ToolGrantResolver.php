<?php

/**
 * Hermiq Tool Grant Resolver (agent-tool-governance-and-disclosure).
 *
 * Pure resolution of `Agent.tools` grant entries against the ADR-063 derived MCP
 * catalog `ToolRegistryFacade::listTools([])` returns. `Agent.tools` stays a plain
 * `string[]` (ADR-035 Decision 4 froze the shape) but the grammar of each entry is
 * extended:
 *
 * - `{app}.{tool}` / `{app}.{schema}.{verb}` (no trailing `.*`) — an EXACT id,
 *   passed through verbatim (today's behaviour, including write verbs named
 *   explicitly).
 * - `{app}.{schema}.*` — a schema wildcard; expands to that schema's READ verbs
 *   only (`search`, `get`) found in the catalog — default-deny on writes.
 * - `{app}.{schema}.*:write` — the same wildcard, but also expands the schema's
 *   write verbs (`create`, `update`, `delete`) found in the catalog.
 * - `[]` (empty `Agent.tools`) — unchanged "all discovered tools allowed", EXCEPT
 *   default-deny still strips any ADR-063 DERIVED write/destructive id (a
 *   3-segment `{app}.{schema}.{verb}` id whose verb is `create`/`update`/`delete`)
 *   from the result; every other (non-derived / hand-written / legacy) id is
 *   unaffected, preserving pre-existing whitelist behaviour exactly.
 *
 * The write/destructive classification reads the CLOSED, fixed ADR-063 verb
 * vocabulary (`search`/`get`/`create`/`update`/`delete` —
 * `OCA\OpenRegister\Service\Mcp\McpAnnotationValidator::VERBS`) off the id's own
 * text, NOT a Hermiq-side lookup table of specific tool ids (design.md
 * "Declarative vs Imperative": the *rule* is code, the *inputs* — grants and the
 * catalog — are declarative). This is a documented, deliberate fallback: OpenRegister's
 * `McpProviderBridge::getFunctions()` (the IMcpToolProvider → LLPhant-descriptor
 * adapter every provider's tools — including the ADR-063 derived catalog — flow
 * through before `ToolRegistryFacade::listTools()` returns them) does not forward
 * the `destructiveHint`/`scope`/`readOnlyHint` MCP annotation keys into the
 * descriptor at all (verified against HEAD 2026-07-13); only `name`/`mcpId`/
 * `description`/`parameters` survive. Until that gap is closed upstream (filed as
 * an OpenRegister follow-up, not hand-fixed here — cross-repo, gate-27), the verb
 * suffix of a 3-segment derived id is the only classification signal available to
 * Hermiq. Should a future descriptor carry `destructiveHint`/`scope`, prefer it —
 * see `classify()`.
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
 * @spec openspec/changes/agent-tool-governance-and-disclosure/tasks.md#task-1
 * @spec openspec/changes/agent-tool-governance-and-disclosure/specs/agent-tool-governance/spec.md#requirement-schema-scoped-whitelist-grants-with-default-deny-for-writedestructive-tools
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Engine;

/**
 * Expands `Agent.tools` grant strings against the derived catalog and applies
 * default-deny to write/destructive-classified tools.
 *
 * @spec openspec/changes/agent-tool-governance-and-disclosure/tasks.md#task-1
 */
class ToolGrantResolver
{

    /**
     * The ADR-063 read-verb vocabulary (`McpAnnotationValidator::VERBS` subset).
     *
     * @var array<int, string>
     */
    public const READ_VERBS = ['search', 'get'];

    /**
     * The ADR-063 write/destructive-verb vocabulary.
     *
     * @var array<int, string>
     */
    public const WRITE_VERBS = ['create', 'update', 'delete'];

    /**
     * Resolve `Agent.tools` grants into a concrete tool id whitelist.
     *
     * @param array<int, string>              $grants  Raw `Agent.tools` entries (exact ids,
     *                                                 schema wildcards, verb subsets, `:write`
     *                                                 modifiers — see class docblock).
     * @param array<int, array<string,mixed>> $catalog Full descriptor list, e.g. from
     *                                                 `ToolRegistryFacade::listTools([])`.
     *
     * @return array<int, string> The resolved, default-denied whitelist.
     *
     * @spec openspec/changes/agent-tool-governance-and-disclosure/specs/agent-tool-governance/spec.md#scenario-a-schema-wildcard-grants-read-verbs-only
     * @spec openspec/changes/agent-tool-governance-and-disclosure/specs/agent-tool-governance/spec.md#scenario-a-write-tool-is-granted-only-when-named-explicitly
     */
    public function resolve(array $grants, array $catalog): array
    {
        $catalogIds  = $this->catalogIds(catalog: $catalog);
        $cleanGrants = $this->sanitizeGrants(grants: $grants);

        if ($cleanGrants === []) {
            // "All discovered tools allowed" (legacy default) — default-deny still
            // strips classifiable ADR-063 derived write/destructive ids.
            return $this->applyDefaultDeny(ids: $catalogIds);
        }

        $resolved = [];
        foreach ($cleanGrants as $grant) {
            foreach ($this->expandGrant(grant: $grant, catalogIds: $catalogIds) as $id) {
                $resolved[$id] = true;
            }
        }

        return array_values(array_keys($resolved));

    }//end resolve()

    /**
     * Whether any grant entry uses the `{app}.{schema}.*` (or `.*:write`) wildcard
     * form — used by `ToolLoop` to decide whether the full catalog must be fetched
     * to expand grants (an exact-id-only whitelist never needs it).
     *
     * @param array<int, string> $grants Raw `Agent.tools` entries.
     *
     * @return bool
     *
     * @spec openspec/changes/agent-tool-governance-and-disclosure/tasks.md#task-2
     */
    public function hasWildcardGrant(array $grants): bool
    {
        foreach ($this->sanitizeGrants(grants: $grants) as $grant) {
            if ($this->isWildcardGrant(grant: $grant) === true) {
                return true;
            }
        }

        return false;

    }//end hasWildcardGrant()

    /**
     * Whether a fully-namespaced tool id is classified write/destructive under
     * the ADR-063 derived-catalog convention: a 3-segment `{app}.{schema}.{verb}`
     * id whose trailing verb is `create`/`update`/`delete`. Any other shape (a
     * bare/2-segment hand-written or legacy id) is NEVER classified this way —
     * default-deny only ever narrows the NEW derived catalog, never pre-existing
     * whitelist behaviour.
     *
     * @param string $id A tool id (the `mcpId`/dotted form).
     *
     * @return bool
     *
     * @spec openspec/changes/agent-tool-governance-and-disclosure/specs/agent-tool-governance/spec.md#requirement-schema-scoped-whitelist-grants-with-default-deny-for-writedestructive-tools
     */
    public static function isWriteOrDestructive(string $id): bool
    {
        $parts = explode('.', $id);
        if (count($parts) !== 3) {
            return false;
        }

        return in_array(end($parts), self::WRITE_VERBS, true);

    }//end isWriteOrDestructive()

    /**
     * Expand one grant entry into zero or more concrete catalog ids.
     *
     * @param string             $grant      The grant entry.
     * @param array<int, string> $catalogIds Every id the catalog currently exposes.
     *
     * @return array<int, string>
     */
    private function expandGrant(string $grant, array $catalogIds): array
    {
        if (preg_match('/^(.+)\.\*:write$/', $grant, $matches) === 1) {
            return $this->schemaVerbIds(
                prefix: $matches[1],
                verbs: array_merge(self::READ_VERBS, self::WRITE_VERBS),
                catalogIds: $catalogIds
            );
        }

        if (preg_match('/^(.+)\.\*$/', $grant, $matches) === 1) {
            return $this->schemaVerbIds(prefix: $matches[1], verbs: self::READ_VERBS, catalogIds: $catalogIds);
        }

        // Exact id (a verb-subset `{app}.{schema}.{verb}`, a plain `{app}.{tool}`,
        // or any other exact string) — pass through verbatim, whatever it names.
        // Default-deny does not apply to an explicitly-named grant.
        return [$grant];

    }//end expandGrant()

    /**
     * Build `{prefix}.{verb}` candidates that actually exist in the catalog.
     *
     * @param string             $prefix     The `{app}.{schema}` prefix.
     * @param array<int, string> $verbs      Candidate verbs to try.
     * @param array<int, string> $catalogIds Every id the catalog currently exposes.
     *
     * @return array<int, string>
     */
    private function schemaVerbIds(string $prefix, array $verbs, array $catalogIds): array
    {
        $catalogSet = array_flip($catalogIds);

        $ids = [];
        foreach ($verbs as $verb) {
            $candidate = $prefix.'.'.$verb;
            if (isset($catalogSet[$candidate]) === true) {
                $ids[] = $candidate;
            }
        }

        return $ids;

    }//end schemaVerbIds()

    /**
     * Strip an id list down to those NOT classified write/destructive.
     *
     * @param array<int, string> $ids Candidate ids.
     *
     * @return array<int, string>
     */
    private function applyDefaultDeny(array $ids): array
    {
        $out = [];
        foreach ($ids as $id) {
            if (self::isWriteOrDestructive(id: $id) === true) {
                continue;
            }

            $out[] = $id;
        }

        return $out;

    }//end applyDefaultDeny()

    /**
     * Whether a grant string uses the `{app}.{schema}.*` (optionally `:write`) form.
     *
     * @param string $grant The grant entry.
     *
     * @return bool
     */
    private function isWildcardGrant(string $grant): bool
    {
        return (str_ends_with($grant, '.*') === true || str_ends_with($grant, '.*:write') === true);

    }//end isWildcardGrant()

    /**
     * Extract every descriptor's whitelist-matchable id: the dotted `mcpId` when
     * present (MCP-bridged/derived tools), else the bare `name`.
     *
     * @param array<int, array<string,mixed>> $catalog Descriptor list.
     *
     * @return array<int, string>
     */
    private function catalogIds(array $catalog): array
    {
        $ids = [];
        foreach ($catalog as $descriptor) {
            if (is_array($descriptor) === false) {
                continue;
            }

            $id = ($descriptor['mcpId'] ?? ($descriptor['name'] ?? null));
            if (is_string($id) === true && $id !== '') {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));

    }//end catalogIds()

    /**
     * Drop non-string / empty grant entries.
     *
     * @param array<int, mixed> $grants Raw `Agent.tools` entries.
     *
     * @return array<int, string>
     */
    private function sanitizeGrants(array $grants): array
    {
        $clean = [];
        foreach ($grants as $grant) {
            if (is_string($grant) === true && $grant !== '') {
                $clean[] = $grant;
            }
        }

        return $clean;

    }//end sanitizeGrants()
}//end class

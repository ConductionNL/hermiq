<?php

/**
 * Hermiq Tool Grant Resolver (agent-tool-governance-and-disclosure,
 * hermiq-prefer-tool-hints).
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
 *   default-deny still strips every id `isWriteOrDestructive()` resolves to
 *   write/destructive (see below) from the result.
 *
 * **Classification precedence** (`isWriteOrDestructive()`), most-authoritative first:
 *
 * 1. **Declared descriptor hints** — `scope` (closed vocabulary, no boolean
 *    ambiguity), then `destructiveHint`, then `readOnlyHint` — the first one the
 *    descriptor actually sets wins; the others are not consulted. OpenRegister's
 *    `McpProviderBridge::getFunctions()` forwards these ADR-063 MCP annotation
 *    keys onto the LLPhant descriptor additively when the provider (a schema's
 *    `x-openregister-mcp` dialect, or a `#[McpTool(readOnlyHint:, ...)]`-annotated
 *    service tool) set them (OpenRegister PR #369 closed the forwarding gap this
 *    class used to document as open; verified forwarding present against HEAD
 *    2026-07-13 — `openregister` `10e605cea`). A key is omitted entirely, never
 *    defaulted, when the provider didn't set it.
 * 2. **Verb-suffix fallback** — only when the descriptor is absent or sets none
 *    of the three hint keys: the CLOSED, fixed ADR-063 verb vocabulary
 *    (`search`/`get`/`create`/`update`/`delete` —
 *    `OCA\OpenRegister\Service\Mcp\McpAnnotationValidator::VERBS`) read off a
 *    3-segment `{app}.{schema}.{verb}` id's own text — unchanged from this
 *    class's original (pre-hints) behaviour, preserved exactly for un-annotated
 *    derived tools (design.md "Declarative vs Imperative": the *rule* is code,
 *    the *inputs* — grants and the catalog — are declarative).
 * 3. **Fail closed on anything else** — a hint-less id that isn't a 3-segment
 *    derived id (a bare/2-segment hand-written, curated, or legacy id) is
 *    classified write/destructive. This is a DELIBERATE reversal of this class's
 *    pre-hints behaviour, where such an id was NEVER classified this way (see
 *    `hermiq-prefer-tool-hints` design.md "Why fail closed, and why now" — a
 *    curated 2-segment tool like `pipelinq.createLead` is exactly where the
 *    dangerous operations live, and was previously unclassifiable, so it could
 *    never trip default-deny or the approval gate).
 *
 * Hints are ADVISORY UX/classification metadata only — OpenRegister RBAC and the
 * `human-approval-gate` approval gate stay the sole authoritative invoke-time
 * boundary; a `readOnlyHint:true` (or a `scope:read`) descriptor can never bypass
 * either.
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
 * @spec openspec/specs/agent-tool-governance/spec.md#requirement-schema-scoped-whitelist-grants-with-default-deny-for-writedestructive-tools
 * @spec openspec/specs/agent-tool-governance/spec.md#scenario-a-declared-hint-overrides-a-conflicting-verb-suffix
 * @spec openspec/specs/agent-tool-governance/spec.md#scenario-a-hint-less-curated-tool-fails-closed
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
     * The grant entry meaning "this agent is INTENTIONALLY tool-less".
     *
     * An empty `Agent.tools` already means the opposite ("all discovered tools,
     * default-denied"), so there is no way to spell "no tools" by omission — this
     * sentinel is it. Recognising it explicitly is what lets a deliberate
     * no-tools agent be told apart from an agent whose grants resolve to zero by
     * ACCIDENT (a typo, or an id from a stale catalog). Both end up with an empty
     * function list; only the second is a defect, and `resolvesToNothing()` is how
     * callers tell them apart instead of silently treating a broken agent as a
     * chat-only one.
     *
     * @var string
     */
    public const NO_TOOLS_SENTINEL = '__none__';

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
        $descriptorsById = $this->descriptorsById(catalog: $catalog);
        $catalogIds      = array_keys($descriptorsById);
        $cleanGrants     = $this->sanitizeGrants(grants: $grants);

        if ($cleanGrants === []) {
            // "All discovered tools allowed" (legacy default) — default-deny still
            // strips every id `isWriteOrDestructive()` resolves to write/destructive
            // (hints first, verb-suffix fallback, fail-closed on anything else).
            return $this->applyDefaultDeny(ids: $catalogIds, descriptorsById: $descriptorsById);
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
     * Whether these grants say "no tools" ON PURPOSE — i.e. every entry is the
     * `__none__` sentinel.
     *
     * @param array<int, string> $grants Raw `Agent.tools` entries.
     *
     * @return bool True when the agent is deliberately tool-less.
     *
     * @spec openspec/changes/cli-runner-governed-mcp-and-egress/specs/governed-cli-mcp-transport/spec.md#requirement-a-grant-set-that-resolves-to-no-tools-fails-loudly
     */
    public function isExplicitNoTools(array $grants): bool
    {
        $clean = $this->sanitizeGrants(grants: $grants);
        if ($clean === []) {
            // An empty grant list means "all discovered tools" — the opposite.
            return false;
        }

        foreach ($clean as $grant) {
            if ($grant !== self::NO_TOOLS_SENTINEL) {
                return false;
            }
        }

        return true;

    }//end isExplicitNoTools()

    /**
     * Whether a grant set asked for tools but produced NONE — the misconfiguration
     * an agent cannot detect for itself.
     *
     * True only when the agent named at least one grant, did not use the
     * `__none__` sentinel, and resolution still came back empty. That combination
     * is never a legitimate state: every id was unknown to the catalog (a typo, a
     * renamed tool, an id from a UI offering a different id space), so the agent
     * silently loses every capability it was configured with. `[]` grants ("all,
     * default-denied") and `['__none__']` ("none, deliberately") are both
     * legitimate and return false.
     *
     * @param array<int, string> $grants        Raw `Agent.tools` entries.
     * @param array<int, mixed>  $resolvedTools The functions resolution actually produced.
     *
     * @return bool True when the grants are broken and the caller must not degrade silently.
     *
     * @spec openspec/changes/cli-runner-governed-mcp-and-egress/specs/governed-cli-mcp-transport/spec.md#requirement-a-grant-set-that-resolves-to-no-tools-fails-loudly
     */
    public function resolvesToNothing(array $grants, array $resolvedTools): bool
    {
        if ($resolvedTools !== []) {
            return false;
        }

        if ($this->sanitizeGrants(grants: $grants) === []) {
            return false;
        }

        return ($this->isExplicitNoTools(grants: $grants) === false);

    }//end resolvesToNothing()

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
     * Whether a fully-namespaced tool id is classified write/destructive.
     *
     * Precedence (see class docblock "Classification precedence"): a supplied
     * descriptor's `scope`/`destructiveHint`/`readOnlyHint` hint wins when
     * present; otherwise a 3-segment `{app}.{schema}.{verb}` id falls back to the
     * ADR-063 verb-suffix heuristic (verb `create`/`update`/`delete`); any other
     * hint-less shape (bare/2-segment hand-written, curated, or legacy id) FAILS
     * CLOSED — classified write/destructive, requiring an explicit grant and
     * tripping the approval gate, rather than silently passing as read (the
     * pre-`hermiq-prefer-tool-hints` behaviour left these unclassifiable, which
     * meant a curated write tool like `pipelinq.createLead` could never be
     * default-denied or gated — see `hermiq-prefer-tool-hints` design.md).
     *
     * @param string                   $id         A tool id (the `mcpId`/dotted form).
     * @param array<string,mixed>|null $descriptor The catalog descriptor for `$id`, when
     *                                             available (carries the optional
     *                                             `scope`/`destructiveHint`/`readOnlyHint`
     *                                             keys). Null when no descriptor is
     *                                             available for this id (e.g. a call the
     *                                             LLM attempted outside its resolved
     *                                             catalog) — falls straight to the
     *                                             verb-suffix/fail-closed rules.
     *
     * @return bool
     *
     * @spec openspec/specs/agent-tool-governance/spec.md#requirement-schema-scoped-whitelist-grants-with-default-deny-for-writedestructive-tools
     * @spec openspec/specs/agent-tool-governance/spec.md#scenario-a-declared-hint-overrides-a-conflicting-verb-suffix
     * @spec openspec/specs/agent-tool-governance/spec.md#scenario-a-hint-less-curated-tool-fails-closed
     */
    public static function isWriteOrDestructive(string $id, ?array $descriptor=null): bool
    {
        if ($descriptor !== null) {
            $fromHints = self::classifyFromHints(descriptor: $descriptor);
            if ($fromHints !== null) {
                return $fromHints;
            }
        }

        $parts = explode('.', $id);
        if (count($parts) === 3) {
            return in_array(end($parts), self::WRITE_VERBS, true);
        }

        // Hint-less, non-3-segment id: unclassifiable by any positive signal —
        // fail CLOSED rather than silently pass as read (hermiq-prefer-tool-hints).
        return true;

    }//end isWriteOrDestructive()

    /**
     * Classify a descriptor from its declared hint keys only — `scope` first
     * (closed vocabulary), then `destructiveHint`, then `readOnlyHint`; the
     * first key the descriptor actually sets wins.
     *
     * @param array<string,mixed> $descriptor The catalog descriptor.
     *
     * @return bool|null `true`/`false` when a hint key is present and usable,
     *                    `null` when the descriptor sets none of them (caller
     *                    falls back to the verb-suffix/fail-closed rules).
     */
    private static function classifyFromHints(array $descriptor): ?bool
    {
        if (isset($descriptor['scope']) === true && is_string($descriptor['scope']) === true) {
            return in_array($descriptor['scope'], self::WRITE_VERBS, true);
        }

        if (array_key_exists('destructiveHint', $descriptor) === true && is_bool($descriptor['destructiveHint']) === true) {
            return ($descriptor['destructiveHint'] === true);
        }

        if (array_key_exists('readOnlyHint', $descriptor) === true && is_bool($descriptor['readOnlyHint']) === true) {
            return ($descriptor['readOnlyHint'] === false);
        }

        return null;

    }//end classifyFromHints()

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
     * Strip an id list down to those NOT classified write/destructive, using each
     * id's own descriptor (hints, when set) — see `isWriteOrDestructive()`.
     *
     * @param array<int, string>                  $ids             Candidate ids.
     * @param array<string, array<string, mixed>> $descriptorsById Every candidate's descriptor, keyed by id.
     *
     * @return array<int, string>
     */
    private function applyDefaultDeny(array $ids, array $descriptorsById): array
    {
        $out = [];
        foreach ($ids as $id) {
            $descriptor = ($descriptorsById[$id] ?? null);
            if (self::isWriteOrDestructive(id: $id, descriptor: $descriptor) === true) {
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
     * Index every descriptor by its whitelist-matchable id: the dotted `mcpId`
     * when present (MCP-bridged/derived tools), else the bare `name` — so
     * `applyDefaultDeny()` can classify each id from its OWN descriptor's hints
     * (hermiq-prefer-tool-hints), not the id text alone.
     *
     * @param array<int, mixed> $catalog Descriptor list. Typed loosely on purpose: these cross
     *                                   the OpenRegister tool-facade boundary, so each entry is
     *                                   re-checked below.
     *
     * @return array<string, array<string, mixed>> id => descriptor, first occurrence wins.
     */
    private function descriptorsById(array $catalog): array
    {
        $byId = [];
        foreach ($catalog as $descriptor) {
            if (is_array($descriptor) === false) {
                continue;
            }

            $id = ($descriptor['mcpId'] ?? ($descriptor['name'] ?? null));
            if (is_string($id) === true && $id !== '' && isset($byId[$id]) === false) {
                $byId[$id] = $descriptor;
            }
        }

        return $byId;

    }//end descriptorsById()

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

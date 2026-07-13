# Design: hermiq-prefer-tool-hints

## Classification precedence

`ToolGrantResolver::isWriteOrDestructive(string $id, ?array $descriptor = null): bool`

1. **`scope`** (string, closed vocabulary `read`/`create`/`update`/`delete` —
   `McpAnnotationValidator::SCOPES`) — checked first because it needs no boolean interpretation:
   `in_array($scope, WRITE_VERBS, true)`.
2. **`destructiveHint`** (bool) — `=== true` classifies write/destructive.
3. **`readOnlyHint`** (bool) — `=== false` classifies write/destructive (the tool is not purely a
   read; per this codebase's existing binary model every `create`/`update`/`delete` verb is bucketed
   into the SAME "write/destructive" classification regardless of destructiveness nuance, so
   "not read-only" is sufficient).
4. **Verb-suffix fallback** — only reached when the descriptor is absent, or present but sets none of
   the three keys above: a 3-segment `{app}.{schema}.{verb}` id classifies from
   `in_array($verb, WRITE_VERBS, true)`, EXACTLY the pre-existing rule (regression-tested unchanged).
5. **Fail closed** — anything else (no descriptor/no hint keys AND not a 3-segment id) is now
   classified write/destructive (`true`), reversing the old `false`.

The first hint key the descriptor actually SETS wins; the others are not consulted even if present
(no descriptor in practice sets more than one — `McpProviderBridge::getFunctions()` forwards whatever
the provider declared, additively, one key at a time).

## Why fail closed, and why now

Before this change, `isWriteOrDestructive()` returned `false` unconditionally for anything that
wasn't a 3-segment derived id — by design, so that pre-existing hand-written/legacy 2-segment tools
(assumed dev-reviewed, e.g. `hermiq.sendMail`) were never accidentally caught by default-deny. That
was a reasonable trade-off when hints didn't exist: there was no way to tell a reviewed, genuinely
safe hand-written tool apart from an unreviewed, genuinely dangerous curated one, so the class
defaulted to trusting all of them.

That trade-off stops being defensible now that hints exist: a tool author can (and, going forward,
should) declare `readOnlyHint`/`destructiveHint`/`scope` on any tool — derived OR curated — and the
classifier prefers that declaration. A hint-less 2-segment tool is no longer "assumed reviewed and
safe" by omission; it is unannotated, and unannotated now means unknown, and unknown must fail
CLOSED, not open. This is the standard "closed by default, open by explicit declaration" posture
every other layer in this governance stack already uses (schema `x-openregister-mcp.enabled` opt-in,
`Agent.tools` default-deny on writes, GuardrailPolicy's fully-open-fallback being the ONE deliberate,
explicitly-documented exception for a different reason — see that class's own docblock).

**Practical migration note**: any existing hand-written 2-segment tool that is genuinely safe and
should keep flowing through an empty-grants ("all tools") agent must now either be granted explicitly
in `Agent.tools`, or carry `readOnlyHint:true`/`scope:read` on its `#[McpTool]` declaration. This is a
one-line annotation, not a behavioural change to the tool itself.

## Why NOT wire hints into `FacadeToolInvoker::requiresApprovalGate()`

`ToolSearchService::descriptor($id)` and `::isGranted($id)` read the SAME underlying `$resolved` map
(`registerResolved()` populates both). So whenever a descriptor IS available for a toolId at that call
site, `isGranted()` is necessarily also `true` for it — and `requiresApprovalGate()`'s final line
(`return isGranted() === false`) then always returns `false` regardless of what the classifier
decided. The descriptor is only ever ABSENT for a toolId the LLM attempted OUTSIDE its resolved
catalog (a hallucinated/never-offered call) — precisely the case with zero hint information available
regardless. Passing a descriptor through at this call site would therefore be dead code: it cannot
change any observable outcome, and no test could distinguish it from the id-only call. The verb-suffix
+ fail-closed fix is the ONLY improvement that matters here, and it already flows through unchanged
via the shared `isWriteOrDestructive()` id-only path. Hint-based classification does its real work
earlier — over the full descriptor-carrying catalog in `ToolGrantResolver::resolve()`/
`applyDefaultDeny()`, before a tool ever becomes part of an agent's resolved set.

## Integration with agent-guardrails (PR #55)

`FacadeToolInvoker::__call()` already layers two independent per-tool checks, in order:

1. `GuardrailPolicyService`'s effective `toolPolicy` map (`classifyTool()`) — an EXPLICIT,
   admin-authored per-organisation `auto`/`confirm`/`deny` override, resolved once per turn and keyed
   by toolId. `deny` refuses outright; `confirm` requires a fresh human approval per call.
2. `ToolGrantResolver::isWriteOrDestructive()` + `ToolSearchService::isGranted()` — the classifier this
   change touches — reached only when (1) resolves to `auto` (the default for any tool the policy
   doesn't mention).

These are orthogonal by design and this change does not merge them: (1) is a human-authored allow/deny
list that never reads a tool's shape or hints at all — an admin can `deny` a perfectly read-only tool,
or `auto`-approve a destructive one, on purpose, per organisation. (2) is the DERIVED, shape/hint-based
classification this proposal refines. Wiring hints into (1) would mean silently overriding an admin's
explicit policy entry with an inferred one — the opposite of "advisory, RESTRICT-only" hints are
supposed to be. No collision, no duplication: verified by reading `GuardrailPolicyService::classifyTool()`
(reads `policy['toolPolicy']` only, never a descriptor) and `FacadeToolInvoker::__call()` (guardrails
checked first, short-circuits before the grant/approval-gate check on `deny`/`confirm`, falls through
unchanged on `auto`).

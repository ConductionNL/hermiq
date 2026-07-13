# Proposal: hermiq-prefer-tool-hints

## Summary
`ToolGrantResolver::isWriteOrDestructive()` (agent-tool-governance-and-disclosure) classifies
write/destructive tools ONLY from the verb suffix of a 3-segment `{app}.{schema}.{verb}` derived id —
its own docblock documented this as a deliberate, temporary fallback because OpenRegister's
`McpProviderBridge::getFunctions()` did not forward the `destructiveHint`/`readOnlyHint`/`scope` MCP
annotation keys onto the LLPhant descriptor. That gap is now closed (OpenRegister PR that merged as
`10e605cea`, "optional hint/scope params on `#[McpTool]`, forwarded to both surfaces"): the bridge
forwards these keys additively whenever a provider (a schema's `x-openregister-mcp` dialect, or a
`#[McpTool(...)]`-annotated service tool) sets them.

Today a 2-segment curated tool (e.g. `pipelinq.createLead`) is **unclassifiable** by the verb-suffix
rule — `count(explode('.', $id)) !== 3` — so `isWriteOrDestructive()` returns `false` for it
unconditionally. That is a fail-OPEN security hole: such a tool can never be stripped by default-deny
(an empty `Agent.tools` = "all tools allowed" grants it) and can never trip the
`human-approval-gate` approval gate (`FacadeToolInvoker::requiresApprovalGate()` short-circuits to
"no gate needed" before ever checking whether it was actually granted) — and curated 2-segment
service tools are exactly where the dangerous operations live (create/update/delete on a domain
object via a hand-written controller method, not the coarse ADR-063 derived template).

Kind: **code**.

## Motivation
Prefer the now-available descriptor hints over the verb-suffix heuristic (closing the documented gap
this class always intended to close once hints were forwarded), and — because a hint-less curated
write tool was previously silently treated as safe — flip the unclassifiable case to fail CLOSED. An
agent's blast radius should never silently include an unreviewed write tool just because nobody
happened to name it in `Agent.tools` explicitly or annotate it.

## Affected Projects
- [x] Project: `hermiq` — `ToolGrantResolver` classification precedence
  (`lib/Service/Engine/ToolGrantResolver.php`); no other file changes behaviour (`FacadeToolInvoker`
  already calls the same classifier, unchanged call site).

## Scope

### In Scope
- Classification precedence in `ToolGrantResolver::isWriteOrDestructive()`: declared descriptor hints
  (`scope`, then `destructiveHint`, then `readOnlyHint`) win when present; otherwise the existing
  3-segment verb-suffix heuristic (unchanged, regression-tested); otherwise (a hint-less, non-3-segment
  id) FAIL CLOSED — classified write/destructive.
- `ToolGrantResolver::resolve()`/`applyDefaultDeny()` thread each candidate id's own catalog
  descriptor into the classifier (previously discarded after id-extraction).
- Hints remain ADVISORY UX/classification metadata only — OpenRegister RBAC and the
  `human-approval-gate` approval gate remain the sole authoritative invoke-time boundary; this is
  unchanged and re-asserted, not weakened, by this change.

### Out of Scope
- `GuardrailPolicyService`'s per-tool `toolPolicy` (agent-guardrails, PR #55) — an orthogonal,
  explicitly admin-authored `auto`/`confirm`/`deny` override checked BEFORE this classifier in
  `FacadeToolInvoker::__call()` and unrelated to descriptor hints; not touched.
- Extending the `{app}.{schema}.*` wildcard candidate-matching (`schemaVerbIds()`) to admit ids by
  hint rather than verb-name match — that mechanism is inherently about the ADR-063 derived-catalog
  naming template, not classification; a curated tool is reached via an exact-id grant or the
  empty-grants ("all tools") default-deny path, both already covered.

---
kind: code
depends_on: []
---

# Proposal: hermiq-guardrail-preamble-filter

## Summary

Apply the organisation's `GuardrailPolicy` input filter to the **assembled context preamble**,
not just to the user's message. Today `Engine::processMessage()` filters `$userMessage` and then
passes `$contextPreamble` — the concatenation of every `Context` object's `files`, `documents`
and `objectQueries` content the agent references — to the LLM completely unfiltered. ADR-024
Rule 3 and the public docs both state that guardrail input filters apply to the preamble "exactly
as they do to any other model input". That statement is false at HEAD. This change makes it true.

## Motivation

`ADR-024` Rule 3 (accepted) is the load-bearing security argument for why attaching Context
objects to an agent is **not** a new trust surface:

> Guardrail input filters (the guardrail policy this session made configurable) apply to the
> assembled preamble exactly as they do to any other model input — a `design.md` cannot smuggle a
> prompt-injection past the org's guardrail policy.

`docs/concepts/context.md` and `docs/concepts/safe-setup.md` repeat the claim to operators.

Verified against HEAD, `lib/Service/Engine/Engine.php`:

| Line | Code | Consequence |
|------|------|-------------|
| ~278 | `$contextPreamble = $this->contextAssembler->assembleForAgent(...)` | Preamble assembled |
| ~292 | `filterInput(policy: $guardrailPolicy, text: $userMessage)` | **Only** the user message is filtered |
| ~379 | `generateResponse(..., contextPreamble: $contextPreamble, ...)` | Preamble reaches the LLM **unfiltered** |

So a `design.md` in a Context's `files` **can** currently smuggle a prompt-injection past an org
policy that sets `promptInjectionAction: block`, and a secret pasted into a Context document is
sent verbatim to the model even under `piiAction: redact`. The preamble is arguably the *more*
dangerous input of the two: the user message is typed by the authenticated acting user, whereas
preamble content is authored by whoever could write to the referenced file — a different, wider,
and less-attributable set of people.

The existing `agent-guardrails` spec requirement is literally titled **"Input is filtered before
every LLM turn"**. This change is what makes that title honest.

## Affected Projects

- [ ] Project: `hermiq` — `Engine::processMessage()` filters the context preamble; `agent-guardrails` spec requirement modified; ADR-024 Rule 3 verified

## Scope

### In Scope

- Filter `$contextPreamble` through `GuardrailPolicyService::filterInput()` before it reaches
  `ResponseGenerationHandler::generateResponse()`.
- A preamble prompt-injection / PII **block** refuses the turn with a reason code distinct from
  the user-message equivalent, so the trace and the error say **where** the match came from.
- A preamble PII **redaction** means the masked text — never the original — reaches the LLM.
- A distinct `guardrail` trace step for the preamble boundary, recorded **only** when an action
  actually occurred.
- Unit tests proving the gap is closed.
- Verifying ADR-024 Rule 3's wording against the shipped behaviour.

### Out of Scope

- **`docs/`** — the public concept docs (`context.md`, `safe-setup.md`) are handled separately by
  the maintainer.
- **The output boundary.** `filterOutput()` already runs on `$aiResponse` and is unchanged.
- **The RAG context block** (`ContextRetrievalHandler::retrieveContext()`). It is a *separate*
  unfiltered path into the same prompt and is deliberately deferred — see Risk 3. It deserves its
  own change rather than being smuggled into this one.
- **New injection patterns.** `PROMPT_INJECTION_PATTERNS` is unchanged; this change is about
  *where* the existing detector runs, not how well it detects.
- **`ScheduleService`'s legacy (non-in-app-engine) branch.** It calls the same Engine when the
  flag is on; the legacy `ChatService` branch has no preamble to filter.

## Approach

Add a second, **separate** `filterInput()` call for `$contextPreamble`, placed immediately after
the existing user-message filter and **before** the user `Message` is persisted — so a refused
turn persists nothing, uniformly, regardless of which boundary refused it. Skip the call entirely
when the preamble is `''` (the common case: most agents have no attached Context), which keeps
the no-Context path byte-for-byte identical and costs zero regex work.

Two calls, not one concatenated call: concatenating would destroy attribution (you could not tell
*which* input matched) and would create false matches straddling the boundary between the two
texts. Details and alternatives in `design.md`.

## New Dependencies

None.

## Impact

- `lib/Service/Engine/Engine.php` — `processMessage()` gains a preamble filter branch; one new
  private helper for the context-scoped reason code.
- `tests/Unit/Service/Engine/EngineTest.php` — new tests.
- `openspec/specs/agent-guardrails/spec.md` — the "Input is filtered before every LLM turn"
  requirement is modified to cover the preamble.
- **No API contract change.** `ChatController` / `AssistantController` map *any*
  `GuardrailBlockedException` to the fixed `errorCode: 'guardrail_blocked'`; the reason code
  travels only in the exception message. The frontend needs no change.

## Cross-Project Dependencies

None. Self-contained within hermiq.

## Risks

### Risk 1: A legitimate Context document that *documents* prompt injection blocks the turn

**Severity:** Medium — **Mitigation:** This is a real false-positive class, not a hypothetical: a
security policy, a threat model, or this very proposal quoting `"ignore previous instructions"`
would match `PROMPT_INJECTION_PATTERNS` and refuse every turn of any agent referencing it. The
detector cannot distinguish *use* from *mention*. Mitigations, in order of preference: (a) the
filter is **off by default** — `promptInjectionAction` defaults to `'off'`, so no existing
installation changes behaviour on upgrade; (b) the policy is **per-organisation**, so an org that
runs security-documentation agents can leave injection filtering off, or scope it to a different
org; (c) the distinct reason code + trace step tell the operator immediately that it was the
*context*, not the user, that matched — which is the difference between "our agent is broken" and
"our design.md trips the filter". Documented honestly in `design.md`.

### Risk 2: Per-turn regex cost on a preamble that can approach `charBudget`

**Severity:** Low — **Mitigation:** The preamble is reassembled every turn and is budgeted at
`DEFAULT_CHAR_BUDGET = 8000` chars per Context object (`ContextAssembler`), which does **not**
truncate — it only flags `needsConsolidation` — so a multi-Context agent's preamble can exceed
that. Cost is 11 `preg_match` calls plus the PII patterns over that text, per turn. Quantified in
`design.md`. Skipping the empty-preamble case removes the cost entirely for agents with no
attached Context. This is the same order of work already accepted for the user message and is
dwarfed by the LLM round trip it precedes.

### Risk 3: The RAG context block remains an unfiltered path into the same prompt

**Severity:** Low — **Mitigation:** Explicitly out of scope and called out here rather than
silently left. Closing the preamble hole does not make the prompt fully filtered, and this
proposal does not claim it does. Filed as a deferred question so it is not lost.

## Rollback Strategy

Revert the commit. The change is one branch in one method plus tests; there is no schema change,
no migration, and no persisted state. An installation with no `GuardrailPolicy`, or with the
default `promptInjectionAction: 'off'` / `piiAction: 'off'`, is behaviourally unaffected either
way — the filter is a pass-through for them.

## Open Questions

See DEFERRED_QUESTIONS in the change report.

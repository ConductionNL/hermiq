# Design: hermiq-guardrail-preamble-filter

## Architecture Overview

`Engine::processMessage()` assembles two independent untrusted texts and sends both to the LLM:

```
                 ┌─ $userMessage ──────── filterInput() ──┐
                 │   (typed by the acting user)           │
processMessage() │                                        ├─→ generateResponse() ─→ LLM
                 │                                        │
                 └─ $contextPreamble ──── (NOTHING) ──────┘   ← the gap at HEAD
                     ContextAssembler::assembleForAgent()
                     = every referenced Context's files /
                       documents / objectQueries content
```

After this change both edges carry a filter:

```
                 ┌─ $userMessage ──────── filterInput() ──── reason: prompt_injection
                 │                                           trace: "Input filter"
processMessage() │
                 └─ $contextPreamble ──── filterInput() ──── reason: prompt_injection_in_context
                     (skipped when '')                       trace: "Context preamble filter"
```

Ordering inside `processMessage()`:

1. `assembleForAgent()` → `$contextPreamble` (unchanged, line ~278)
2. `resolveGuardrailPolicy()` → `$guardrailPolicy` (unchanged, line ~290) — **one** policy read per
   turn, reused for both boundaries
3. `filterInput($userMessage)` → trace step, throw, reassign (unchanged, lines ~292-319)
4. **NEW:** `filterInput($contextPreamble)` → trace step, throw, reassign
5. `storeMessage(role: 'user', ...)` (unchanged)
6. … → `generateResponse(contextPreamble: $contextPreamble, ...)` now receives the **filtered**
   preamble

Step 4 lands **before** step 5 deliberately: the existing contract for a refused turn is "no
user/assistant Message is stored for this attempt". A preamble block must honour the same
contract, or a refused turn would leave a half-turn in the conversation.

## API Design

No API change. `ChatController::sendMessage()` and `AssistantController::converse()` both map any
`GuardrailBlockedException` to a fixed `errorCode: 'guardrail_blocked'` — the reason code travels
only inside `getMessage()` / `getReason()`. A new reason string therefore needs no frontend or
contract change. (Verified: `lib/Controller/ChatController.php:229`,
`lib/Controller/AssistantController.php:128`.)

## Database Changes

None.

## Nextcloud Integration

- Controllers: none changed.
- Services: `OCA\Hermiq\Service\Engine\Engine` (modified);
  `OCA\Hermiq\Service\GuardrailPolicyService` (**called**, not modified);
  `OCA\Hermiq\Service\Engine\ContextAssembler` (unchanged, its output is now filtered).
- Mappers/Entities: none.
- Events/Hooks: none.

## Security Considerations

This change **is** the security consideration. Specifics:

- **Threat closed.** Context content authored by anyone with write access to a referenced file /
  document / OpenRegister object could inject instructions into the system prompt with the org's
  guardrail policy fully engaged and no trace of it. Note the acting-user identity (ADR-023) does
  **not** mitigate this: the preamble is read as the acting user, but its *content* was authored
  by someone else, possibly long before.
- **Threat NOT closed.** The RAG context block (`ContextRetrievalHandler::retrieveContext()`,
  line ~339) is still unfiltered and still reaches the model. This change narrows the surface; it
  does not eliminate it. See Deferred Questions.
- **Fail-open is preserved and is deliberate.** `$this->guardrailPolicyService?->filterInput(...)
  ?? [passthrough]` — when the service is absent (it is a nullable constructor dep) the preamble
  passes through, exactly as the user message already does. This is consistent with the existing
  boundary, not a new fail-open.
- **No new secret exposure.** A `redact` match masks the preamble before it leaves the process;
  the original preamble is never persisted (it is not persisted at all today).

## File Structure

```
lib/
  Service/
    Engine/
      Engine.php                 (modified — processMessage(); + contextReasonFor() helper)
tests/
  Unit/
    Service/
      Engine/
        EngineTest.php           (modified — 4 new tests)
openspec/
  specs/
    agent-guardrails/
      spec.md                    (modified via this change's delta)
  architecture/
    adr-024-agent-context-concepts.md   (verified — see Decision 5)
```

## Decisions

### Decision 1: Two separate `filterInput()` calls, not one concatenated call

**Chosen:** call `filterInput()` once for `$userMessage` and once for `$contextPreamble`.

**Rejected:** `filterInput($userMessage . "\n" . $contextPreamble)`.

Rationale:

- **Attribution.** One call returns one `blocked`/`reason` for the union. The operator could not
  tell whether the user tried to jailbreak the agent or whether a `design.md` merely quotes a
  jailbreak. Those are different events with different responses (discipline vs. edit the doc).
- **Boundary artefacts.** Concatenation creates text that exists in neither input. A user message
  ending `"...please ignore"` followed by a preamble starting `"previous instructions are..."`
  would match `~\bignore\s+(all\s+|any\s+)?(previous|prior|above)\s+instructions\b~i` across the
  seam — a match on a string no one wrote.
- **Redaction is unmergeable.** `applyPiiAction()` returns one string. Splitting it back into the
  two texts afterwards would mean counting characters through a substitution that changed the
  length. Two calls return two independently-usable strings.
- **Cost is identical.** Same total bytes scanned either way.

### Decision 2: The redacted preamble is what reaches the LLM

Same contract as the user message: the model only ever sees masked text. `$contextPreamble` is
reassigned from `$preambleFilter['text']` before `generateResponse()`, mirroring
`$userMessage = (string) $inputFilter['text'];` at line ~319.

Unlike the user message there is no *persisted* copy of the preamble to keep consistent — the
preamble is assembled fresh each turn and never stored — so the "persist the redacted copy"
half of the user-message contract has no analogue here.

### Decision 3: A preamble block uses a `_in_context`-suffixed reason code

`filterInput()` returns `reason: 'prompt_injection'` or `'sensitive_content'`. For the preamble
boundary the Engine maps that to `'prompt_injection_in_context'` / `'sensitive_content_in_context'`
by suffixing `_in_context`.

Rationale for the suffix over a hand-written constant per case:

- It is mechanical, so it stays correct if `GuardrailPolicyService` ever adds a third reason code
  — a hand-written `match` would silently fall through to a wrong or null reason.
- It preserves *both* facts the operator needs: **what** matched (`prompt_injection`) and
  **where** it came from (`_in_context`). A reason of just `'context_blocked'` would lose the
  first; reusing `'prompt_injection'` loses the second — and losing the second is the whole point
  of Decision 3, because "a user tried to jailbreak our agent" and "our onboarding doc contains
  the phrase 'ignore previous instructions'" demand opposite responses from an operator.
- `GuardrailBlockedException`'s docblock documents the reason as "the filter's short reason code
  (`prompt_injection`|`sensitive_content`)". The suffixed codes stay in that shape and remain a
  stable, machine-matchable string. The docblock is updated to name them.

**Rejected:** a new exception subclass (`ContextGuardrailBlockedException`). The controllers
`instanceof`-check `GuardrailBlockedException` to set `errorCode`; a subclass would still satisfy
that check but would add a type for a distinction that is already carried in data. No caller needs
to branch on the type.

### Decision 4: An empty preamble skips the filter entirely

`if ($contextPreamble !== '')`. `assembleForAgent()` returns `''` for a null agent or an agent
with no `contextRefs` — "a no-op for most agents" per its own docblock. Skipping is:

- **Correct:** filtering `''` cannot match anything. `matchesPromptInjection()` itself early-returns
  on `''`, and a PII redaction of `''` is `''`.
- **Free:** it removes 100% of the added cost from every agent with no attached Context.
- **Trace-preserving:** guarantees the no-Context path inserts no step (see Decision 5).

### Decision 5: Only an actual action records a trace step — preserved exactly

The existing `guardrailActed()` helper returns true only for a block or a redaction that *changed*
the text. The preamble branch reuses that **same helper** rather than reimplementing the check, so
a fully-open policy inserts no step and an org with no `GuardrailPolicy` sees an identical trace to
before this change. This is a spec property ("record every input block, output block/redaction …
as a trace step" — not every turn), not an optimisation. Two mechanisms guard it: `guardrailActed()`
and the Decision-4 empty skip. A dedicated test asserts zero steps for a fully-open policy with a
non-empty preamble.

The step is named **"Context preamble filter"** to distinguish it from "Input filter" in the
timeline; both use `type: 'guardrail'`, so any existing consumer filtering on the type keeps
working.

## Risks / Trade-offs

### [False positive: a Context that *documents* injection blocks every turn] → Mitigation

The detector is 11 regexes matching literal phrases; it cannot distinguish **use** from
**mention**. A Context whose `files` include a security policy, a threat model, a prompt-injection
runbook — or a copy of this design doc, which quotes `"ignore previous instructions"` twice —
matches. Because the preamble is reassembled **every turn**, the result is not a one-off refusal:
**every turn of every agent referencing that Context fails**, until someone edits the document.
That is a materially worse failure mode than a user-message false positive, which the user can
work around by rephrasing. The operator cannot rephrase a file they may not own.

Escape hatches, in order:

1. **Off by default.** `promptInjectionAction` reads `?? 'off'` and only `'block'` triggers a
   refusal. An installation that never configured a `GuardrailPolicy`, or left injection filtering
   at its default, sees **no** behaviour change on upgrade. This is the primary reason the risk is
   acceptable: it is opt-in.
2. **Per-organisation policy.** `effectivePolicyFor(organisation:)` resolves per org. An org
   running security-documentation agents can leave `promptInjectionAction: 'off'` while another
   org on the same instance keeps it `'block'`.
3. **Diagnosability.** The `_in_context` reason + the "Context preamble filter" trace step name
   the culprit boundary immediately. Without Decision 3 the operator would see
   `prompt_injection` and hunt through user messages that contain nothing.

Not mitigated: there is **no per-Context allowlist**. An org that wants injection filtering on for
user messages but off for one trusted Context cannot express that today. Deferred — see the
report's DEFERRED_QUESTIONS.

### [Per-turn regex cost over a preamble that can approach the char budget] → Mitigation

Shape of the cost, per turn, for an agent **with** attached Context:

- **Input size.** `ContextAssembler` budgets at `DEFAULT_CHAR_BUDGET = 8000` chars — but the budget
  is a *nudge*, not a truncation: it only sets `needsConsolidation` when `mb_strlen($body) >
  $budget` (`ContextAssembler.php:193-196`). `assembleForAgent()` concatenates **every** referenced
  Context. So the realistic range is ~1-8 KB for a single Context and can reach tens of KB for a
  multi-Context agent. The filter must be assumed to run over an unbounded string.
- **Work.** Up to 11 `preg_match` calls (`PROMPT_INJECTION_PATTERNS`) — each a linear scan with no
  catastrophic-backtracking construct (they are literal-anchored `\b…\b` alternations over `\s+`,
  no nested quantifiers) — plus the `applyPiiAction()` pattern set. Roughly O(11 × n) plus PII, n =
  preamble length. Single-digit milliseconds at 8 KB; low tens at 100 KB.
- **Frame of reference.** This precedes an LLM round trip measured in **seconds**. The added cost
  is ~3 orders of magnitude below the operation it guards, and it is the *same* work already
  accepted, unremarked, for the user message.
- **Zero for the common case.** Decision 4 skips it entirely when no Context is attached.

Accepted. The honest statement is: this is per-turn work proportional to total attached Context
size, it is not cached, and it grows with the number of Contexts an agent references. If preamble
assembly is ever memoised across turns, the filter result should be memoised with it — filtering
must never be the thing that gets cached while assembly stays live, or a stale filter would bless
new content.

### [Two `filterInput()` calls double the policy-dependent branching] → Mitigation

Both calls share one `resolveGuardrailPolicy()` result (one OpenRegister read per turn, unchanged)
and one `guardrailActed()` helper. No duplicated policy logic.

## Migration Plan

None required. No schema, no stored state, no data migration.

**Deploy:** ship with the app; the behaviour activates only for orgs whose `GuardrailPolicy`
already sets a non-`off` input action.
**Rollback:** revert the commit. No cleanup.

## Trade-offs

| Alternative | Why not |
|---|---|
| Filter inside `ContextAssembler::assembleForAgent()` | Assembler would need the policy + organisation injected, coupling a text-assembly service to guardrails, and the block would have to travel back through a return shape that today is a plain `string`. The Engine already owns the policy and the boundary. |
| Filter inside `ResponseGenerationHandler::generateResponse()` | Too late for the "no LLM call, nothing persisted" contract — the user Message is already stored by then. |
| One concatenated `filterInput()` call | Decision 1. |
| Reuse `reason: 'prompt_injection'` for both boundaries | Decision 3 — destroys the user-vs-document distinction the operator needs to act. |
| Truncate the preamble before filtering to bound cost | Would silently drop context the agent needs, and an injection placed past the cut would evade the filter while still reaching the model — strictly worse than the bug being fixed. |

## Open Questions

Carried to the report's DEFERRED_QUESTIONS.

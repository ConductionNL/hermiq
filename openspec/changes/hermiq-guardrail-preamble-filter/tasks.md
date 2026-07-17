# Tasks: hermiq-guardrail-preamble-filter

## Task 1: Filter the context preamble in `Engine::processMessage()`

- [x] Add a `filterInput()` call for `$contextPreamble`, separate from the user-message call, placed after the user-message throw and before `storeMessage(role: 'user')`
- [x] Skip the call entirely when `$contextPreamble === ''` (design Decision 4)
- [x] Reuse the already-resolved `$guardrailPolicy` — no second `resolveGuardrailPolicy()` read
- [x] Record a `type: 'guardrail'`, name `Context preamble filter` trace step ONLY when `guardrailActed()` is true (reuse the existing helper, do not reimplement)
- [x] Throw `GuardrailBlockedException` with the `_in_context`-suffixed reason on a preamble block
- [x] Reassign `$contextPreamble` from the filter's `text` so the redacted preamble reaches `generateResponse()`
- [x] Add the private `contextReasonFor()` helper mapping a filter reason to its `_in_context` code
- [x] Update `GuardrailBlockedException`'s docblock to name the two new reason codes

Acceptance criteria:

- A prompt-injection present only in the preamble refuses the turn; `generateResponse()` is never called.
- The preamble block's reason is `prompt_injection_in_context`, distinct from `prompt_injection`.
- A preamble PII redaction sends the masked text to `generateResponse()`.
- No user or assistant `Message` is persisted for a preamble-refused turn.
- An empty preamble adds zero `filterInput()` calls.
- A fully-open policy over a non-empty preamble records zero guardrail trace steps.

## Task 2: Unit tests proving the gap is closed

- [x] Test: injection in the preamble blocks the turn, LLM never called, nothing persisted
- [x] Test: the preamble block reason is `prompt_injection_in_context` (asserts the distinct code)
- [x] Test: a preamble PII match under `redact` reaches `generateResponse()` masked
- [x] Test: a fully-open policy with a non-empty preamble records zero guardrail steps (trace-property regression guard)
- [x] Verify Task-2 tests FAIL against the unmodified Engine (hand-revert, run, re-apply — no `git stash`)
- [x] Stub `filterInput()` per-argument (`willReturnCallback`), not a bare `willReturn`, so the two boundaries cannot pass vacuously

Acceptance criteria:

- The injection-in-preamble test fails against pre-change code with the LLM having been called.
- Mocks distinguish the user-message call from the preamble call by argument, not by call order alone.
- The existing guardrail tests still pass unmodified.

## Task 3: Verify ADR-024 Rule 3 and run quality gates

- [x] Verify ADR-024 Rule 3's wording matches the shipped behaviour; correct it if it overclaims
- [x] Run `phpunit -c phpunit-unit.xml` — green
- [x] Run `phpcs --standard=phpcs.xml lib/Service/Engine/Engine.php` — clean
- [x] Run `phpstan analyse lib/Service/Engine/Engine.php` — clean

Acceptance criteria:

- ADR-024 Rule 3 is either accurate as written or corrected to match what ships.
- `docs/` is NOT touched (handled separately by the maintainer).
- All three tools pass with real, quoted output.

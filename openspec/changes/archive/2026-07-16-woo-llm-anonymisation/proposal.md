---
kind: code
---

# Proposal: woo-llm-anonymisation

## Why

Six Dutch municipalities (led by Gemeente Hoeksche Waard, Computable Award
2025) now ship "Anonimiseren met LLM" / "Anonimiseren bij de bron" directly
into their case-management stack, and xxllnc acquired DataMask specifically
for AI-assisted document anonymisation feeding Woo publication. Procest's
existing `WOORedactionService` only orchestrates hand-off to Docudesk or a
manual "upload a redacted version" fallback — it detects nothing itself.
LLM-assisted PII/redaction-span proposal is the modern differentiator, and
per the fleet rule (AI functionality lives in Hermiq) that detection
capability must be built here, not in procest.

Hermiq's existing `case-assistant-surface` (`POST /api/assistant/converse`)
is a free-text conversational endpoint — it returns a `reply` string, not
structured, position-addressable spans, and it applies an output PII filter
that would scramble exactly the data an anonymisation caller needs to see.
Reusing it as-is would force procest to parse prose for PII locations, which
is neither reliable nor auditable. A small, structured, sibling endpoint is
needed.

## What Changes

- Add `POST /api/assistant/detect-pii`: `{text, context: {app, objectType?,
  objectRef?}}` → `{spans: [{start, end, category, confidence}], usage}`.
- Add `AssistantService::detectPii()`: reuses the SAME tool-free-by-
  construction agent pattern, `ResponseGenerationHandler`, `ProviderFactory`,
  and `GuardrailPolicyService` the case-assistant-surface already uses — NO
  new LLM plumbing. Differs from `converse()` in three deliberate ways:
  - No `Conversation`/`Message` persistence (a one-shot structured
    extraction call has no session; more importantly, persisting raw
    document text containing PII into Hermiq's own OR-backed conversation
    store would duplicate exactly the data this feature exists to protect).
  - The effective `GuardrailPolicy`'s prompt-injection input filter still
    runs, but its PII input-redaction action is deliberately bypassed for
    this endpoint — the whole point is that the model must SEE the PII to
    locate it; redacting it first would defeat detection. The output PII
    filter is not applied either, since a JSON span envelope must not be
    mangled by text-redaction.
  - The model is instructed to emit offsets + a category label only, never
    to repeat the PII substring in its answer, minimising incidental
    leakage into the response body/logs beyond the caller-supplied input.
- Register the route in `appinfo/routes.php`.
- Unit tests for `AssistantService::detectPii()` (validation, guardrail
  prompt-injection block, JSON parse failure, tool-free agent reuse) and
  `AssistantController::detectPii()` (auth, error mapping).

## Impact

- Affected specs: new `woo-llm-anonymisation` capability (hermiq side).
- Affected code: `lib/Service/Assistant/AssistantService.php`,
  `lib/Controller/AssistantController.php`, `appinfo/routes.php`,
  `tests/Unit/Service/Assistant/AssistantServiceTest.php`,
  `tests/Unit/Controller/AssistantControllerTest.php`.
- Consumer: procest's `woo-llm-anonymisation` change (separate proposal, same
  name, procest repo) — a thin HTTP client mirroring
  `HermiqAssistantClient`, merging this endpoint's proposed spans with
  procest's own deterministic PII regex floor and gating everything behind
  human review before any downstream redaction/publication step.
- NOT in scope: the redaction execution itself (blacking out a PDF/DOCX) —
  that remains procest's `WOORedactionService`/Docudesk hand-off, unchanged.

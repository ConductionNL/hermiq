# Design: woo-llm-anonymisation (hermiq)

## Context

procest needs LLM-assisted PII/redaction-span detection for Woo document
disclosure review. Fleet rule: AI lives in Hermiq. Hermiq already exposes
`case-assistant-surface` (`POST /api/assistant/converse`) for free-text,
tool-free, grounded chat — the question this design answers is whether that
surface can be reused as-is, or whether a small sibling is warranted.

## Decision 0: reuse the plumbing, add a sibling endpoint

`converse()` cannot serve this need directly:

1. Its response shape is a free-text `reply`, not position-addressable
   spans. Asking the model to embed JSON inside a chat reply and parsing it
   out of prose is unreliable and untestable compared to a purpose-built
   prompt+parse contract.
2. Its output `GuardrailPolicyService::filterOutput()` call applies the
   organisation's PII action to the reply text. For a normal chat answer
   that is exactly the right default. For a PII-*detection* answer it is
   actively harmful — scrubbing PII out of a response whose entire job is
   to report PII locations breaks the feature by design.
3. It always creates/reuses a `Conversation` and persists both turns via
   `MessageHistoryHandler`. Persisting full unredacted document text into
   Hermiq's own `Message` OR objects would duplicate the sensitive data this
   feature exists to protect, with no corresponding benefit (there is no
   multi-turn "PII detection conversation" to continue).

None of these are surface-breaking changes to `converse()` — they are
reasons this is a **different, purpose-built endpoint** that reuses the same
underlying components (`ResponseGenerationHandler`, `ProviderFactory`,
`GuardrailPolicyService`, the tool-free `__none__`-sentinel agent pattern),
exactly as `converse()` itself reuses `agent-engine-port`'s handlers instead
of duplicating them. No new LLM plumbing is added anywhere in this change.

## Decision 1: prompt-injection filtering stays on; PII input filtering is bypassed

`GuardrailPolicyService::filterInput()` conflates two independent checks:
prompt-injection detection (always safe to keep) and the PII input action
(redact/block PII in the outbound prompt — the opposite of what a detector
needs). `detectPii()` resolves the effective policy exactly like `converse()`
does, then calls `filterInput()` against a **derived copy** of that policy
with `inputFilters.piiAction` forced to `'off'` before the call — so a
`promptInjectionAction: block` policy still refuses hostile document text
(`GuardrailBlockedException`, 422, same error code the caller already
handles), while PII in a legitimate document reaches the model as-is. This
is documented behaviour, not a guardrail bypass: PII visibility is the
precondition for detecting it, and the endpoint's whole output IS a PII
report, gated by NoAdminRequired auth + the caller's own authorization
boundary (procest gates this behind the same case-mutation check every WOO
assessment endpoint already uses).

No output PII filter is applied at all — the response is a structured span
list, not free text, and scrubbing it would corrupt the JSON envelope.

## Decision 2: no conversation persistence

Unlike `converse()`, `detectPii()` does not call `resolveConversation()` /
`MessageHistoryHandler`. It is a stateless, single-shot structured
extraction: `ResponseGenerationHandler::generateResponse()` is called with
`messageHistory: []` directly. This keeps sensitive document text from
being duplicated into a second OR-backed store, and matches the caller's
usage pattern (procest calls this once per document-assessment review, not
as a back-and-forth chat).

## Decision 3: dedicated tool-free agent, dedicated prompt

A new `findOrCreateDetectorAgent(app)` mirrors `findOrCreateAgent()` exactly
(`tools: ['__none__']`, `isPrivate: true`) but with a distinct name (`PII
Span Detector ({app})`) and a system prompt that: (a) instructs strict JSON
output (`{"spans":[{"start":int,"end":int,"category":string,"confidence":
"low"|"medium"|"high"}]}`), (b) explicitly forbids repeating the PII
substring itself in the response, (c) lists the target categories (persons,
BSN, addresses, contact data, signatures, medical/financial mentions) so the
model's recall is steered toward what procest's deterministic regex floor
cannot catch (names, addresses, free-text medical/financial mentions —
regex already owns BSN/IBAN/phone/postcode).

## Decision 4: strict JSON parsing, fail loud

The reply is parsed with `json_decode()` after stripping a leading/trailing
markdown code fence (models routinely wrap JSON in ```` ```json ```` even
when instructed not to). An undecodable reply, or one whose top-level shape
is not `{"spans": [...]}`, throws `Exception('...', 502)` — the SAME
generic 5xx path `AssistantController` already maps to "Failed to process
message". procest's `HermiqAnonymisationClient` treats any non-2xx as a
signal to fall back to rules-only detection (fail-closed on the consumer
side — see procest's own design.md).

## Risks / Trade-offs

- A malicious/malformed document could attempt prompt injection via its own
  text content; prompt-injection filtering stays active (Decision 1) as the
  first line of defence, and the agent is tool-free by construction so even
  a successful injection cannot escalate to a tool call.
- Span offsets are UTF-8 codepoint-relative to whatever the model perceives
  as the input string boundaries; the caller (procest) treats the model's
  offsets as advisory positions for UI highlighting, not as a byte-exact
  contract — mismatches degrade to "span not found in text", not a crash
  (procest's merge step clamps/discards any span whose `[start,end)` falls
  outside `[0, strlen(text))` or where `start >= end`).

## Migration Plan

None — additive only, new route + new service method, zero changes to
`converse()`'s existing behaviour or contract.

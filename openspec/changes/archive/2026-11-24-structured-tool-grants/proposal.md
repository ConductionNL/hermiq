---
kind: code
---

# Proposal: structured-tool-grants

## Summary

Store an agent's tool grants as a **structure** — `app → subject → action → tool id` — instead of a
flat `string[]` that every consumer takes apart again with its own rule.

The change began as a parser fix. The grant matrix rendered the entire OpenRegister core as thirty
one-off rows, and `cms_create_page` appeared under a subject called "create". Locating the verb
segment fixed both, and a control run against the old parser failed 14 of the new fixtures — so the
parser really was wrong.

But the parser was the symptom. It existed only because the stored shape discarded the structure and
asked each reader to reconstruct it from a string. Three spellings are in live use
(`pipelinq.lead.search`, `hermiq.listFiles`, `list_registers`), and **87 of 177 tools declare no
`subject`/`action` at all**, so reconstruction is guesswork by construction. This change stores what
the readers need instead of asking them to infer it.

ADR-095 records the decision and supersedes the `string[]` half of ADR-035 Decision 4.

## The one rule that keeps a stored grant honest

**The tool id is stored, not derived.** `hermiq.listFiles` sits at coordinates `(hermiq, file, list)`;
recomputing an id from those coordinates gives `hermiq.file.list`, which is not a tool and never was.
Every leaf therefore carries the catalogue's own id verbatim.

## Capabilities

### New Capabilities
- `structured-tool-grants`

## Compatibility

Both shapes load. A stored **list** is the legacy grammar and is honoured as-is; a stored **map** is
the structured shape. Shape detection uses `array_is_list()` — an earlier `is_int($key)` test made a
structured map whose first key was numeric read as a legacy list, which resolved to **no tools at
all**. A legacy list is passed through rather than round-tripped, because regrouping by app reorders
the grants and `baseToolIds()` promises order.

## What this change does not do

It does not declare the taxonomy. **87 of 177 tools still publish no `subject`/`action`**, so the
matrix still falls back to parsing for those. The mechanism to fix that already exists — `subject`
and `action` are in `McpProviderBridge::PASSTHROUGH_KEYS` and `ToolRegistryFacade` reads them
straight off `getFunctions()` — so it is two keys per descriptor, not a redesign. That work is
tracked separately; OpenRegister's 25 undeclared tools are the worst-parsed and come first.

## Risks

The grant matrix has previously destroyed 7 of 8 grants while every unit test stayed green, because
the tests exercised the codec rather than the round trip through the UI. The read and the write path
both change here, so **an unmodified save must be verified through the browser**, not only in unit
tests: a read that returns less than was stored deletes the difference on save. The spec states this
as a requirement rather than leaving it to review.

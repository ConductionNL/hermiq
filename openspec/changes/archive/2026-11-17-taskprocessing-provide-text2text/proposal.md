---
kind: code
depends_on: []
---

# Proposal: taskprocessing-provide-text2text

## Why

SPECTR-NEXTCLOUD-PLAN.md §8 move 2 ("Provide"): Nextcloud's TaskProcessing is the
single AI API for the whole instance (Assistant UI, Mail, decidesk, …), but on our
instances **no provider is installed**, so every consumer 503s — decidesk's
`MinutesDraftService` guards on `hasProviders()` and returns 503 without one. If
Hermiq registers `ISynchronousProvider`s for the `text2text` family backed by its
own LLM layer, the entire instance gets AI from one Hermiq configuration, with zero
changes to the consuming apps.

This is the reciprocal of `taskprocessing-consume-ui` (move 1): there Hermiq
*consumes* TaskProcessing for its own background work; here Hermiq *provides* it for
everyone else.

## What Changes

- **`lib/TaskProcessing/AbstractTextProvider.php`** (new) — a base
  `ISynchronousProvider` carrying the 12 optional-shape/enum/default accessors (all
  empty for a plain text→text provider) plus a shared `process()` that frames the
  input and generates through Hermiq's configured LLM. The `core:text2text`,
  `core:text2text:summary` and `core:text2text:headline` task types share the
  identical `{input}`→`{output}` shape (verified against the NC 33/34 checkout), so
  subclasses supply only id/name/runtime and prompt framing.
- **`lib/TaskProcessing/Text2TextProvider.php`** (new, `core:text2text`) — passes the
  prompt through verbatim (the raw surface decidesk already frames itself).
- **`lib/TaskProcessing/Text2TextSummaryProvider.php`** (new, `core:text2text:summary`)
  — frames the input in a "summarise the following" instruction.
- **`lib/TaskProcessing/Text2TextHeadlineProvider.php`** (new, `core:text2text:headline`)
  — frames the input in a "generate a concise headline" instruction.
- **`lib/Service/Llm/ProviderFactory.php`** — a new public `generateText()` seam:
  one blocking generation call resolving the configured driver, with the `nextcloud`
  (TaskProcessing) driver forbidden so a Hermiq provider can never recurse into
  TaskProcessing.
- **`lib/AppInfo/Application.php`** — registers the three providers via
  `registerTaskProcessingProvider(...)`.

## Non-Goals

- Registering the task TYPES — `core:text2text*` ship with NC core; Hermiq only
  registers PROVIDERS for them.
- `core:text2text:chatwithtools` / translation / image / audio task types — out of
  scope; the text2text family is the un-503 win.
- Streaming — TaskProcessing providers are pull/sync (`ITriggerableProvider` is
  absent in NC 33); `ISynchronousProvider::process()` is a blocking call, which is
  exactly right for background text work.

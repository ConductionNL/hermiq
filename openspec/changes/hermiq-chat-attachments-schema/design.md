# Design: hermiq-chat-attachments-schema

## Architecture Overview

This change adds one additive property to one OpenRegister schema. There is no code, no
endpoint, and no runtime behavior at this revision — the design work is entirely in **choosing
the field's shape and its home**, because both are load-bearing for the follow-on
`hermiq-chat-attachments` code change and both are expensive to change once objects are stored.

Where the field sits in the existing model:

```
Agent
 └── contextRefs[] ──► Context            (durable, curated, per-AGENT)
                        ├── files[]        {path, description}        ─┐
                        ├── documents[]    {name, body, format, desc}  ├─► ContextAssembler
                        ├── objectQueries[]                            │   → budgeted preamble
                        └── viewRefs[]     (deferred)                 ─┘

Conversation
 └── Message                              (per-TURN)
      ├── conversationId, role, content, sources, context
      └── attachments[]  {path, name, description}   ◄── THIS CHANGE
```

The parallel between `Context.files[] {path, description}` and `Message.attachments[]
{path, name, description}` is deliberate and is the core decision below.

## Goals / Non-Goals

**Goals**
- Give a chat turn a place to carry a reference to a file in the acting user's Nextcloud.
- Shape that reference so the follow-on change can read it through the **existing**
  `IRootFolder` path (`ContextAssembler::resolveFiles()`), not a new one.
- Land the two version bumps that gate the register re-import.

**Non-Goals**
- Any code. Any endpoint. Any UI.
- A binary/image/vision-capable shape (decided below, and again in change 2's design).
- Deciding *where uploads land on disk*, size/type enforcement, or budget interaction — those
  are behavior, and they belong to `hermiq-chat-attachments`.

## Decisions

### Decision 1: An attachment is per-turn context *material* — not a fourth concept, and not a `Context`

ADR-024 Rule 1 fixes three concepts: **Skill** = capability, **Context** = situation reference,
**Memory** = learned state, all converging in ONE budgeted preamble via `ContextAssembler`. A
chat attachment must be placed against that table, not bolted beside it.

The answer: **an attachment is Context-*kind* material with a Message lifecycle.** It is
mechanically identical to a `Context.files` entry — a pointer at a Nextcloud file, read as the
acting user, rendered as text into the model's input under a budget. It differs in exactly one
dimension: **lifecycle/ownership**. A `Context` is attached to an *agent* and applies to *every*
run; an attachment is supplied by a *user* at send time and applies to *one* turn.

So this is **not a fourth parallel concept** — it introduces no new source kind, no new assembly
seam, and no new trust posture. It reuses the `files` semantics wholesale and only re-homes the
lifecycle. ADR-024's concept table stays valid as written; "attachment" is a *lifecycle variant*
of the Context/`files` source kind, not a new row.

**Alternative considered — model an attachment as a `Context` object with a `files` entry, and
reference it from the Message.** Rejected. It inverts the concept: `Context` per ADR-024 Rule 2
is "curated context that is part of the agent's definition", versioned and shareable. Minting a
throwaway `Context` object per uploaded file would flood the register with junk objects that a
Context editor UI would then have to hide, and would make an ephemeral thing look durable and
shareable. The cost is a second read call site in change 2 (`Context.files` and
`Message.attachments` are read separately); the benefit is that neither concept lies about its
lifecycle. Worth it.

**Alternative considered — reuse the existing `Message.context` property.** Rejected. That field
is already taken and means something else entirely: it is the *CnAiContext page snapshot*
(`appId`, `pageKind`, `objectUuid`, `registerSlug`, `schemaSlug`, `route`, `capturedAt`),
"preserved verbatim as free-form JSON", flowing as `Engine::processMessage(..., array $context)`
and stored via `historyHandler->storeMessage(..., context: $cnAiContext)`. Overloading it would
collide two unrelated meanings in one key. (Note for readers: hermiq already carries **three**
distinct things called "context" — `Context` objects, `Message.context`/CnAiContext, and the RAG
`$context` shape `{text, sources}` that `Engine` reassigns into the same variable at
`Engine.php:333`. This change adds a fourth name at its peril, which is precisely why the field
is called `attachments`.)

### Decision 2: The field lives on `Message`, not `Conversation`

An attachment is per-turn: "look at *this* file for *this* question." `Conversation` (properties:
`title`, `userId`, `agentId`, `metadata`) is the thread. Putting attachments there would make one
upload apply to every subsequent turn in the thread — the same too-durable lifecycle bug as
putting it on the Agent, just one level down. It would also make "which turn was this file
attached to?" unanswerable, which matters for auditability (every Message is an OpenRegister
object with an audit trail).

`Message` already carries per-turn provenance in exactly this spirit: `sources` records the RAG
sources used for *that* message. `attachments` is the user-supplied mirror of `sources`.

**Alternative considered — `Conversation.metadata`.** Rejected: free-form JSON, no schema, no
per-turn anchor, and the wrong lifecycle.

### Decision 3: The shape is `{path, name, description}` — a reference, not a body

`Context.files` is `{path, description}`. `Context.documents` (ADR-024) is `{name, body, format,
description}` — an *inline body*. The attachment field must pick a side, and it picks **reference**:

- `path` — file path relative to the acting user's Nextcloud folder. **Identical semantics and
  identical name** to `Context.files[].path`, so change 2 can read it with the same
  `$userFolder->nodeExists($path)` / `$userFolder->get($path)` logic that
  `ContextAssembler::resolveFiles()` already runs. This is what makes the sovereignty story hold:
  a path is resolved *as the user*, so Nextcloud's permissions are applied by construction and
  the file content never has to live in the register.
- `name` — the display filename. This is the **one addition** over the `files` precedent, and it
  earns its place: a chat upload's stored path can diverge from what the user picked, because
  Nextcloud deduplicates a colliding upload to `report (2).txt`. The UI must be able to show
  "report.txt" while `path` points at the real, possibly-renamed node. `Context.files` has no such
  problem — a user picks an existing file, so its path *is* its name.
- `description` — optional note, carried over from the `files` precedent for shape symmetry and
  for a future "why is this attached" affordance.

**Alternative considered — an inline-body shape like `documents` (`{name, body, format}`).**
Rejected, and this is the most consequential rejection in the chain. Storing the uploaded file's
bytes inline in the Message object would:
1. **Break the sovereignty story.** `docs/concepts/safe-setup.md` states the design as "your data
   never leaves" and "Hermiq has the hands... it does it *as you*, and only if you granted it".
   An inline body is a **copy** of the user's data in the register, outside the Files permission
   model, with no share/ACL/audit story of its own. The path shape keeps exactly one copy of the
   file, in Files, where Nextcloud's permission model already governs it.
2. **Create a parallel attachment store** — the exact thing the chain is trying to avoid.
3. **Put unbounded blobs in an OpenRegister object** that is read on every history fetch.

**Alternative considered — a Nextcloud file ID (`fileid`) instead of a path.** Genuinely
defensible: a fileid survives a rename/move, where a path does not. Rejected for chain
consistency: `Context.files` and `HermiqToolProvider` are both path-based, and
`ContextAssembler::resolveFiles()` is path-based. Introducing an id-based reference for
attachments alone would mean two file-reference dialects in one assembler and two read paths.
The rename-breakage is real but bounded — an attachment is per-turn and read within seconds of
being uploaded, so the rename window is near-zero, unlike a `Context.files` entry that lives for
months. **Flagged as a deferred question**, not hand-waved: if a fileid dialect is ever adopted,
it should be adopted for `Context.files` and `attachments` together, as one migration.

### Decision 4: No `mediaType`/binary affordance in the shape — the chain is text-only

The shape deliberately has **no** `mediaType`, `mimeType`, `encoding`, or `base64` field. That is
not an oversight, it is the scope call made concrete in the schema, so a future contributor
cannot quietly light up vision by populating an existing field.

Verified grounding for the call:
- There is **no** vision/`image_url`/base64 handling anywhere in `lib/Service/Llm/` or
  `lib/Service/Engine/` at HEAD.
- `composer.json` `require` is `php`, `cweagans/composer-patches`, `dragonmantank/cron-expression`,
  `theodo-group/llphant` — **no text-extraction library**. So PDF/docx are not "text-extractable"
  here either; extraction would be a new dependency and a new proposal.
- Per-provider vision encoding differs (OpenAI `image_url` parts vs. Ollama's `images[]`), and
  `ChatDriver`/`ProviderFactory` expose no seam for it.
- `docs/concepts/safe-setup.md` describes the model boundary as "text in, text out, that is all".

So: **binary and image attachments are OUT OF SCOPE for this chain**, and the schema says so by
omission. The full argument and the file-type policy live in `hermiq-chat-attachments`' design;
this change's obligation is only to not pre-commit the shape to a vision future it hasn't
designed. Adding `mediaType` later is additive and cheap; removing a misused one is not.

### Decision 5: Both version gates move together

The register re-import is gated on `info.version`; the app upgrade that triggers the import is
gated on `appinfo/info.xml` `<version>`. Verified current values: `info.version` = `0.15.0`,
`<version>` = `0.1.80`. Both bump (→ `0.16.0`, → `0.1.81`). `Message.version` also bumps
`0.1.0` → `0.1.1`, mirroring `Context.version`'s `0.1.1` after ADR-024 added `documents`.

Bumping only one is the well-known silent failure: the JSON diff looks correct, review passes,
and the field never reaches the database. Verification is therefore defined against the
**imported** schema, not the file on disk.

## Database Changes

None in the Nextcloud sense. Hermiq is a thin client and owns no database tables; there is no
`lib/Migration/` class and no `changeSchema()`. The `Message` schema is an OpenRegister object
schema, re-imported from `lib/Settings/hermiq_register.json` when the version gates move. The
property is additive with `default: []`, so no stored object is rewritten and no data is
transformed.

## Nextcloud Integration

- Controllers: none (config-only).
- Services: none (config-only). The follow-on change consumes `OCP\Files\IRootFolder`.
- Mappers/Entities: none — OpenRegister `ObjectService` owns persistence.
- Events/Hooks: none.

## Security Considerations

No new attack surface is opened by this change *at this revision*, because nothing reads or
writes the field. Two forward-looking constraints are nonetheless fixed here by the shape, and
are called out so change 2 cannot silently drop them:

- **The `path` shape is the authorization mechanism.** Because an attachment is a path resolved
  through `IRootFolder::getUserFolder($actingUserId)`, the reader can only ever see what that
  user can see. A path the user cannot read simply fails to resolve. Had the shape carried an
  inline body, the register would hold a permission-free copy of the bytes and this property
  would be lost. This is the schema-level expression of the safe-setup posture.
- **A stored `path` is untrusted user input.** It is a string in an OpenRegister object that a
  user controls. Change 2 MUST NOT treat it as trusted: traversal-style paths must resolve
  through the user folder (which scopes them) and never be concatenated onto a filesystem root.
  Its *content*, once read, is untrusted model input subject to guardrails — spelled out in
  change 2's design, where the code exists to constrain.

## File Structure

```
lib/Settings/hermiq_register.json   # Message.attachments; Message.version 0.1.0→0.1.1;
                                    # info.version 0.15.0→0.16.0
appinfo/info.xml                    # <version> 0.1.80→0.1.81
```

## Trade-offs

- **Reference over inline body** — keeps one copy of the data under Nextcloud's permission model
  and preserves sovereignty, at the cost of a dangling reference if the file is later deleted
  (change 2 handles the miss non-fatally, exactly as `resolveFiles()` already does: log and skip).
- **Path over fileid** — one file-reference dialect across the codebase, at the cost of
  rename/move fragility in a near-zero window. Deferred question, not a silent choice.
- **`Message` over `Conversation`** — correct per-turn lifecycle and auditability, at the cost of
  attachments not being trivially "sticky" across a thread. If users later want a
  pinned-for-the-thread file, the honest answer is a `Context` on the agent or a new explicit
  feature — not a lifecycle smear of this field.
- **Text-only by omission** — a smaller, honest shape now, at the cost of an additive schema
  change if vision is ever designed properly.

## Open Questions

- **Should file references move to `fileid` across the codebase?** Not for this chain (Decision
  3), but the path-based dialect is fragile under rename/move, and if it is ever revisited it
  MUST be revisited for `Context.files` and `Message.attachments` together. Carried to
  DEFERRED_QUESTIONS.

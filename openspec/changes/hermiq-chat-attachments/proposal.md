---
kind: code
depends_on: [hermiq-chat-attachments-schema]
---

# Proposal: hermiq-chat-attachments

## Summary

Let a user attach a file to a chat turn. A new upload endpoint stores the file in the acting
user's **own Nextcloud** and returns a reference; the existing chat endpoints carry that
reference in their existing JSON body; and at turn assembly the content is read back through the
**same** `IRootFolder` path `ContextAssembler::resolveFiles()` already uses — so Nextcloud's
permissions apply by construction, the existing character budget applies, and no parallel
attachment store is created. Scoped to text-decodable files; binary/image (vision) attachments
are explicitly out of scope. Depends on `hermiq-chat-attachments-schema` for `Message.attachments`.

## Motivation

Users want to upload a file in the AI chat so the agent can use it in that turn. Today this is
impossible — hermiq's chat API is JSON-only (verified: no `getUploadedFile`, multipart, or
attachment handling in either chat controller). The schema head of this chain ships the
`Message.attachments` field; without this change that field is a phantom — present, defaulted,
and read by nothing. This change is what makes the chain a feature rather than an orphaned
capability.

The user-visible gap is sharp: hermiq can already read a user's Nextcloud files
(`ContextAssembler`, `HermiqToolProvider`), but only files an admin wired onto the agent in
advance via a `Context`. "Here, read *this*, right now" — the single most ordinary thing a person
wants from a chat assistant — has no path at all.

## Affected Projects

- [ ] Project: `hermiq` — a new upload endpoint + route; `Message.attachments` persisted through
      the existing chat + stream controllers; attachment text resolved into the turn preamble via
      `IRootFolder`; guardrail coverage for that text; a Vue attach control on the Chat view.

## Scope

### In Scope

- **Upload endpoint** `POST /api/chat/attachments` — accepts one multipart file, stores it in the
  acting user's Nextcloud, returns `{path, name}`. Guarded `#[NoAdminRequired]`, CSRF-enforced.
- **Storage location**: the acting user's own folder, under a dedicated `Hermiq/Attachments/`
  directory, created on demand. Nextcloud's own non-colliding-name logic decides the final path.
- **Reference plumbing**: `ChatController::sendMessage()` (via `extractMessageRequestParams()`)
  and `ChatStreamController::stream()` (JSON body) both accept an `attachments` array and pass it
  to `Engine::processMessage()`, which persists it onto the user `Message` via
  `historyHandler->storeMessage()`.
- **Turn assembly**: attachment content is read via `IRootFolder::getUserFolder($actingUserId)`
  and rendered into the turn's preamble using the same `Source: {path}\n{content}` block
  convention and the same `MAX_FILE_BYTES` (20000) cap `ContextAssembler::resolveFiles()` uses.
- **Limits & failure handling**: a per-file byte cap, a text-decodable-only policy, and defined,
  non-fatal behavior for missing / oversized / binary / unreadable files.
- **Guardrails**: attachment text is untrusted input and MUST pass the guardrail input filter —
  including closing a **verified gap** where `$contextPreamble` bypasses `filterInput()` today.
- **Frontend**: an attach control on `src/views/Chat.vue` + `src/api/chat.js`, with `nl_NL`/`en_US`
  strings.

### Out of Scope

- **Binary and image attachments / vision.** No base64, no `image_url`, no per-provider encoding.
  Justified at length in design.md — this is a deliberate, argued exclusion, not an omission.
- **PDF/Office text extraction.** `composer.json` has no extraction library; adding one is a new
  dependency and its own proposal.
- **Multipart on the chat/stream endpoints themselves.** Both stay JSON-only; upload is a separate
  step. (`ChatStreamController::readRequestBody()` reads `php://input`, which PHP does not
  populate for `multipart/form-data` — see design.md Decision 1.)
- **Attachments on scheduled/webhook/flow agent runs.** This is the interactive chat surface only.
- **Picking an existing Nextcloud file** as an attachment (no Files picker). The shape supports it
  — it is just a `path` — but the UI and its authorization review are deferred.
- **Retention/cleanup** of uploaded attachment files. They are the user's files in the user's
  Files, managed like any other file. Deliberately deferred; see DEFERRED_QUESTIONS.

## Approach

Two steps, both reusing existing seams:

1. **Upload → reference.** A dedicated endpoint takes the file and writes it into the user's
   Nextcloud via `IRootFolder`, returning `{path, name}`. The chat endpoints never see a file —
   they see a reference in the JSON body they already parse. This keeps the SSE stream endpoint
   JSON-only (a hard technical constraint, not a preference) and makes upload and send
   independently retryable.
2. **Reference → text.** At turn assembly the reference is resolved through the same
   `getUserFolder($actingUserId)` → `nodeExists()` → `get()` → `getContent()` chain
   `ContextAssembler::resolveFiles()` already runs, capped at the same 20000 bytes, rendered as
   the same `Source:` block, and folded into the same preamble under the same budget.

The sovereignty story (`docs/concepts/safe-setup.md`) holds because nothing about it changes: the
file lives in the user's Nextcloud, is read *as that user* (so their permissions are the
authorization), and the model container still receives only text.

## New Dependencies

None. `OCP\Files\IRootFolder` is already injected in `ContextAssembler` and `HermiqToolProvider`.

## Impact

- `lib/Controller/ChatAttachmentController.php` — new.
- `appinfo/routes.php` — one new route.
- `lib/Controller/ChatController.php` — `extractMessageRequestParams()` gains `attachments`.
- `lib/Controller/ChatStreamController.php` — `stream()` reads `attachments` from the JSON body.
- `lib/Service/Engine/Engine.php` — `processMessage()` gains an `$attachments` parameter;
  persists it on the user Message; folds resolved text into the preamble; guardrail coverage.
- `lib/Service/Engine/ContextAssembler.php` — a per-turn attachment resolution entry point
  reusing the existing file-read logic.
- `src/views/Chat.vue`, `src/api/chat.js`, `l10n/` — the attach control and its strings.
- Existing chat callers — unaffected; `attachments` is optional everywhere.

## Cross-Project Dependencies

None at runtime. Depends on `hermiq-chat-attachments-schema` (this repo) landing and re-importing
first — `Message.attachments` must exist on the **imported** schema or the reference silently
fails to persist.

## Risks

### Risk 1: Attachment text reaches the model without passing the guardrail input filter

**Severity:** High — **Mitigation:** An uploaded file is attacker-controllable content aimed
straight at the prompt — the textbook prompt-injection carrier, and exactly what ADR-024 Rule 3
says must be filtered. Verified at HEAD: `Engine.php` applies
`guardrailPolicyService->filterInput()` to `$userMessage` **only**; `$contextPreamble`
(assembled at `Engine.php:278`) is never passed through it. So ADR-024 Rule 3's claim that input
filters "apply to the assembled preamble exactly as they do to any other model input" is
**aspirational, not implemented**. Routing attachment text through the preamble without fixing
this would ship a guardrail bypass. This change MUST filter attachment text explicitly and MUST
NOT rely on the preamble being covered. See design.md Security.

### Risk 2: An upload endpoint is a write primitive into the user's Files

**Severity:** Medium — **Mitigation:** The endpoint writes to the acting user's own folder under
a fixed `Hermiq/Attachments/` prefix; the filename is sanitised to a basename and never
interpreted as a path (no traversal into arbitrary locations), and Nextcloud's own
non-colliding-name logic prevents overwriting an existing file. `#[NoAdminRequired]` with the
acting user resolved from the session — never from a request parameter — so a user can only ever
write into their own storage. A size cap is enforced before the write.

### Risk 3: Attachment text silently crowds out the rest of the preamble

**Severity:** Medium — **Mitigation:** The existing `charBudget` contract explicitly does NOT
truncate — it only flags `needsConsolidation`. A large attachment therefore cannot be silently
dropped, but it also cannot be silently trimmed; it will simply make the preamble long. The
20000-byte per-file cap bounds a single attachment, and a cap on attachments per turn bounds the
aggregate. Design.md states the budget interaction explicitly rather than inheriting it by
accident.

### Risk 4: A binary file is read as text and fed to the model as mojibake

**Severity:** Low — **Mitigation:** `resolveFiles()` today does a raw `getContent()` with no
encoding check — pointing it at a PNG yields binary noise in the prompt. This change adds an
explicit text-decodable check and rejects binary at upload time with a clear message, rather than
letting it degrade silently.

## Rollback Strategy

Revert the commit. The route disappears, both controllers stop reading `attachments`, and the
Engine stops resolving it. Stored `Message.attachments` values become inert (unread) data — the
schema field survives independently, so no Message object breaks. Files already uploaded remain
in the user's `Hermiq/Attachments/` folder as ordinary files they own and can delete; nothing
orphans. If the whole chain is abandoned, revert `hermiq-chat-attachments-schema` too, so the
field does not linger as a phantom.

## Open Questions

- **Should the guardrail input filter cover the whole assembled preamble, not just attachments?**
  This change fixes the gap for the text it introduces. Extending `filterInput()` to
  `$contextPreamble` wholesale (making ADR-024 Rule 3 true for `Context.files`/`documents` too) is
  a strictly larger change with its own performance and false-positive profile. Carried to
  DEFERRED_QUESTIONS; not silently assumed.

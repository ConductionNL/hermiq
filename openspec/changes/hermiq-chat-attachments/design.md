# Design: hermiq-chat-attachments

## Architecture Overview

Two hops, both landing on seams that already exist.

```
 ┌── HOP 1: upload ──────────────────────────────────────────────┐
 │  Chat.vue ──multipart──► POST /api/chat/attachments           │
 │                            ChatAttachmentController           │
 │                              IRequest::getUploadedFile()      │
 │                              size + text-decodable check      │
 │                              IRootFolder::getUserFolder(uid)  │
 │                                └─► Hermiq/Attachments/<name>  │  ◄── the user's OWN Files
 │                            ◄──── { path, name }               │
 └───────────────────────────────────────────────────────────────┘
 ┌── HOP 2: send + assemble ─────────────────────────────────────┐
 │  Chat.vue ──JSON { message, attachments:[{path,name}] }──►    │
 │      ChatController::sendMessage()  /  ChatStreamController::stream()
 │                            └─► Engine::processMessage(..., $attachments)
 │                                  ├─ resolve text  ◄── SAME IRootFolder read
 │                                  │                    as ContextAssembler::resolveFiles()
 │                                  ├─ guardrail filterInput()  ◄── explicit; see Security
 │                                  ├─ storeMessage(attachments) → Message.attachments
 │                                  └─ fold into the ONE budgeted preamble
 └───────────────────────────────────────────────────────────────┘
```

Nothing new is invented: the read path, the block format, the byte cap, and the budget are all
the ones `ContextAssembler` already runs. The only genuinely new primitive is a **file write**,
which hermiq has never done before (verified: no `newFile`/`putContent`/`newFolder` anywhere in
`lib/`) — hence the disproportionate security attention below.

## Goals / Non-Goals

**Goals**
- A user can attach a text file to a turn and the agent uses it in that turn.
- The file lands in the user's Nextcloud and is read back **as that user**.
- Attachment text is budgeted and guardrailed like any other model input.

**Non-Goals**
- Vision / binary / image attachments (Decision 4).
- PDF/Office extraction (no library; new dependency; separate proposal).
- Attachments on non-interactive runs (schedule/webhook/flow).
- A Files picker for existing files.

## Decisions

### Decision 1: Upload is a SEPARATE endpoint; the chat endpoints stay JSON-only

The brief's steer ("the controller accepting the upload/reference") leaves open whether the chat
endpoint itself takes multipart. It must not — and this is a hard technical constraint, not taste:

`ChatStreamController::readRequestBody()` is `file_get_contents('php://input')`, fed to
`json_decode`. **PHP does not populate `php://input` for `multipart/form-data` requests.** So a
multipart POST to `/api/chat/stream` yields an empty body, `$rawBody === ''` → `'[]'` → a
`missing_message` error. Supporting multipart there would mean abandoning `php://input` and
restructuring the SSE entry point — while also streaming an SSE response back from a multipart
request, which is a deeply awkward shape.

`ChatController::sendMessage()` uses `IRequest::getParam()` and *would* tolerate multipart. But
splitting the behavior — multipart on `send`, JSON on `stream` — would give the two chat
endpoints divergent contracts for the same feature, and the frontend uses **both** (`axios` for
send, `fetch` for the SSE stream, per `src/api/chat.js`).

So: `POST /api/chat/attachments` takes the file and returns `{path, name}`; both chat endpoints
gain an optional `attachments` array in the JSON body they already parse. Upload and send become
independently retryable, and a failed send doesn't lose the upload.

**Alternative considered — base64 the file into the existing JSON body.** Rejected: it inflates
the body ~33%, puts bytes through `json_decode` into memory, and re-creates the "content in the
message" shape the schema change explicitly rejected.

### Decision 2: Uploads land in the acting user's own Files, under `Hermiq/Attachments/`

Where: `IRootFolder::getUserFolder($actingUserId)` → `Hermiq/Attachments/` (created on demand via
`newFolder()`), file written with `newFile($path, $content)`, final name chosen by
`Folder::getNonExistingName($name)` so an upload never overwrites an existing file. All four are
real OCP `Folder` methods (verified against `lib/public/Files/Folder.php`).

Why the user's own folder and not an app-owned store: this is the whole sovereignty argument.
`docs/concepts/safe-setup.md` states "your data never leaves", "Hermiq has the hands... it does it
*as you*, and only if you granted it", and draws the model as a brain in a jar receiving only
text. If hermiq kept attachments in an app-private location, then (a) the file would sit outside
the Files permission model that makes "as you" meaningful, (b) hermiq would own user data it has
no lifecycle story for, and (c) there would be two answers to "where are my files?". Writing into
the user's own tree means the file is theirs on arrival: they can see it, move it, share it, or
delete it with the tools they already have, and the read-back is permission-checked for free.

Why a visible folder rather than a hidden one: a hidden store would be a parallel store wearing a
disguise. A user must be able to find and delete what they uploaded.

**Alternative considered — a temp file / no persistence (read once, discard).** Genuinely
attractive for privacy: nothing accumulates. Rejected because the Message would then reference a
path that no longer exists, so re-reading a conversation, replaying a run, or auditing "what did
the model actually see?" all break — and `Message.attachments` would be a reference to nothing.
Persistence is what makes the reference shape honest.

### Decision 3: An attachment is per-turn Context-kind material — ADR-024 gains no fourth concept

ADR-024 Rule 1 fixes three concepts (Skill = capability, Context = situation reference, Memory =
learned state), all converging in ONE budgeted preamble via `ContextAssembler`, with "no second
assembly path". This change must not quietly become a fourth.

It doesn't. An attachment is **the same kind of thing as a `Context.files` entry** — a pointer at
a Nextcloud file, read as the acting user, rendered as a `Source:` block into the same preamble
under the same budget — differing only in **lifecycle**: supplied by a user at send time, scoped
to one Message, rather than curated onto an Agent and applied to every run. It adds no new source
kind, no new trust posture, and critically **no second assembly path**: the resolved text is
folded into the same preamble string, in the same format, subject to the same budget.

So ADR-024's concept table stands unamended. The honest one-line placement: *an attachment is
Context-kind material with a Message lifecycle* — a lifecycle variant of the `files` source kind.
This is why the resolution logic lives with `ContextAssembler` (reusing its read path) rather than
in a new `AttachmentAssembler` that would be a second seam by another name.

The one place ADR-024 is **not** merely inherited is Rule 3 (untrusted input / guardrails) —
because Rule 3 turns out not to be true in code today. See Security.

**Alternative considered — mint a throwaway `Context` object per upload and add it to
`contextRefs`.** Rejected: it would make an ephemeral, user-supplied thing masquerade as curated
agent definition, pollute the register with junk objects, and — worst — leak one turn's file into
every subsequent run of that agent.

### Decision 4: Binary/image attachments and vision are OUT OF SCOPE — the argued case

This is the brief's explicit "don't hand-wave" item, so the reasoning is laid out rather than
asserted.

**What "supporting images" would actually require, verified at HEAD:**
1. **A per-provider encoding fork that has no seam.** `lib/Service/Llm/ChatDriver.php` wraps
   LLPhant (`OpenAIChat`/`OllamaChat`) built by `ProviderFactory.php`. There is **no**
   vision/`image_url`/base64 handling anywhere in `lib/Service/Llm/` or `lib/Service/Engine/`
   (verified by grep). OpenAI expects structured content parts with an `image_url`; Ollama expects
   an `images[]` array of base64 strings on the message. These are different message *shapes*, not
   different strings — so hermiq's uniform "assemble one text preamble, hand it to the driver"
   contract would have to become a multimodal message-parts contract, through an abstraction
   (LLPhant) that is currently used only for text. That is a driver-layer redesign, not a feature
   flag.
2. **A budget model that doesn't exist.** The entire budget contract is `charBudget` —
   `mb_strlen($body) > $budget`. Images have no character count. Their cost is provider-specific
   tile arithmetic. Folding an image into a `charBudget` preamble is a category error: there is no
   honest number to add.
3. **A capability-detection story that doesn't exist.** Not every configured model is a vision
   model. Sending image parts to a text-only Ollama model fails at request time. Hermiq has no
   model-capability metadata to gate on, so the failure would be a runtime provider error
   surfaced to a user who was merely told "attach a file".
4. **A sovereignty statement that would need rewriting.** `safe-setup.md` describes the model
   boundary as "text in, text out, that is all". Multimodal input is a genuine change to the
   documented contract — reviewable on its own merits, not smuggled in as a file-upload feature.

**And note what is NOT the reason:** the constraint is not "images are hard". It is that images
touch four seams (driver contract, budget, capability gating, documented boundary) that this
change touches none of. A change that had to redesign the driver layer to deliver an upload box
would be the wrong change.

**PDF/Office are out for a separate, equally concrete reason:** `composer.json` `require` is
`php`, `cweagans/composer-patches`, `dragonmantank/cron-expression`, `theodo-group/llphant` —
**no text-extraction library**. `resolveFiles()` does a raw `getContent()`. A PDF read this way is
binary noise. "Text-extractable" therefore means, at this revision, **text-decodable**: bytes that
are already valid UTF-8. Extraction is a new dependency and its own proposal.

**So the scope is: files whose content is valid UTF-8 text.** Everything else is rejected at
upload with an explicit message — never silently degraded into mojibake in the prompt.

### Decision 5: Limits, and what happens at each failure

`ContextAssembler::MAX_FILE_BYTES = 20000` is the precedent and is reused as the per-attachment
read cap. Precedent-consistent behavior at each edge:

| Condition | Where | Behavior | Precedent |
|---|---|---|---|
| File > size cap | Upload | **Reject** with a clear error; nothing written | New — upload is a write, and writing a file we will refuse to read fully is dishonest |
| Content not valid UTF-8 | Upload | **Reject** ("only text files are supported") | New — Decision 4 |
| Too many attachments in one turn | Send | **Reject** the turn with a clear error | New — bounds the aggregate (Risk 3) |
| Path not found at assembly | Assembly | **Skip + log**, turn proceeds | Exactly `resolveFiles()` (`nodeExists()===false → log info, continue`) |
| Path is a folder | Assembly | **Skip + log**, turn proceeds | Exactly `resolveFiles()` (`!($node instanceof File)`) |
| Read throws | Assembly | **Skip + log**, turn proceeds | Exactly `resolveFiles()` (`catch Throwable → warning, continue`) |
| Content > 20000 bytes at assembly | Assembly | **Truncate to cap + log** | Exactly `resolveFiles()` |

The asymmetry is deliberate and worth stating: **upload rejects loudly, assembly degrades
quietly.** At upload the user is present and can fix it, and a rejected upload costs nothing. At
assembly the turn is in flight and one bad reference must never blank the preamble — the
established "one broken file must not blank the preamble" posture. A file can pass upload and
still be gone at assembly (the user deleted it in Files between the two hops); that is the
skip-and-log case, not an error.

The 20000-byte cap can still bite twice for one file: rejected at upload if over, truncated at
assembly if it somehow grew. Both are logged.

### Decision 6: Budget interaction — attachments join the budget, and the budget does not truncate

This must be stated precisely, because the existing contract is counter-intuitive.
`ContextAssembler::assemble()` computes `needsConsolidation = mb_strlen($body) > $budget` and
**never truncates to fit** — exceeding the budget only flags (and persists) a nudge. The class
docblock is explicit: "the assembled text is NEVER truncated to fit the budget".

Attachment text is folded into the **same** preamble the agent's Contexts produce, so:
- Attachment text **counts toward** the same budget accounting — it is not budget-exempt.
- Exceeding the budget **does not drop** the attachment. The turn proceeds with a long preamble.
  This is the existing contract, and quietly truncating attachments would *diverge* from it —
  worse, it would silently hide the user's own file from the model after they explicitly attached
  it, which is the least acceptable failure mode in this whole feature.
- The **real** bounds on attachment size are therefore the 20000-byte per-file cap and the
  per-turn count cap (Decision 5) — not `charBudget`.

The `charBudget`/`needsConsolidation` flag is stored **on a Context object**. A per-turn
attachment has no Context object, so there is nothing to flag and nothing to persist. Attachment
text is added to the preamble the Context assembly produced; where the agent has Contexts, the
combined length is what the existing nudge reflects. Where the agent has none (`assembleForAgent()`
returns `''`), attachments still work and are simply unbudgeted-but-capped. That is honest and is
the status quo for an agent with no Context — this change does not invent a budget where none
exists.

## API Design

### `POST /api/chat/attachments`
**Auth:** Nextcloud session; `#[NoAdminRequired]`. **CSRF enforced** (no `#[NoCSRFRequired]`) —
see Security.

**Request:** `multipart/form-data`, one file field `file`.

**Response (200):**
```json
{ "path": "Hermiq/Attachments/report.txt", "name": "report.txt" }
```

**Errors:**
| Code | Condition |
|------|-----------|
| 400  | No file in the request; file exceeds the size cap; content is not valid UTF-8 text |
| 401  | No authenticated user |
| 500  | The write failed (quota, storage error) |

### Existing endpoints — additive only
`POST /api/chat/send` and `POST /api/chat/stream` accept an optional `attachments` array of
`{path, name, description?}` in their existing JSON body. Omitted → `[]` → behavior identical to
today.

## Nextcloud Integration

- Controllers: `ChatAttachmentController` (new); `ChatController`, `ChatStreamController` (modified).
- Services: `Engine` (modified), `ContextAssembler` (modified — per-turn attachment resolution
  reusing its file-read logic), `GuardrailPolicyService` (consumed).
- OCP interfaces: `IRequest::getUploadedFile()` (verified real), `IRootFolder::getUserFolder()`,
  `Folder::newFolder()/newFile()/getNonExistingName()/nodeExists()/get()`, `File::getContent()`,
  `IUserSession`, `IL10N`.
- Mappers/Entities: none — OpenRegister `ObjectService` owns Message persistence.
- Events/Hooks: none.

## Security Considerations

An uploaded file is **untrusted input on two axes at once**: untrusted *bytes* being written into
storage, and untrusted *text* being fed to a model. Both are addressed.

**1. Attachment text MUST be guardrail-filtered — and the preamble is NOT covered today.**
This is the highest-severity item in the change. ADR-024 Rule 3 asserts that guardrail input
filters "apply to the assembled preamble exactly as they do to any other model input". **Verified
at HEAD, that is false.** `Engine.php` calls:

```php
$inputFilter = $this->guardrailPolicyService?->filterInput(
    policy: $guardrailPolicy,
    text: $userMessage        // ← $userMessage ONLY
) ?? ['text' => $userMessage, 'blocked' => false, 'reason' => null];
```

`$contextPreamble` — assembled at `Engine.php:278`, before this block — is **never** passed to
`filterInput()`. So `Context.files` and `Context.documents` content reaches the model unfiltered
today.

The consequence for this design is decisive: **attachment text must be filtered explicitly, and
must NOT be routed through the preamble on the assumption that the preamble is covered.** An
uploaded file is the single most attacker-reachable input in the feature (a user can be socially
engineered into attaching a hostile document; a shared file can be authored by someone else), so
inheriting a silent bypass would be shipping a prompt-injection hole. A `block` match MUST behave
like a blocked user message: throw `GuardrailBlockedException`, no LLM call, no Message stored. A
`redact` match MUST mask the text so both the model input and any persisted copy are the masked
text.

Whether to *also* extend `filterInput()` to the whole preamble (making Rule 3 true for
Contexts generally) is a strictly larger change with its own performance and false-positive
profile → DEFERRED_QUESTIONS. This change fixes the gap for the text it introduces and does not
paper over the rest.

**2. Path handling.** The uploaded filename is attacker-controlled. It MUST be reduced to a
basename (never interpreted as a path) so `../../` cannot escape `Hermiq/Attachments/`, passed
through `Folder::verifyPath()`, and de-duplicated via `getNonExistingName()`. At read time the
stored `path` is resolved **only** through `getUserFolder($actingUserId)`, which scopes it to that
user's storage — the same containment `resolveFiles()` relies on.

**3. The acting user is the authorization.** The uid comes from `IUserSession`, never from a
request parameter, on both hops. A user can therefore only write into their own storage and only
read back what they can read. This — not an ACL of our own — is what makes the safe-setup claim
("Hermiq reads it *as you*, with your permissions") true for attachments. A user attaching a
`path` they cannot read gets a skip-and-log, not someone else's data.

**4. IDOR on the reference.** `attachments[].path` on a send is user-supplied and NOT validated to
be something we uploaded — by design (it is just a path). It is safe precisely because it is
resolved through the sender's own user folder: the worst a user can do is read their own file,
which they may already do. It MUST NOT be resolved through any other user's folder, and the
conversation-ownership guard (`verifyConversationAccess`) continues to gate the turn.

**5. CSRF.** The existing chat methods carry `@NoCSRFRequired`. The upload endpoint MUST NOT copy
that: it is a state-changing write into the user's storage, reachable from a browser form, and it
has no streaming constraint to justify an exemption. `axios` from `@nextcloud/axios` attaches the
token automatically (per `src/api/chat.js`).

**6. Quota & DoS.** The write is subject to the user's own Nextcloud quota (enforced by the Files
layer — hermiq does not bypass it). The size cap is checked before writing, and the per-turn count
cap bounds a single turn.

## NL Design System

The attach control on `src/views/Chat.vue` uses standard Nextcloud components (`NcButton` with an
icon, and the existing chat input row), no hardcoded colors (CSS variables only), a visible label
/ `aria-label` on the control, and keyboard-reachable attach + remove affordances (WCAG AA). New
strings ship in `nl_NL` and `en_US` (ADR-005/ADR-007).

## File Structure

```
lib/
  Controller/
    ChatAttachmentController.php   # NEW — upload endpoint
    ChatController.php             # MOD — extractMessageRequestParams(): + attachments
    ChatStreamController.php       # MOD — stream(): read attachments from the JSON body
  Service/
    Engine/
      Engine.php                   # MOD — processMessage($attachments); guardrail; persist; preamble
      ContextAssembler.php         # MOD — per-turn attachment resolution reusing the file read
appinfo/
  routes.php                       # MOD — chatAttachment#upload
src/
  views/Chat.vue                   # MOD — attach control
  api/chat.js                      # MOD — uploadAttachment(); send/stream carry attachments
l10n/                              # MOD — nl_NL + en_US strings
```

## Trade-offs

- **Separate upload endpoint** — both chat endpoints keep one JSON contract and the SSE path stays
  intact, at the cost of two round-trips and a file that can outlive an abandoned send (it is the
  user's file in their Files; acceptable).
- **Write into the user's Files** — sovereignty holds and lifecycle is the user's, at the cost of
  hermiq gaining its first write primitive and putting an app-named folder in the user's tree.
- **Text-only** — a small, honest, shippable change, at the cost of not answering "summarise this
  PDF" (the most likely first user request — flagged in DEFERRED_QUESTIONS as the top follow-on).
- **Attachments don't truncate to budget** — consistent with the existing never-truncate contract
  and never silently hides the user's file, at the cost of a long preamble being possible; bounded
  by the byte cap and the count cap instead.
- **Filtering only attachment text, not the whole preamble** — fixes the hole this change would
  otherwise open without silently expanding scope, at the cost of leaving ADR-024 Rule 3 still
  untrue for `Context.files`/`documents`. Explicitly deferred, not hidden.

## Open Questions

- Extending the guardrail input filter to the whole assembled preamble (ADR-024 Rule 3 vs. code).
- PDF/Office text extraction (new dependency).
- Attachment retention/cleanup in `Hermiq/Attachments/`.
- Attaching an existing Nextcloud file via a Files picker.

All carried to DEFERRED_QUESTIONS.

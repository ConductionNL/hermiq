# chat-attachments Specification

**Status**: planned
**Scope**: hermiq
**OpenSpec changes**:
- hermiq-chat-attachments-schema (dependency — schema)
- hermiq-chat-attachments (this change — controller + storage + turn assembly)

## Purpose

Deliver the behaviour behind the `Message.attachments` field: a user attaches a file to a chat
turn, the file lands in their own Nextcloud, the Message carries a reference, and the content is
read back at turn assembly through the same permission-respecting `IRootFolder` path
`ContextAssembler::resolveFiles()` already uses — under the same byte cap, in the same
`Source:`-block format, folded into the same single budgeted preamble (ADR-024 Rule 1: no second
assembly path). The file stays in Nextcloud and the model container still receives only text, so
the sovereignty posture in `docs/concepts/safe-setup.md` is preserved. Attachment content is
untrusted input and is guardrail-filtered (ADR-024 Rule 3). Scoped to text-decodable files;
binary/image/vision attachments are out of scope.

## ADDED Requirements

### Requirement: An upload endpoint stores an attachment in the acting user's Nextcloud

Hermiq MUST expose `POST /api/chat/attachments` accepting one `multipart/form-data` file and
storing it in the **acting user's own** Nextcloud folder, returning the reference the chat
endpoints consume.

The endpoint MUST resolve the acting user from `IUserSession` and never from a request parameter.
It MUST read the file via `IRequest::getUploadedFile()`, resolve the target via
`IRootFolder::getUserFolder($actingUserId)`, create `Hermiq/Attachments/` on demand, and write the
file there. The stored name MUST be de-duplicated via `Folder::getNonExistingName()` so an upload
never overwrites an existing file. On success it MUST return `{path, name}` where `path` is
relative to the user's folder. The endpoint MUST be `#[NoAdminRequired]` and MUST enforce CSRF
(it MUST NOT declare `#[NoCSRFRequired]`).

#### Scenario: A text file is uploaded

- GIVEN an authenticated user
- WHEN they POST a `report.txt` containing UTF-8 text to `/api/chat/attachments`
- THEN the file exists in that user's Nextcloud at `Hermiq/Attachments/report.txt`
- AND the response is 200 with `{ path: "Hermiq/Attachments/report.txt", name: "report.txt" }`
- AND the file is owned by that user and visible to them in Files

#### Scenario: The attachments folder is created on demand

- GIVEN an authenticated user whose Nextcloud has no `Hermiq/Attachments/` folder
- WHEN they upload an attachment
- THEN the folder is created
- AND the file is written inside it

#### Scenario: An upload never overwrites an existing file

- GIVEN the user already has `Hermiq/Attachments/report.txt`
- WHEN they upload another file named `report.txt`
- THEN the existing file is NOT overwritten
- AND the new file is stored under a non-colliding name chosen by `getNonExistingName()`
- AND the returned `path` is the non-colliding path while the returned `name` remains `report.txt`

#### Scenario: An unauthenticated upload is refused

- GIVEN no authenticated user
- WHEN a file is POSTed to `/api/chat/attachments`
- THEN the response is 401
- AND no file is written

### Requirement: Uploads are restricted to text-decodable files within a size cap

The upload endpoint MUST reject any file whose content is not valid UTF-8 text, and any file
exceeding the per-file size cap of 20000 bytes (`ContextAssembler::MAX_FILE_BYTES`, the
established precedent). A rejected upload MUST return 400 with an explanatory message and MUST
NOT write anything. Binary content MUST NOT be stored, base64-encoded, or passed to the model in
any form — there is no vision/binary support in this capability.

#### Scenario: A binary file is rejected

- GIVEN an authenticated user
- WHEN they upload a PNG image
- THEN the response is 400 with a message stating only text files are supported
- AND no file is written to their Nextcloud
- AND no binary content is ever placed into a model prompt

#### Scenario: An oversized file is rejected

- GIVEN an authenticated user
- WHEN they upload a text file larger than 20000 bytes
- THEN the response is 400 with a message stating the size limit
- AND no file is written to their Nextcloud

#### Scenario: A PDF is rejected as non-text

- GIVEN an authenticated user
- WHEN they upload a PDF
- THEN the response is 400 with a message stating only text files are supported
- AND no text extraction is attempted (no extraction library is a dependency of this app)

### Requirement: Both chat endpoints accept an attachment reference in their JSON body

`POST /api/chat/send` and `POST /api/chat/stream` MUST each accept an optional `attachments`
array of `{path, name, description}` objects in their existing JSON body, and MUST pass it to
`Engine::processMessage()`. Neither endpoint SHALL accept `multipart/form-data`; both MUST remain
JSON-only, because `ChatStreamController::readRequestBody()` reads `php://input`, which PHP does
not populate for multipart requests. An omitted or empty `attachments` value MUST produce
behaviour identical to the current implementation.

The turn MUST be rejected with a clear error when it carries more attachments than the per-turn
cap.

#### Scenario: sendMessage carries an attachment reference

- GIVEN an authenticated user with an uploaded attachment at `Hermiq/Attachments/report.txt`
- WHEN they POST to `/api/chat/send` with `message` and `attachments: [{ path: "Hermiq/Attachments/report.txt", name: "report.txt" }]`
- THEN the reference is passed to `Engine::processMessage()`
- AND the turn completes normally

#### Scenario: The SSE stream endpoint carries an attachment reference

- GIVEN an authenticated user with an uploaded attachment
- WHEN they POST a JSON body to `/api/chat/stream` including `attachments`
- THEN the reference is passed to `Engine::processMessage()`
- AND the SSE response streams normally

#### Scenario: Omitting attachments changes nothing

- GIVEN an authenticated user
- WHEN they send a chat message with no `attachments` key
- THEN the turn behaves exactly as before this change
- AND the persisted Message carries the empty default for `attachments`

#### Scenario: Too many attachments in one turn is refused

- GIVEN an authenticated user
- WHEN they send a turn carrying more attachments than the per-turn cap
- THEN the turn is rejected with a clear error
- AND no LLM call is made

### Requirement: The user Message persists its attachment references

`Engine::processMessage()` MUST persist the turn's `attachments` onto the stored user `Message`
via the existing `storeMessage()` history path, so a conversation read back after the fact shows
which files were attached to which turn.

#### Scenario: The attachment reference is stored on the Message

- GIVEN a turn carrying one attachment reference
- WHEN the user Message is persisted
- THEN its `attachments` value contains that `{path, name}` entry
- AND reading the conversation history returns the reference

### Requirement: Attachment content is resolved into the turn preamble via the acting user's folder

At turn assembly, each attachment reference MUST be resolved by reading the file through
`IRootFolder::getUserFolder($actingUserId)` — the same permission-respecting path
`ContextAssembler::resolveFiles()` uses — and rendered into the SAME single budgeted preamble
using the SAME `Source: {path}\n{content}` block convention, capped at the same
`MAX_FILE_BYTES` (20000). No second assembly path SHALL be introduced (ADR-024 Rule 1). The file
content SHALL NOT be copied into the register, and the model container SHALL receive text only.

Because resolution goes through the acting user's own folder, Nextcloud's permissions are the
authorization: a user can only ever cause hermiq to read a file they themselves can read.

#### Scenario: Attachment text reaches the model

- GIVEN a turn carrying an attachment at `Hermiq/Attachments/report.txt` whose content is `Revenue was 12M`
- WHEN the turn is assembled
- THEN the preamble contains a `Source: Hermiq/Attachments/report.txt` block containing `Revenue was 12M`
- AND that block sits in the same preamble as any Context the agent references
- AND the file content is not written into any OpenRegister object

#### Scenario: Content over the byte cap is truncated at assembly

- GIVEN a turn carrying an attachment whose content exceeds 20000 bytes
- WHEN the turn is assembled
- THEN the content is truncated to 20000 bytes
- AND the truncation is logged
- AND the turn proceeds

#### Scenario: A user cannot read another user's file via a path

- GIVEN user A sends a turn whose attachment `path` names a file only user B can read
- WHEN the turn is assembled
- THEN resolution goes through user A's own folder
- AND the path does not resolve for user A
- AND no content from user B's file reaches the model

### Requirement: An unresolvable attachment degrades without failing the turn

At assembly, an attachment whose path is missing, resolves to a folder, or fails to read MUST be
skipped and logged, and the turn MUST proceed — the established "one broken reference must not
blank the preamble" posture of `ContextAssembler::resolveFiles()`. This assembly-time tolerance
MUST NOT be confused with upload-time validation, which rejects loudly.

#### Scenario: A file deleted between upload and send

- GIVEN a turn carrying an attachment reference whose file the user has since deleted in Files
- WHEN the turn is assembled
- THEN the missing file is skipped and logged
- AND the turn completes with the rest of the preamble intact
- AND no error is surfaced that blocks the turn

#### Scenario: A path that resolves to a folder

- GIVEN a turn carrying an attachment whose `path` names a folder
- WHEN the turn is assembled
- THEN the entry is skipped and logged
- AND the turn completes

### Requirement: Attachment content is untrusted input and is guardrail-filtered

Attachment text MUST be passed through the guardrail input filter
(`GuardrailPolicyService::filterInput()`) before it reaches the LLM, exactly as a user message is
(ADR-024 Rule 3). It MUST NOT be assumed to be covered by any existing preamble filtering: at
HEAD, `Engine` applies `filterInput()` to `$userMessage` only, and `$contextPreamble` is never
passed through it — so relying on that would ship a prompt-injection bypass on the most
attacker-reachable input in the feature.

A `block` match MUST prevent the LLM call and MUST NOT create an assistant Message, mirroring the
blocked-user-message contract. A `redact` match MUST mask the text so that both the text sent to
the model and any persisted copy are the masked text, never the original.

#### Scenario: A hostile attachment is blocked

- GIVEN an organisation whose GuardrailPolicy blocks prompt-injection patterns
- AND a turn carrying an attachment whose content matches a block rule
- WHEN the turn is processed
- THEN a GuardrailBlockedException is raised
- AND no LLM call is made
- AND no assistant Message is created

#### Scenario: A redact match masks attachment text

- GIVEN an organisation whose GuardrailPolicy redacts PII
- AND a turn carrying an attachment whose content matches a redact rule
- WHEN the turn is processed
- THEN the text placed in the preamble is the masked text
- AND the original unmasked attachment text is never sent to the model

#### Scenario: An open policy is a no-op

- GIVEN an organisation with no GuardrailPolicy
- WHEN a turn carrying an attachment is processed
- THEN the attachment text passes through unchanged
- AND no guardrail trace step is recorded

### Requirement: Attachment text counts toward the preamble budget and is never silently dropped

Resolved attachment text MUST be included in the same preamble the existing `charBudget`
accounting measures, and MUST NOT be exempt from it. Consistent with the established contract
("the assembled text is NEVER truncated to fit the budget"), exceeding the budget MUST NOT drop
or trim the attachment — the turn proceeds. Attachment size is bounded by the per-file byte cap
and the per-turn count cap, not by `charBudget`.

#### Scenario: A large attachment does not silently vanish

- GIVEN an agent whose Context `charBudget` is already nearly consumed
- AND a turn carrying an attachment
- WHEN the turn is assembled
- THEN the attachment text is still present in the preamble
- AND it is not truncated to fit the budget
- AND the user's explicitly attached file is never silently hidden from the model

#### Scenario: An agent with no Context still supports attachments

- GIVEN an agent with no `contextRefs` (so Context assembly returns an empty preamble)
- WHEN a turn carrying an attachment is assembled
- THEN the attachment text is present in the preamble
- AND the turn completes normally

### Requirement: The chat UI lets a user attach a file to a turn

`src/views/Chat.vue` MUST provide a keyboard-reachable attach control that uploads via the
endpoint and includes the returned reference in the send, and MUST show the attached file's
`name` with a way to remove it before sending. Standard Nextcloud components and CSS variables
MUST be used (no hardcoded colours). All new user-facing strings MUST ship in `nl_NL` and
`en_US`.

#### Scenario: Attaching and sending through the UI

- GIVEN a user on the Chat view
- WHEN they attach `report.txt` and send a message
- THEN the file is uploaded before the send
- AND the attached file name is shown in the composer
- AND the assistant's answer reflects the file's content

#### Scenario: A rejected upload is surfaced

- GIVEN a user on the Chat view
- WHEN they attach a binary file
- THEN a clear error is shown
- AND no attachment reference is added to the pending message

## Non-Functional Requirements

- **Performance:** An attachment adds at most one folder resolution and one bounded read (≤20000 bytes) per attachment per turn — the same cost profile as an existing `Context.files` entry. No additional LLM call and no OpenRegister query is introduced.
- **Accessibility:** The attach and remove controls MUST be keyboard-reachable and carry accessible labels (WCAG 2.1 AA).
- **Internationalization:** Dutch and English MUST be supported (ADR-005/ADR-007) for every new user-facing string, including upload rejection messages.

## Acceptance Criteria

- `POST /api/chat/attachments` stores a text file in the acting user's `Hermiq/Attachments/` and returns `{path, name}`.
- Binary, non-UTF-8, and oversized uploads are rejected with 400 and nothing is written.
- Both chat endpoints accept an optional `attachments` array in their JSON body and remain JSON-only.
- The user Message persists its `attachments` references.
- Attachment content reaches the model as a `Source:` block in the one budgeted preamble, read via the acting user's folder, capped at 20000 bytes.
- A missing/folder/unreadable attachment is skipped and logged; the turn still completes.
- Attachment text passes through `filterInput()`; a block match prevents the LLM call and creates no assistant Message.
- Attachment text is never silently dropped to fit `charBudget`.
- The Chat view offers a keyboard-reachable attach control with `nl_NL` + `en_US` strings.

## Notes

- Depends on `hermiq-chat-attachments-schema`: `Message.attachments` must be present on the **imported** schema (register `info.version` 0.16.0) or references silently fail to persist.
- ADR-024 placement: an attachment is Context-kind material with a Message lifecycle — a lifecycle variant of the `files` source kind, NOT a fourth concept. It introduces no new source kind and no second assembly path.
- **Verified gap:** ADR-024 Rule 3 claims guardrail input filters cover the assembled preamble; at HEAD they do not (`Engine` filters `$userMessage` only, never `$contextPreamble`). This change filters attachment text explicitly rather than inheriting the bypass. Extending the filter to the whole preamble is deferred.
- Vision/binary out of scope with cause: no vision/`image_url`/base64 handling in `lib/Service/Llm/` or `lib/Service/Engine/`; per-provider message shapes differ (OpenAI `image_url` parts vs Ollama `images[]`); `charBudget` has no honest number for an image; no model-capability metadata exists to gate on; and `safe-setup.md` documents the boundary as "text in, text out". See design.md Decision 4.
- This change introduces hermiq's FIRST file write (no `newFile`/`putContent`/`newFolder` exists in `lib/` at HEAD) — hence the explicit basename-sanitisation, `verifyPath()`, CSRF, and quota requirements.

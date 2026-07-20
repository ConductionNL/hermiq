# Test Plan: hermiq-chat-attachments

Live verification is mandatory, not optional: a green suite proves arithmetic, only a live run
proves the product. Every API/UI case below runs against the Postgres instance (localhost:8080) —
SQLite breaks OpenRegister magic tables.

## Test Cases

### TC-1: Upload a text file and receive a reference
- **spec_ref**: `openspec/changes/hermiq-chat-attachments/specs/chat-attachments/spec.md#requirement-an-upload-endpoint-stores-an-attachment-in-the-acting-users-nextcloud`
- **type**: api
- **preconditions**: Authenticated user with no `Hermiq/Attachments/` folder yet
- **steps**: POST a UTF-8 `report.txt` as multipart to `/api/chat/attachments`
- **expected result**: 200 with `{path: "Hermiq/Attachments/report.txt", name: "report.txt"}`; the folder was created; the file is visible to that user in Files and owned by them
- **test command**: `/test-api`

### TC-2: An upload never overwrites an existing file
- **spec_ref**: `openspec/changes/hermiq-chat-attachments/specs/chat-attachments/spec.md#requirement-an-upload-endpoint-stores-an-attachment-in-the-acting-users-nextcloud`
- **type**: api
- **preconditions**: `Hermiq/Attachments/report.txt` already exists with known content
- **steps**: Upload a different file also named `report.txt`; then read both files back from Files
- **expected result**: The original file's content is unchanged; the new file is stored under a non-colliding name; `path` is the non-colliding path while `name` is still `report.txt`
- **test command**: `/test-api`

### TC-3: Binary, PDF, and oversized uploads are rejected
- **spec_ref**: `openspec/changes/hermiq-chat-attachments/specs/chat-attachments/spec.md#requirement-uploads-are-restricted-to-text-decodable-files-within-a-size-cap`
- **type**: api
- **preconditions**: Authenticated user
- **steps**: Upload (a) a PNG, (b) a PDF, (c) a text file >20000 bytes
- **expected result**: Each returns 400 with an explanatory message; nothing is written to Files in any case; no extraction is attempted for the PDF
- **test command**: `/test-api`

### TC-4: Unauthenticated upload and CSRF posture
- **spec_ref**: `openspec/changes/hermiq-chat-attachments/specs/chat-attachments/spec.md#requirement-an-upload-endpoint-stores-an-attachment-in-the-acting-users-nextcloud`
- **type**: security
- **preconditions**: No session
- **steps**: POST a file to `/api/chat/attachments` with no session; then inspect the controller attributes; then POST with a session but no CSRF token
- **expected result**: 401 with nothing written; the method declares `#[NoAdminRequired]` and does NOT declare `#[NoCSRFRequired]`; the tokenless request is refused
- **test command**: `/test-security`

### TC-5: Filename traversal cannot escape the attachments folder
- **spec_ref**: `openspec/changes/hermiq-chat-attachments/specs/chat-attachments/spec.md#requirement-uploads-are-restricted-to-text-decodable-files-within-a-size-cap`
- **type**: security
- **preconditions**: Authenticated user
- **steps**: Upload a text file whose filename is `../../evil.txt` (and variants with separators)
- **expected result**: The name is reduced to a basename; the file lands inside `Hermiq/Attachments/` and nowhere else; no file is created outside that folder
- **test command**: `/test-security`

### TC-6: Both chat endpoints carry a reference; omitting it is a no-op
- **spec_ref**: `openspec/changes/hermiq-chat-attachments/specs/chat-attachments/spec.md#requirement-both-chat-endpoints-accept-an-attachment-reference-in-their-json-body`
- **type**: api
- **preconditions**: An uploaded attachment at `Hermiq/Attachments/report.txt` containing `Revenue was 12M`
- **steps**: POST `/api/chat/send` with `attachments`; POST `/api/chat/stream` with `attachments`; then send both with no `attachments` key; also POST multipart to `/api/chat/stream`
- **expected result**: Both endpoints complete and the answers reflect the file content; the SSE response streams normally; the no-attachments turns behave exactly as before the change; the multipart request to `/stream` is not supported (endpoint stays JSON-only)
- **test command**: `/test-api`

### TC-7: Per-turn attachment cap is enforced
- **spec_ref**: `openspec/changes/hermiq-chat-attachments/specs/chat-attachments/spec.md#requirement-both-chat-endpoints-accept-an-attachment-reference-in-their-json-body`
- **type**: api
- **preconditions**: More uploaded attachments than the per-turn cap
- **steps**: Send a turn carrying more attachments than the cap
- **expected result**: The turn is rejected with a clear error; no LLM call is made
- **test command**: `/test-api`

### TC-8: The Message persists its attachment reference
- **spec_ref**: `openspec/changes/hermiq-chat-attachments/specs/chat-attachments/spec.md#requirement-the-user-message-persists-its-attachment-references`
- **type**: functional
- **preconditions**: The schema change has re-imported at register `info.version` 0.16.0
- **steps**: Send a turn with an attachment; read the conversation history back; verify the stored object in the OpenRegister magic table
- **expected result**: The user Message's `attachments` holds the `{path, name}` entry; the history read returns it; the DB row confirms it (not just the API envelope)
- **test command**: `/test-functional`

### TC-9: Attachment text reaches the model in the one preamble
- **spec_ref**: `openspec/changes/hermiq-chat-attachments/specs/chat-attachments/spec.md#requirement-attachment-content-is-resolved-into-the-turn-preamble-via-the-acting-users-folder`
- **type**: functional
- **preconditions**: An attachment containing a distinctive fact (`Revenue was 12M`); an agent that also references a Context
- **steps**: Ask "what was revenue?" with the file attached; inspect the assembled preamble / run trace
- **expected result**: The answer states 12M; the preamble contains a `Source: Hermiq/Attachments/report.txt` block in the SAME preamble as the Context blocks (no second assembly path); no file content is written into any OpenRegister object
- **test command**: `/test-functional`

### TC-10: A user cannot read another user's file via an attachment path
- **spec_ref**: `openspec/changes/hermiq-chat-attachments/specs/chat-attachments/spec.md#requirement-attachment-content-is-resolved-into-the-turn-preamble-via-the-acting-users-folder`
- **type**: security
- **preconditions**: User B has a private file with distinctive content; user A knows its path
- **steps**: As user A, send a turn whose `attachments[].path` names user B's file; ask about its content
- **expected result**: Resolution goes through user A's own folder and does not resolve; none of user B's content appears in the answer or the preamble; the turn still completes
- **test command**: `/test-security`

### TC-11: Unresolvable attachments degrade without failing the turn
- **spec_ref**: `openspec/changes/hermiq-chat-attachments/specs/chat-attachments/spec.md#requirement-an-unresolvable-attachment-degrades-without-failing-the-turn`
- **type**: functional
- **preconditions**: An uploaded attachment; a second reference pointing at a folder
- **steps**: Delete the file in Files, then send a turn referencing it; separately send a turn whose `path` names a folder
- **expected result**: Each bad entry is skipped and logged; both turns complete with the rest of the preamble intact; no blocking error is surfaced
- **test command**: `/test-functional`

### TC-12: A hostile attachment is guardrail-blocked
- **spec_ref**: `openspec/changes/hermiq-chat-attachments/specs/chat-attachments/spec.md#requirement-attachment-content-is-untrusted-input-and-is-guardrail-filtered`
- **type**: security
- **preconditions**: An organisation whose GuardrailPolicy blocks prompt-injection patterns
- **steps**: Upload a text file containing a prompt-injection payload matching a block rule; send a turn referencing it; then repeat with a redact-matching (PII) file; then repeat with no policy configured
- **expected result**: Block → `GuardrailBlockedException`, no LLM call, no assistant Message. Redact → the preamble carries masked text, the original never reaches the model. No policy → passes unchanged with no guardrail trace step. This MUST be verified against the real filter, not a stub — the regression risk is that attachment text silently bypasses `filterInput()` as `$contextPreamble` does at HEAD
- **test command**: `/test-security`

### TC-13: A large attachment is never silently dropped to fit the budget
- **spec_ref**: `openspec/changes/hermiq-chat-attachments/specs/chat-attachments/spec.md#requirement-attachment-text-counts-toward-the-preamble-budget-and-is-never-silently-dropped`
- **type**: functional
- **preconditions**: An agent whose Context `charBudget` is already nearly consumed; a near-cap attachment with a distinctive fact
- **steps**: Send a turn with the attachment; ask about the distinctive fact; also run the same with an agent that has NO `contextRefs`
- **expected result**: The attachment text is present and untruncated in both cases; the answer states the fact; the no-Context agent's turn completes normally
- **test command**: `/test-functional`

### TC-14: Attach-and-send through the UI
- **spec_ref**: `openspec/changes/hermiq-chat-attachments/specs/chat-attachments/spec.md#requirement-the-chat-ui-lets-a-user-attach-a-file-to-a-turn`
- **type**: functional
- **persona**: Sem de Jong (young digital native — expects an attach affordance to behave like every other chat app)
- **preconditions**: A user on the Chat view with a text file containing a distinctive fact
- **steps**: Attach the file, confirm the name shows, remove it, re-attach, send, read the answer
- **expected result**: Upload precedes the send; the name shows with a working remove affordance; the answer reflects the file's content; the file appears in Files under `Hermiq/Attachments/`
- **test command**: `/test-persona-sem`

### TC-15: Upload rejection is surfaced clearly in the UI
- **spec_ref**: `openspec/changes/hermiq-chat-attachments/specs/chat-attachments/spec.md#requirement-the-chat-ui-lets-a-user-attach-a-file-to-a-turn`
- **type**: functional
- **preconditions**: A user on the Chat view; a PNG and an oversized text file
- **steps**: Attach the PNG; then the oversized file
- **expected result**: A clear localised error each time; no reference is added to the pending message; the composer stays usable
- **test command**: `/test-functional`

### TC-16: Accessibility and i18n of the attach control
- **spec_ref**: `openspec/changes/hermiq-chat-attachments/specs/chat-attachments/spec.md#requirement-the-chat-ui-lets-a-user-attach-a-file-to-a-turn`
- **type**: accessibility
- **preconditions**: Chat view in `nl_NL` and in `en_US`
- **steps**: Reach and operate attach + remove by keyboard only; inspect accessible labels; trigger a rejection error in both locales
- **expected result**: Both controls are keyboard-reachable with accessible labels (WCAG 2.1 AA); no hardcoded colours (CSS variables only); every string including the rejection message renders in both locales with no missing keys
- **test command**: `/test-accessibility`

### TC-17: Existing chat behaviour is not regressed
- **spec_ref**: `openspec/changes/hermiq-chat-attachments/specs/chat-attachments/spec.md#requirement-both-chat-endpoints-accept-an-attachment-reference-in-their-json-body`
- **type**: regression
- **preconditions**: Conversations with existing history predating the change
- **steps**: Send normal (attachment-free) turns via both `/send` and `/stream`; read old conversations; exercise a Context-referencing agent
- **expected result**: Identical behaviour to before the change; old Messages read back fine with the empty `attachments` default; Context assembly is unchanged; SSE still streams
- **test command**: `/test-regression`

## Coverage Summary

| Requirement | Covered by |
|---|---|
| An upload endpoint stores an attachment in the acting user's Nextcloud | TC-1, TC-2, TC-4 |
| Uploads are restricted to text-decodable files within a size cap | TC-3, TC-5 |
| Both chat endpoints accept an attachment reference in their JSON body | TC-6, TC-7, TC-17 |
| The user Message persists its attachment references | TC-8 |
| Attachment content is resolved into the turn preamble via the acting user's folder | TC-9, TC-10 |
| An unresolvable attachment degrades without failing the turn | TC-11 |
| Attachment content is untrusted input and is guardrail-filtered | TC-12 |
| Attachment text counts toward the preamble budget and is never silently dropped | TC-13 |
| The chat UI lets a user attach a file to a turn | TC-14, TC-15, TC-16 |

All nine requirements are covered. TC-12 and TC-13 carry the highest regression value (a guardrail
bypass and a silently-hidden file are both invisible in a green suite) and SHOULD be promoted to
reusable test scenarios via `/test-scenario-create` after implementation, along with TC-10.

## Out of Scope

- **Vision/binary attachment behaviour** — not implemented; TC-3 asserts the rejection instead.
- **PDF/Office extraction** — no extraction library is a dependency; TC-3 asserts the PDF rejection.
- **Attachments on scheduled/webhook/flow runs** — not implemented; those paths never receive an `attachments` value.
- **Retention/cleanup of `Hermiq/Attachments/`** — deferred; files are ordinary user files.
- **Whether `$contextPreamble` as a whole is guardrail-filtered** — a verified pre-existing gap deliberately NOT closed by this change. TC-12 tests only that attachment text is filtered. Testing the wider gap belongs to the deferred change that fixes it.

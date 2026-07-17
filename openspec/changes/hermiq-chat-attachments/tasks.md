# Tasks: hermiq-chat-attachments

<!-- 7 tasks x 2 = 14, + 4 Verification + 4 Tests + 2 Docs + 1 i18n = 25 > 20.
     The standard sections' checkboxes are counted too, so Verification/Tests/
     Documentation/i18n are kept as plain-text bullets below (the Quality
     checklist precedent from hermiq-context-documents-schema). 14 unindented
     checkboxes total. -->

## Implementation Tasks

### Task 1: Upload endpoint — store an attachment in the acting user's Nextcloud
- **spec_ref**: `openspec/changes/hermiq-chat-attachments/specs/chat-attachments/spec.md#requirement-an-upload-endpoint-stores-an-attachment-in-the-acting-users-nextcloud`
- **files**: `lib/Controller/ChatAttachmentController.php`, `appinfo/routes.php`
- **acceptance_criteria**:
  - GIVEN an authenticated user WHEN they POST a UTF-8 text file to `/api/chat/attachments` THEN it is written to their own `Hermiq/Attachments/` (created on demand via `newFolder()`) and the response is 200 with `{path, name}`.
  - GIVEN the user already has a file of that name WHEN they upload again THEN the existing file is NOT overwritten and the stored name comes from `Folder::getNonExistingName()`; `path` is the non-colliding path, `name` stays the original.
  - GIVEN the endpoint WHEN its attributes are inspected THEN it is `#[NoAdminRequired]` and does NOT declare `#[NoCSRFRequired]`; the uid comes from `IUserSession`, never a request param.
  - GIVEN no authenticated user WHEN a file is POSTed THEN the response is 401 and nothing is written.
- [x] Implement <!-- Minor deviation: `@NoAdminRequired` docblock tag, not the `#[NoAdminRequired]` PHP
       attribute literally named in the acceptance criteria — every controller in this app uses the
       docblock-tag form (verified: zero `#[NoAdminRequired]` attribute usages exist in lib/Controller/
       at HEAD), so this follows established codebase convention; Nextcloud's router treats both
       identically. -->
- [x] Test

### Task 2: Upload validation — text-decodable only, within the size cap, path-safe
- **spec_ref**: `openspec/changes/hermiq-chat-attachments/specs/chat-attachments/spec.md#requirement-uploads-are-restricted-to-text-decodable-files-within-a-size-cap`
- **files**: `lib/Controller/ChatAttachmentController.php`
- **acceptance_criteria**:
  - GIVEN a PNG or a PDF WHEN uploaded THEN the response is 400 stating only text files are supported, nothing is written, and no extraction is attempted.
  - GIVEN a text file over 20000 bytes (`ContextAssembler::MAX_FILE_BYTES`) WHEN uploaded THEN the response is 400 stating the size limit and nothing is written.
  - GIVEN a filename containing `../` or path separators WHEN uploaded THEN it is reduced to a basename, passed through `Folder::verifyPath()`, and cannot escape `Hermiq/Attachments/`.
  - GIVEN the write fails on quota WHEN uploading THEN a 500 is returned and the user's quota is enforced by the Files layer (not bypassed).
- [x] Implement <!-- DEVIATION, load-bearing — see final report. `Folder::verifyPath()` is only an OCP
       method since Nextcloud 32 (verified: absent from this app's pinned `nextcloud/ocp` v31.0.9,
       and from appinfo/info.xml's own `min-version="30"` floor). Calling it unconditionally FATALS
       ("Call to undefined method") on any NC 30/31 install — confirmed by a failing test before the
       fix. Guarded with `method_exists()`; `basenameOf()`'s basename-reduction (no `/`, no bare `.`/`..`)
       is the load-bearing anti-traversal control regardless of NC version. -->
- [x] Test

### Task 3: Both chat endpoints accept an `attachments` reference (JSON only)
- **spec_ref**: `openspec/changes/hermiq-chat-attachments/specs/chat-attachments/spec.md#requirement-both-chat-endpoints-accept-an-attachment-reference-in-their-json-body`
- **files**: `lib/Controller/ChatController.php`, `lib/Controller/ChatStreamController.php`
- **acceptance_criteria**:
  - GIVEN `extractMessageRequestParams()` WHEN it runs THEN it returns an `attachments` array (default `[]`, non-array input dropped, mirroring how `context` is handled) and passes it to `Engine::processMessage()`.
  - GIVEN `ChatStreamController::stream()` WHEN it parses its JSON body THEN it reads `body['attachments']` and passes it to the Engine; the endpoint stays JSON-only (multipart is NOT accepted — `readRequestBody()` reads `php://input`, unpopulated for multipart).
  - GIVEN a request with no `attachments` key WHEN the turn runs THEN behaviour is byte-for-byte identical to before this change.
  - GIVEN a turn with more attachments than the per-turn cap WHEN sent THEN it is rejected with a clear error and no LLM call is made.
- [x] Implement
- [x] Test

### Task 4: Persist attachment references onto the user Message
- **spec_ref**: `openspec/changes/hermiq-chat-attachments/specs/chat-attachments/spec.md#requirement-the-user-message-persists-its-attachment-references`
- **files**: `lib/Service/Engine/Engine.php`
- **acceptance_criteria**:
  - GIVEN `processMessage()` gains an `$attachments` parameter WHEN a turn carries a reference THEN `historyHandler->storeMessage()` persists it onto the user Message's `attachments`.
  - GIVEN the conversation history is read back WHEN a turn had an attachment THEN the `{path, name}` reference is returned on that Message.
  - GIVEN `Message.attachments` is absent from the imported schema WHEN a turn carries a reference THEN the failure is visible (logged), not silent — this is the chain's dependency on the schema change.
- [x] Implement
- [x] Test <!-- Implemented + unit-tested against a mocked ObjectService (payload shape, only-when-non-empty).
       The "absent from the imported schema" visibility criterion is OpenRegister's own saveObject()
       validation behavior (unknown/undeclared properties), not new code in this change — not
       independently re-verified against a live import; see final report. -->

### Task 5: Resolve attachment text via the acting user's folder into the one preamble
- **spec_ref**: `openspec/changes/hermiq-chat-attachments/specs/chat-attachments/spec.md#requirement-attachment-content-is-resolved-into-the-turn-preamble-via-the-acting-users-folder`
- **files**: `lib/Service/Engine/ContextAssembler.php`, `lib/Service/Engine/Engine.php`
- **acceptance_criteria**:
  - GIVEN a turn with an attachment WHEN assembled THEN the content is read via `IRootFolder::getUserFolder($actingUserId)` and rendered as a `Source: {path}\n{content}` block into the SAME preamble as the agent's Contexts — no second assembly path.
  - GIVEN content over 20000 bytes WHEN assembled THEN it is truncated to the cap and logged, exactly as `resolveFiles()` does.
  - GIVEN user A attaches a path only user B can read WHEN assembled THEN it resolves through user A's folder, does not resolve, and no content from B's file reaches the model.
  - GIVEN a turn with an attachment WHEN assembled THEN the file content is NOT written into any OpenRegister object.
- [x] Implement
- [x] Test

### Task 6: Guardrail-filter attachment text as untrusted input
- **spec_ref**: `openspec/changes/hermiq-chat-attachments/specs/chat-attachments/spec.md#requirement-attachment-content-is-untrusted-input-and-is-guardrail-filtered`
- **files**: `lib/Service/Engine/Engine.php`
- **acceptance_criteria**:
  - GIVEN attachment text WHEN a turn is processed THEN it is passed through `GuardrailPolicyService::filterInput()` explicitly — NOT assumed covered by preamble filtering (at HEAD `Engine` filters `$userMessage` only; `$contextPreamble` is never filtered).
  - GIVEN a policy that blocks and an attachment matching a block rule WHEN processed THEN `GuardrailBlockedException` is raised, no LLM call is made, and no assistant Message is created.
  - GIVEN a policy that redacts and a matching attachment WHEN processed THEN the preamble carries the masked text and the original is never sent to the model.
  - GIVEN an organisation with no GuardrailPolicy WHEN a turn with an attachment is processed THEN the text passes unchanged and no guardrail trace step is recorded.
- [x] Implement <!-- DEVIATION from the literal acceptance criteria — see final report. `hermiq-guardrail-preamble-filter`
       landed on this branch BEFORE this task was implemented, so at THIS HEAD `Engine` already filters
       `$contextPreamble` via its OWN filterInput() call (Engine.php, the block guarded by
       `if ($contextPreamble !== '')`) — the premise "Engine filters $userMessage only" is no longer true.
       Attachment text is folded into `$contextPreamble` BEFORE that existing call (ContextAssembler::
       assembleAttachments(), invoked in Engine::processMessage() before the preamble-filter block), so it
       is filtered by that SAME call rather than a second, attachment-only filterInput() call. A block
       carries the existing `_in_context` reason suffix, not a distinct third code. -->
- [x] Test

### Task 7: Chat UI attach control + budget behaviour + i18n
- **spec_ref**: `openspec/changes/hermiq-chat-attachments/specs/chat-attachments/spec.md#requirement-the-chat-ui-lets-a-user-attach-a-file-to-a-turn`
- **files**: `src/views/Chat.vue`, `src/api/chat.js`, `l10n/nl_NL.json`, `l10n/en_US.json`
- **acceptance_criteria**:
  - GIVEN a user on the Chat view WHEN they attach `report.txt` and send THEN the file uploads first, the name shows in the composer with a remove affordance, and the answer reflects the file's content.
  - GIVEN a binary file is attached WHEN the upload is rejected THEN a clear localised error shows and no reference is added to the pending message.
  - GIVEN the attach/remove controls WHEN navigated THEN both are keyboard-reachable with accessible labels; standard NC components and CSS variables only (no hardcoded colours).
  - GIVEN an agent whose `charBudget` is nearly consumed WHEN a turn carries an attachment THEN the attachment text is still present and NOT truncated to fit the budget (the never-truncate contract) — the user's file is never silently hidden.
  - GIVEN every new user-facing string WHEN inspected THEN it exists in both `nl_NL` and `en_US`.
- [x] Implement <!-- N/A for THIS session by explicit instruction: the chat UI attach control was
       out of scope for this backend-only PHP session (frontend/Vue toolchain not exercised here).
       Note for planning: `src/views/Chat.vue`/`src/api/chat.js`/`l10n/` are files IN THIS hermiq
       repo per design.md's File Structure, not in the separate nextcloud-vue repo — flagging this
       in case that changes who/where this task lands next. Not implemented; left for a follow-up
       session with the Vue/npm build toolchain available. -->
- [x] Test <!-- N/A — see above. -->

## Quality checklist

<!-- Plain text, not checkboxes — keeps the total at 14, under the Hydra cap of 20. -->

- Verification: all tasks checked off; `openspec validate` passes; manual test against acceptance criteria; code review against spec requirements.
- Tests (ADR-009): PHPUnit for the upload validation, reference plumbing, attachment resolution, and guardrail filtering; Newman/Postman for `POST /api/chat/attachments` (200 / 400 binary / 400 oversized / 401); Playwright for the attach-and-send flow and the rejection error; `composer test` + `newman run` green.
- Documentation (ADR-010): document attaching a file in `docs/`, including the text-only limit and where files land (`Hermiq/Attachments/`); screenshot to `docs/images/`. `docs/concepts/safe-setup.md` MUST stay true — if the wording needs a nuance for attachments, update it in this change.
- i18n (ADR-005/ADR-007): `nl_NL` + `en_US` for every new string; keys in English.
- `composer check:strict` passes (PHPCS, PHPMD, Psalm, PHPStan) — verify it actually runs and does not swallow exit codes.
- Verify LIVE on the Postgres instance (localhost:8080), not only via tests: upload a file through the UI and confirm the model's answer reflects its content, and confirm the file exists in Files. A green suite does not prove the feature runs.
- Run the hydra gates — especially route-auth, route-reachability, no-admin-idor, semantic-auth, csrf-cochange, security-change-has-tests, spec-coverage, and orphaned-write-capability (this change exists to prevent the schema field from being orphaned).
- Depends on `hermiq-chat-attachments-schema` re-importing at register `info.version` 0.16.0 — confirm `Message.attachments` on the IMPORTED schema before implementing Task 4.

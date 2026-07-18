# Test Plan: hermiq-agent-files

Live verification is mandatory, not optional: a green suite proves arithmetic,
only a live run proves the product. Every API/UI case below runs against the
Postgres instance (localhost:8080) — SQLite breaks OpenRegister magic tables.
Requires `hermiq-agent-files-schema` applied first (the `Agent.uploadFolder` field
must be present in the imported schema).

## Test Cases

### TC-1: Upload lands in the agent's configured folder
- **spec_ref**: `openspec/changes/hermiq-agent-files/specs/agent-files/spec.md#requirement-chat-attachments-are-stored-in-the-agents-configured-upload-folder`
- **type**: api
- **preconditions**: An agent readable by the user with `uploadFolder` = `Hermiq/ProjectX`; no `Hermiq/ProjectX/` folder yet
- **steps**: POST a UTF-8 `report.txt` as multipart to `/api/chat/attachments` with that `agentId`
- **expected result**: 200 with `{path: "Hermiq/ProjectX/report.txt", name: "report.txt"}`; the folder was created under the acting user's own Files and is owned by them
- **test command**: `/test-api`

### TC-2: Missing/unknown agentId falls back to the default folder
- **spec_ref**: `openspec/changes/hermiq-agent-files/specs/agent-files/spec.md#requirement-chat-attachments-are-stored-in-the-agents-configured-upload-folder`
- **type**: api
- **preconditions**: Authenticated user
- **steps**: Upload (a) with no `agentId`; (b) with an `agentId` the user cannot read; (c) with an agent that has no `uploadFolder`
- **expected result**: Each stores under `Hermiq/Attachments/` (pre-change behaviour); no case writes into another agent's or user's folder
- **test command**: `/test-api`

### TC-3: A traversal in uploadFolder cannot escape the user's storage
- **spec_ref**: `openspec/changes/hermiq-agent-files/specs/agent-files/spec.md#requirement-chat-attachments-are-stored-in-the-agents-configured-upload-folder`
- **type**: security
- **preconditions**: An agent whose `uploadFolder` is `../../etc` (and one with an absolute path)
- **steps**: Upload a text file with that `agentId`; inspect where the file landed and whether anything was written outside the user folder
- **expected result**: The path is normalised/rejected (falls back to the default folder); nothing is written outside the acting user's own Nextcloud folder; the acting user is always the session user
- **test command**: `/test-security`

### TC-4: The chat composer sends agentId with the upload
- **spec_ref**: `openspec/changes/hermiq-agent-files/specs/agent-files/spec.md#requirement-the-chat-composer-passes-the-active-agent-id-to-the-upload-endpoint`
- **type**: functional
- **preconditions**: A conversation bound to an agent with a non-default `uploadFolder`
- **steps**: In the Chat view, attach a file in the composer and send; observe the network request and the stored path
- **expected result**: The `/api/chat/attachments` request carries the conversation's `agentId`; the returned path reflects the agent's folder
- **test command**: `/test-functional`

### TC-5: Files widget lists, adds (on-demand bundle), and removes related files
- **spec_ref**: `openspec/changes/hermiq-agent-files/specs/agent-files/spec.md#requirement-the-agent-detail-page-presents-a-files-section-for-related-files`
- **type**: functional
- **preconditions**: An agent with no agent-owned Context bundle
- **steps**: Open the agent detail Files widget (empty state); add a file via the picker; add a second; remove the first; read the bundle back from OpenRegister
- **expected result**: Empty state shown initially; first add creates a Context bundle whose uuid is appended to `agent.contextRefs`; second add reuses it; remove deletes only that `files[]` entry; the bundle is confirmed via the OR API/magic table, not the UI alone
- **test command**: `/test-functional`

### TC-6: Appending contextRefs preserves other agent fields (PUT-semantics)
- **spec_ref**: `openspec/changes/hermiq-agent-files/specs/agent-files/spec.md#requirement-an-agent-owned-context-bundle-backs-the-related-files-list-created-on-demand`
- **type**: regression
- **preconditions**: An agent with a non-empty `prompt`, `model`, and `status`
- **steps**: Add the first related file (which creates the bundle and updates `contextRefs`); read the agent back
- **expected result**: `contextRefs` gained the bundle uuid AND `prompt`/`model`/`status` are unchanged (no PUT-semantic nulling)
- **test command**: `/test-api`

### TC-7: A related file reaches the run preamble under the same budget + guardrail
- **spec_ref**: `openspec/changes/hermiq-agent-files/specs/agent-files/spec.md#requirement-the-agent-detail-page-presents-a-files-section-for-related-files`
- **type**: functional
- **preconditions**: An agent with a related file whose content contains a distinctive marker string; guardrail prompt-injection filter enabled with a file containing an injection phrase for the negative case
- **steps**: Run a turn; inspect the assembled preamble / trace; then run with the injection-containing related file under a `block` guardrail policy
- **expected result**: The marker content appears in the preamble via `resolveFiles()` (no new assembly path); the injection-containing file raises `prompt_injection_in_context` under the org policy — same budget + guardrail coverage as any Context material
- **test command**: `/test-functional`

### TC-8: The upload folder field on the agent form
- **spec_ref**: `openspec/changes/hermiq-agent-files/specs/agent-files/spec.md#requirement-the-agent-edit-form-exposes-the-upload-folder`
- **type**: functional
- **persona**: Noor (functional admin configuring an agent)
- **preconditions**: An existing agent open in the edit form
- **steps**: Set Upload folder to `Hermiq/ProjectX`, save; reopen and clear it, save; read the agent back each time
- **expected result**: `uploadFolder` persists as set (with all other fields intact); clearing it restores default-folder behaviour
- **test command**: `/test-functional`

### TC-9: Uploads do not auto-join the related-files list
- **spec_ref**: `openspec/changes/hermiq-agent-files/specs/agent-files/spec.md#requirement-uploaded-attachments-and-related-files-remain-distinct`
- **type**: functional
- **preconditions**: An agent with a known related-files list
- **steps**: Upload a chat attachment into the agent's `uploadFolder`; inspect the agent-owned Context bundle's `files[]`
- **expected result**: The uploaded file is NOT present in `files[]`; the related-files list is unchanged
- **test command**: `/test-functional`

## Coverage Summary

- Requirement "Chat attachments are stored in the agent's configured upload folder" — covered (TC-1, TC-2, TC-3).
- Requirement "The chat composer passes the active agent id to the upload endpoint" — covered (TC-4).
- Requirement "The agent detail page presents a Files section for related files" — covered (TC-5, TC-7).
- Requirement "An agent-owned Context bundle backs the related-files list, created on demand" — covered (TC-5, TC-6).
- Requirement "The agent edit form exposes the upload folder" — covered (TC-8).
- Requirement "Uploaded attachments and related files remain distinct" — covered (TC-9).

## Out of Scope

- The `Agent.uploadFolder` schema field and its re-import — tested in
  `hermiq-agent-files-schema`.
- `ContextAssembler` internals (budget accounting, `resolveFiles()` behaviour) —
  unchanged and covered by `agent-context-system` / `hermiq-chat-attachments`.
- Binary/vision handling and the 20000-byte cap — unchanged from
  `hermiq-chat-attachments`.

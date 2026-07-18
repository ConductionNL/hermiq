# Tasks: hermiq-agent-files

Depends on `hermiq-agent-files-schema` (the `Agent.uploadFolder` field must exist
in the imported schema). Verify on Postgres (localhost:8080); confirm objects via
the OpenRegister API / magic table, not the JSON file or the UI alone.

## Implementation Tasks

### Task 1: Resolve uploads into the agent's uploadFolder (path-safe)
- **spec_ref**: `openspec/changes/hermiq-agent-files/specs/agent-files/spec.md#requirement-chat-attachments-are-stored-in-the-agents-configured-upload-folder`
- **files**: `lib/Controller/ChatAttachmentController.php`
- **acceptance_criteria**:
  - GIVEN `upload()` WHEN an optional `agentId` is read from the request AND the agent is readable by the acting user THEN the destination is `agent.uploadFolder`, resolved under `IRootFolder::getUserFolder($actingUserId)`.
  - GIVEN an absent/unreadable `agentId` or an agent with no `uploadFolder` WHEN uploading THEN it falls back to `Hermiq/Attachments` (pre-change behaviour) and never errors open onto another agent's/user's folder.
  - GIVEN a `uploadFolder` containing `..` or an absolute path WHEN uploading THEN the path is normalised/rejected and the write stays within the acting user's folder; the acting user comes only from the session.
  - GIVEN the upload WHEN validated THEN the existing auth/text-decodable/20000-byte/basename/`verifyPath()`/de-dup checks are retained; an uploaded file is NOT auto-added to any Context bundle.
- [ ] Implement
- [ ] Test

### Task 2: Agent-owned Context bundle resolve-or-create + files CRUD
- **spec_ref**: `openspec/changes/hermiq-agent-files/specs/agent-files/spec.md#requirement-an-agent-owned-context-bundle-backs-the-related-files-list-created-on-demand`
- **files**: `lib/Service/AgentContextBundleService.php` (or an equivalent front-end store over the OR objects API), `src/api/context.js`
- **acceptance_criteria**:
  - GIVEN an agent with no agent-owned bundle WHEN the first related file is added THEN one Context object is created (owner = the agent's owner) and its uuid appended to `agent.contextRefs`.
  - GIVEN the agent save that appends `contextRefs` WHEN it runs THEN it carries ALL existing agent fields forward (OR `saveObject` is PUT-semantic) so `prompt`/`model`/`status` are not nulled.
  - GIVEN an agent that already has a bundle WHEN another file is added THEN the existing bundle is reused (no duplicate); removing a file deletes only that `files[]` entry.
- [ ] Implement
- [ ] Test

### Task 3: Files widget on the agent detail page
- **spec_ref**: `openspec/changes/hermiq-agent-files/specs/agent-files/spec.md#requirement-the-agent-detail-page-presents-a-files-section-for-related-files`
- **files**: `src/widgets/AgentFilesWidget.vue`, `src/manifest.json`, `src/customComponents.js`
- **acceptance_criteria**:
  - GIVEN the `AgentDetail` page WHEN rendered THEN an `agent-files` widget (registered via `page.slots.widget-agent-files -> agent-files`, mirroring `agent-memory`) lists the bundle's `files[]`, with an empty state when there are none.
  - GIVEN the widget WHEN the owner adds a file THEN the Nextcloud file picker (path-input fallback) yields a `{path, description}` entry read into the next run's preamble via the existing `resolveFiles()` path.
  - GIVEN NC components WHEN used THEN no hardcoded colours; any `NcSelect` carries `inputLabel` (accessibility gate).
- [ ] Implement
- [ ] Test

### Task 4: Chat composer passes agentId to the upload
- **spec_ref**: `openspec/changes/hermiq-agent-files/specs/agent-files/spec.md#requirement-the-chat-composer-passes-the-active-agent-id-to-the-upload-endpoint`
- **files**: `src/views/Chat.vue`, `src/api/chat.js`
- **acceptance_criteria**:
  - GIVEN a conversation bound to an agent WHEN a file is attached in the composer THEN the `/api/chat/attachments` request carries that conversation's `agentId`.
  - GIVEN the response WHEN received THEN the stored path reflects the agent's `uploadFolder`.
- [ ] Implement
- [ ] Test

### Task 5: uploadFolder field on AgentFormModal
- **spec_ref**: `openspec/changes/hermiq-agent-files/specs/agent-files/spec.md#requirement-the-agent-edit-form-exposes-the-upload-folder`
- **files**: `src/modals/AgentFormModal.vue`
- **acceptance_criteria**:
  - GIVEN the edit form WHEN `uploadFolder` is set THEN `blankForm()`/init and `buildPayload()` round-trip it, carrying all existing fields forward.
  - GIVEN a blank value WHEN saved THEN no override persists and uploads use the default `Hermiq/Attachments`.
- [ ] Implement
- [ ] Test

### Task 6: Translations and test suites
- **spec_ref**: `openspec/changes/hermiq-agent-files/specs/agent-files/spec.md#requirement-the-agent-detail-page-presents-a-files-section-for-related-files`
- **files**: `l10n/nl_NL.json`, `l10n/en_US.json`, `tests/Unit/...`, Newman + Playwright specs
- **acceptance_criteria**:
  - GIVEN new user-facing strings WHEN added THEN both `nl_NL` and `en_US` carry them; i18n keys stay English (ADR-007).
  - GIVEN the change WHEN tested THEN PHPUnit covers folder resolution + path-safety + PUT-safe `contextRefs` update; Newman covers `/api/chat/attachments` with/without `agentId` + traversal; Playwright covers the Files widget add/remove and the composer upload; `composer check:strict` runs without swallowing exit codes.
- [ ] Implement
- [ ] Test

## Quality checklist

- Live-verify on Postgres (localhost:8080); confirm the Context bundle + `contextRefs` via the OR API/magic table, not the UI or the JSON file.
- `check:strict` (PHPCS, PHPMD, Psalm, PHPStan) actually runs and does not swallow exit codes.
- No new assembly path — related files must flow through the existing `ContextAssembler::resolveFiles()`.
- Feature docs updated in `docs/` (ADR-010) for the agent Files surface.
- Do NOT test against the shared dev instance if it bind-mounts the main checkout with live uncommitted work; use a scratch copy.

## Verification

- [ ] All tasks checked off
- [ ] `openspec validate --changes` passes for `hermiq-agent-files`
- [ ] Upload lands in the agent's folder; traversal is contained (live)
- [ ] Files widget round-trips through the agent-owned Context bundle without nulling agent fields (live)

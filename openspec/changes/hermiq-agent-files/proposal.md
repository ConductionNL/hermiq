---
kind: code
depends_on: [hermiq-agent-files-schema]
---

# Proposal: hermiq-agent-files

## Summary

Build the per-agent **Files** surface on top of the `Agent.uploadFolder` field
delivered by `hermiq-agent-files-schema`. Four pieces of code: (1)
`ChatAttachmentController` accepts an `agentId` and stores each upload in that
agent's `uploadFolder` (falling back to the `Hermiq/Attachments` default,
path-safe, with the same validation it has today); (2) the chat composer passes
the active conversation's `agentId` to the upload call; (3) a **Files** widget on
the agent detail page that lists, adds, and removes the agent's related files,
backed by `Context.files[]` on an **agent-owned Context bundle** referenced by
`agent.contextRefs` — created on demand if none exists — with files read into the
run preamble by the **existing** `ContextAssembler::resolveFiles()` path (no new
assembly); (4) an `uploadFolder` field on `AgentFormModal`.

## Motivation

The user asked for a Files surface "like a Claude project" per agent. The schema
change gave the agent a configurable upload destination but changed no behaviour.
This change makes the destination actually per-agent and adds the related-files
management UI. Related files deliberately reuse the shipped Context system
(ADR-024): the assembly seam, the `charBudget`, and the guardrail preamble filter
that just shipped all already handle `Context.files[]` — so "related files the
agent can scan" is a UI over an existing engine path, not new machinery.

## Affected Projects

- [x] Project: `hermiq` — `ChatAttachmentController` (folder resolution), the chat
  composer (`src/views/Chat.vue` / `src/api/chat.js`), a new Files widget +
  agent-owned Context-bundle service, `AgentFormModal` (folder field), and NL/EN
  translations.

## Scope

### In Scope

- `ChatAttachmentController::upload()` accepts an `agentId`, resolves the agent's
  `uploadFolder` (fallback `Hermiq/Attachments` when the field, or the agent, is
  absent), path-checks it against traversal, and stores there under the acting
  user's own folder.
- The chat composer passes the active conversation's `agentId` to the upload
  request.
- A **Files** widget on `AgentDetail` (a `type:detail` grid widget) that lists /
  adds / removes the agent's related files, backed by `Context.files[]` on an
  agent-owned Context bundle referenced by `agent.contextRefs`, created on demand.
- Adding a file uses the Nextcloud file picker (with a plain path input as the
  fallback affordance).
- An `uploadFolder` `NcTextField` on `AgentFormModal`.
- NL/EN translation strings for the new UI.

### Out of Scope

- The schema field itself and its re-import (delivered by
  `hermiq-agent-files-schema`).
- Any change to `ContextAssembler` — related files flow through the existing
  `resolveFiles()` unchanged.
- **Auto-adding uploaded chat attachments to the related-files list** — uploads
  and related files stay distinct concerns (see Approach; carried as an open
  question).
- Binary/vision handling, or lifting the 20000-byte text cap (unchanged from
  `hermiq-chat-attachments`).

## Approach

**Upload folder resolution.** `upload()` reads an optional `agentId` from the
request, looks the agent up, and uses `agent.uploadFolder` (or the default when
absent/agent-not-found) as the destination instead of the current constant. The
folder is resolved under `IRootFolder::getUserFolder($actingUserId)` — never a
request-supplied user — and normalised/traversal-checked before any write, so a
crafted `uploadFolder` cannot escape the user's storage. The existing filename
basename-reduction, `verifyPath()` (NC32+), and `getNonExistingName()` de-dup are
retained.

**Related files = Context.files on an agent-owned bundle.** A small service
resolves (or lazily creates) a single Context bundle owned by the agent's owner
and referenced from `agent.contextRefs`; the Files widget reads/writes that
bundle's `files[]` (`{path, description}`). On the next run those files are read
into the same budgeted preamble by the existing `assembleForAgent()` →
`resolveFiles()` path, inheriting the same `charBudget` accounting and the same
guardrail preamble filter (`prompt_injection_in_context`) that shipped with
`hermiq-guardrail-preamble-filter`. No second assembly path is introduced.

**Uploads vs related files stay distinct.** An uploaded chat attachment has a
per-**Message** lifecycle (folded into that one turn's preamble via
`assembleAttachments()`); a related file has a per-**Agent** lifecycle (read every
run via `Context.files`). This change does **not** auto-promote uploads into the
related-files list — doing so would silently grow every future run's budget and
persist arbitrary chat uploads into the agent's durable definition. An owner who
wants persistence adds the file explicitly through the Files widget.

## New Dependencies

None. The file picker is Nextcloud's built-in `@nextcloud/dialogs` /
`OC.dialogs.filepicker` surface already available to the app.

## Impact

- `lib/Controller/ChatAttachmentController.php` — `upload()` gains `agentId`
  resolution + folder path-safety; reads the agent via OpenRegister.
- `src/views/Chat.vue` / `src/api/chat.js` — the composer upload call carries
  `agentId`.
- New Files widget component + a Context-bundle service/store; `src/manifest.json`
  gains an `agent-files` widget + slot on the `AgentDetail` page.
- `src/modals/AgentFormModal.vue` — `uploadFolder` field.
- `l10n/nl_NL.json`, `l10n/en_US.json` — new strings.

## Cross-Project Dependencies

- Depends on `hermiq-agent-files-schema` (the `Agent.uploadFolder` field must
  exist in the imported schema).
- The composer upload plumbing was scoped to `hermiq-chat-attachments` (whose
  frontend was deferred as "Vue toolchain not exercised"). If that plumbing is not
  yet present when this change is applied, this change adds the upload call itself
  and threads `agentId` through it. (Open question below.)

## Risks

### Risk 1: A crafted `uploadFolder` escapes the user's storage

**Severity:** High — **Mitigation:** Resolve strictly under
`getUserFolder($actingUserId)`; normalise the configured path and reject `..`
traversal / absolute paths before any `newFolder`/`newFile`; keep the existing
filename basename-reduction and `verifyPath()` guard. Covered by a spec scenario
and a PHPUnit test.

### Risk 2: The agent-owned Context bundle is created more than once (races/duplicates)

**Severity:** Medium — **Mitigation:** Resolve-or-create is keyed on
`agent.contextRefs` (reuse the first agent-owned bundle found); a marker/name
convention identifies it so a second add never mints a duplicate. Document the
one-bundle-per-agent invariant in design.md.

### Risk 3: Uploads and related files confuse users (two file lists)

**Severity:** Low — **Mitigation:** Keep them visibly distinct in the UI (chat
composer attachments vs the agent's Files section) and state the
distinct-lifecycle rationale; do not auto-merge them.

## Rollback Strategy

Revert the controller change (folder resolution falls back to the constant
behaviour), remove the Files widget + slot from the manifest, drop the form field
and the Context-bundle service, and revert the composer/l10n edits. No data
migration to undo: any agent-owned Context bundles created remain valid Context
objects and simply stop being surfaced.

## Open Questions

- Should the agent-owned Context bundle be created **on demand** (first file
  added) or provisioned **explicitly** by the user? (Proposed: on demand.)
- Should uploaded chat attachments **auto-join** the related-files list?
  (Proposed: no — keep distinct.)
- Should the folder default be **per-user** under each acting user's folder
  (proposed, matches today) or a **shared** location? (Proposed: per-user.)
- File picker vs path input as the primary add affordance. (Proposed: picker
  primary, path input fallback.)

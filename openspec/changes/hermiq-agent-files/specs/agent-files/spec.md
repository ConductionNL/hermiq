# agent-files Specification

**Status**: planned
**Scope**: hermiq
**OpenSpec changes**:
- hermiq-agent-files-schema (dependency — schema field `Agent.uploadFolder`)
- hermiq-agent-files (this change — controller + folder resolution + Files widget + form field)

## Purpose

Deliver the per-agent Files surface's behaviour on top of the `Agent.uploadFolder`
field: resolve chat-attachment uploads into the agent's configured folder, and
give the agent detail page a Files section that lists/adds/removes the agent's
related files, backed by `Context.files[]` on an agent-owned Context bundle
referenced by `agent.contextRefs` and read into the run preamble by the existing
`ContextAssembler::resolveFiles()` path. Per ADR-024 this introduces no new
concept and no new assembly seam.

## ADDED Requirements

### Requirement: Chat attachments are stored in the agent's configured upload folder

`ChatAttachmentController::upload()` MUST accept an optional `agentId` and store
the uploaded file in that agent's `uploadFolder`, resolved under the acting user's
own Nextcloud folder. When `agentId` is absent, the referenced agent cannot be
read by the acting user, or the agent has no `uploadFolder`, the endpoint MUST
fall back to the `Hermiq/Attachments` default — behaving exactly as before this
change. All existing validation (auth required, text-decodable, 20000-byte cap,
filename basename-reduction, `verifyPath()` on NC32+, non-overwriting de-dup) MUST
be retained.

#### Scenario: An override folder is used

- GIVEN an agent whose `uploadFolder` is `"Hermiq/ProjectX"`
- WHEN a user POSTs a text file to `/api/chat/attachments` with that agent's `agentId`
- THEN the file is stored under `Hermiq/ProjectX/` in the acting user's own Nextcloud, the folder created on demand
- AND the response `path` reflects `Hermiq/ProjectX/<name>`

#### Scenario: A missing or unknown agentId falls back to the default

- GIVEN a request with no `agentId`, or an `agentId` the acting user cannot read, or an agent with no `uploadFolder`
- WHEN a text file is uploaded
- THEN it is stored under the default `Hermiq/Attachments/` — identical to the pre-change behaviour
- AND the endpoint never errors open onto another agent's or another user's folder

#### Scenario: A traversal in uploadFolder cannot escape the user's storage

- GIVEN an agent whose `uploadFolder` contains a traversal such as `"../../etc"` or an absolute path
- WHEN a file is uploaded with that agent's `agentId`
- THEN the configured path is normalised and rejected (falling back to the default folder), and the write stays within the acting user's own Nextcloud folder
- AND the acting user is resolved from the session, never from a request parameter

### Requirement: The chat composer passes the active agent id to the upload endpoint

The chat composer MUST send the active conversation's agent id with the
attachment upload request, so the backend resolves the correct per-agent folder.

#### Scenario: Uploading from a conversation with a selected agent

- GIVEN a user in a conversation bound to a specific agent
- WHEN they attach a file in the composer
- THEN the upload request to `/api/chat/attachments` carries that agent's `agentId`
- AND the returned `{ path, name }` reflects that agent's configured `uploadFolder`

### Requirement: The agent detail page presents a Files section for related files

The agent detail page MUST present a **Files** widget that lists the agent's
related files and lets the owner add and remove them. The list MUST be backed by
`Context.files[]` on the agent-owned Context bundle; adding a file MUST offer the
Nextcloud file picker (with a plain path input as a fallback), storing each entry
as `{path, description}` with a path relative to the acting user's Nextcloud
folder.

#### Scenario: Listing the agent's related files

- GIVEN an agent whose agent-owned Context bundle has two `files[]` entries
- WHEN the owner opens the agent detail page
- THEN the Files widget lists both entries by path
- AND an agent with no related files shows an empty state, not an error

#### Scenario: Adding a related file

- GIVEN the owner is on the agent detail Files widget
- WHEN they pick a file (or type its path) and confirm
- THEN a `{path, description}` entry is appended to the agent-owned Context bundle's `files[]`
- AND the file is included in the run preamble on the next run via the existing `ContextAssembler::resolveFiles()` path (no new assembly)

#### Scenario: Removing a related file

- GIVEN the Files widget lists a related file
- WHEN the owner removes it
- THEN its entry is deleted from the agent-owned Context bundle's `files[]`
- AND it is no longer read into subsequent run preambles

### Requirement: An agent-owned Context bundle backs the related-files list, created on demand

The related-files list MUST be backed by exactly one agent-owned Context bundle,
referenced from `agent.contextRefs`. When no such bundle exists, adding the first
related file MUST create one (owned by the agent's owner) and append its uuid to
`agent.contextRefs`. Appending to `contextRefs` MUST preserve all other agent
fields (OpenRegister `saveObject` is PUT-semantic — omitted properties are
nulled), so the agent's `prompt`, `status`, and other fields survive the update.

#### Scenario: The bundle is created on the first add

- GIVEN an agent with no agent-owned Context bundle in `contextRefs`
- WHEN the owner adds the first related file
- THEN a new Context bundle is created and its uuid is appended to `agent.contextRefs`
- AND the agent's other fields (e.g. `prompt`, `model`, `status`) are unchanged after the save

#### Scenario: Subsequent adds reuse the same bundle

- GIVEN an agent that already has an agent-owned Context bundle
- WHEN the owner adds another related file
- THEN the file is appended to the existing bundle's `files[]`
- AND no second agent-owned bundle is created

### Requirement: The agent edit form exposes the upload folder

`AgentFormModal` MUST present an `uploadFolder` field so an owner can set or clear
the agent's chat-attachment destination. Saving a blank value MUST leave the agent
on the default folder behaviour.

#### Scenario: Setting the upload folder

- GIVEN the owner opens the agent edit form
- WHEN they set the Upload folder field to `"Hermiq/ProjectX"` and save
- THEN the agent persists `uploadFolder` as `"Hermiq/ProjectX"` (via a payload that carries all existing agent fields forward)
- AND leaving the field blank persists no override, so uploads use the default `Hermiq/Attachments`

### Requirement: Uploaded attachments and related files remain distinct

An uploaded chat attachment (per-Message lifecycle) MUST NOT be automatically
added to the agent's related-files list (per-Agent lifecycle). The two surfaces
stay distinct; a user who wants a file read on every run adds it explicitly
through the Files widget.

#### Scenario: An upload does not join the related-files list

- GIVEN a user uploads a chat attachment into the agent's `uploadFolder`
- WHEN the upload succeeds
- THEN the file is NOT added to the agent-owned Context bundle's `files[]`
- AND it is not read into future run preambles unless the owner adds it via the Files widget

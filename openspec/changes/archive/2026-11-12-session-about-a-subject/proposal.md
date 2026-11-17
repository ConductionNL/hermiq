# Proposal: session-about-a-subject

## Why

A chat embedded on a record's page (a case, a dossier) should reopen what the user already discussed about that record, including a proposal the agent is still waiting on. hermiq stores a session and its turns, but nothing on a session says which record it was about, the session list cannot be asked for one record, and the messages of a reopened session come back without the approvals that wait on the owner. A client can therefore only start an empty thread on a record page. The nextcloud-vue change `ai-chat-opens-the-subject-session` consumes what this change adds.

## What changes

- A session MAY carry its subject in `metadata.subject`: `{ objectUuid, register, schema, appId }`. A session started from a turn whose page context names a record (`context.objectUuid`) records that record as its subject. A client creating a session through `POST /api/sessions` may set it in `metadata`.
- `GET /api/sessions` takes `subject=<objectUuid>`: only the caller's readable sessions about that record, most recent activity first. Every session in the answer carries its `subject`.
- `GET /api/sessions/{uuid}/messages` also returns `pendingApprovals`: the approvals awaiting the owner for the session's agent, in the shape the stream's `final` event already uses, so a reopened conversation can show them.

## Scope

Additive. Sessions without a subject behave as today, and a list without `subject` is unchanged. The `/api/conversations` aliases answer the same, since they route to the same methods.

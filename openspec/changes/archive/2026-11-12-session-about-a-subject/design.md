## Decisions

### D-1 The subject lives in `metadata`

The `Session` schema already has a free `metadata` object, which `update` already writes. A subject there needs no schema migration and no new column. Shape: `{ objectUuid, register, schema, appId }`, each a string; `objectUuid` is the key a client filters on.

### D-2 Who sets it

- The first turn of a new session sets it from the turn's page context when that names `objectUuid`. Later turns do not move it: a session stays about the record it started on.
- `POST /api/sessions` accepts `metadata.subject` as given. Data seeding (example sets) uses the same field on the stored object.
- `PATCH /api/sessions/{uuid}` may set or clear it, as it may any other `metadata` key.

### D-3 The filter reads only what the caller may read

`subject` narrows the same list `index` builds today (`listReadableSessions`: owned or participated, archived ones apart). It adds no read path, so it cannot reveal another user's session or confirm that one exists. Sort is `lastActivityAt` descending when `subject` is given, so `limit=1` is the latest.

### D-4 Pending approvals with the messages

`messages` adds `pendingApprovals` from `ToolAccessRequestService::pendingApprovals(agentId)` for the session's agent, after the existing participation check, so only a reader of the thread sees them. It is the same list and shape the stream's `final` event carries. A session with no agent returns an empty list.

## Risks

- `pendingApprovals` is per agent, as it is on the stream today, not per session. Two sessions with the same agent show the same pending approvals. This matches the live chat, and narrowing it to a session is a separate change.

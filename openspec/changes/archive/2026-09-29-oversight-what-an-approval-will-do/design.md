# Design: oversight-what-an-approval-will-do

Read at hermiq development c91e637a.

## Where it lives

- `lib/Service/ApprovalService.php:1088`: the record builder in `listPendingForReviewer()` adds `sourceType`, `toolId`, `toolArguments` (already redacted before persistence) and `preview`.
- New `lib/Service/ApprovalPreviewBuilder.php`: given an Approval's data, returns `{kind, tools: [{id, name, effect, reach, arguments?}], heldBecause}`. `effect` is `reads` when `readOnlyHint` is true, `deletes` when `scope` is `delete`, `sends` for a tool whose reach is external, else `changes`. It reads the catalog through `ToolRegistryFacade::listTools()` once per inbox load, not once per row.
- For non-tool approvals it reads the agent's grants and resolves them the way `ToolLoop` does (`ToolGrantResolver::resolve()`), so the list matches what the run could call.
- `src/views/ApprovalInbox.vue`: a "Details" button per row; `src/modals/ApprovalPreviewModal.vue` renders the preview (ADR-004).
- The Talk notice is written where the approval request is posted today; one line is appended.

## Decisions

- D1. Derived, not stored. The preview is built from data the Approval already holds plus the live catalog, so no schema change and no migration.
- D2. Arguments are shown as stored, which is after redaction. The preview never reads unredacted arguments.
- D3. The reviewer check stays `listPendingForReviewer()`'s own; the preview adds no new read path.

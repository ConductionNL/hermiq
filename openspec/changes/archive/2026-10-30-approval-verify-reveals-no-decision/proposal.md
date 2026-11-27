# The public approval check reveals no decision on a mismatch

## Why

`POST /api/approvals/verify` is a public route. A security trace before the beta release found that a verdict with reason `unknown` or `binding-mismatch` still carried `decidedBy`, `decidedAt` and `expiresAt` from the stored approval. Anyone who knows an approval id (approval ids travel to integriq, into tool results and task mirrors) could learn who decided it and when, for any approval type.

## What changes

- A verdict whose reason is `unknown` or `binding-mismatch` carries `decidedBy`, `decidedAt` and `expiresAt` as null. The other reasons are unchanged, because they are only reached when the caller already named the approval's own tool, binding and agent.

## Impact

- `lib/Service/Approval/ApprovalVerdictService.php`
- `tests/Unit/Service/Approval/ApprovalVerdictServiceTest.php`
- No change for integriq: it only acts on `approved: true`.

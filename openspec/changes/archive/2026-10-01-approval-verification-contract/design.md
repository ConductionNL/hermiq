# Design: approval-verification-contract

## D1. The verdict

`approved` is true only when every one holds: the approval exists; its `sourceType` is `toolcall`; its `toolId`, `binding` and `agentId` equal the request's `toolId`, `binding` and `actingAgent`; its `status` is `approved`; `decidedAt` plus the toolcall validity window (`ApprovalService::TOOLCALL_APPROVAL_TTL_SECONDS`) is in the future; `decidedBy` is a person who is not the acting agent and not the agent's own principal (`actingUser`, or the legacy `user`).

The first failing check names the `reason`, in this order: `unknown` (no approval, not a toolcall, or no binding stored), `binding-mismatch` (tool, binding or agent differ), `pending`, `rejected` (denied or overridden), `expired`, `approver-is-agent`. Otherwise `approved`.

`expiresAt` is `decidedAt` plus the window; `issuedAt` is the signing time. Canonical JSON: the verdict with keys sorted, `JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE`, which is exactly what integriq's `ApprovalVerdictVerifier::canonical()` computes.

A request that lacks a field, or carries a non-string one, is answered with HTTP 400 and no signature: there is nothing to echo.

## D2. The key

`ApprovalVerdictSigner` reads `approval_verdict_secret_key` (sensitive) and `approval_verdict_public_key` from `IAppConfig`. When either is missing or does not decode to the right length, it makes a new pair with `sodium_crypto_sign_keypair()` and stores both. A lost secret key therefore rotates the pair; verdicts signed before the rotation stop verifying, which is the safe side.

## D3. Who may call

integriq calls the endpoint from the server, without a user session, so the route is `#[PublicPage]` and `#[NoCSRFRequired]`. It is brute-force protected (`#[BruteForceProtection(action: 'hermiq_approval_verify')]`): an answer of `unknown` or `binding-mismatch` registers an attempt. To learn anything a caller needs the approval uuid and the sha256 binding of the staged batch, which only the stager holds. The verdict discloses no arguments, only the fields the caller sent and the decision.

## D4. Storing the binding

The binding reaches Hermiq in phase 1's tool result (`status: staged`, `proposal`, `tool`, `targetIds`, `binding`). After a successful `integriq.*` dispatch, `FacadeToolInvoker` hands such a result to `ApprovalService::ensurePendingApprovalForStagedBatch()`, which raises (or finds) a pending `toolcall` approval keyed by `sha256([agentId, toolId, "batch", binding])`, storing `binding`, `proposalId` and the target ids in `toolArguments`. The tool result the agent sees gains `approvalId`. Hermiq never recomputes the binding. The approval is not consumed by a verify call: integriq closes its own proposal, so one approval runs one batch once.

## D5. The acting agent

`FacadeToolInvoker::withAgentId()` adds the six integriq agent tool ids (`integriq.runSynchronization`, `replayDeadLetters`, `discardDeadLetters`, `testSynchronization`, `testSource`, `listDeadLetters`). Any `agentId` the model sends is overwritten.

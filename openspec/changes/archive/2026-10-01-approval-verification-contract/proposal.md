# Proposal: approval-verification-contract

## Why

integriq's agent tools (hermiq-ai-tooling, REQ-MCP-107) run a synchronisation, a dead-letter replay or a discard only after a person approved that exact batch in Hermiq. Ruben decided (DECISIONS rows 31 and 40, 30 Sep 2026) that integriq never reads Hermiq's approval objects: it asks Hermiq and trusts only a signed answer. Issue hermiq#1045 holds the contract. Until Hermiq answers it, integriq refuses every phase 2 call.

## What changes

- `POST /api/approvals/verify` answers `{verdict, signature}`: the verdict echoes the five request fields, says whether the approval holds, and is signed with Hermiq's Ed25519 key over its canonical JSON.
- Hermiq keeps an Ed25519 key pair, made on first use. The secret key is a sensitive app value; the public key is published as `hermiq` / `approval_verdict_public_key` (base64).
- When an `integriq.*` tool answers `status: staged` with a `binding`, Hermiq raises a pending `toolcall` approval that stores the binding and the proposal, and hands the approval id back to the agent in the tool result.
- Hermiq injects the acting agent's id as `agentId` into the arguments of the six integriq agent tools, as it does for its own memory tools.

## Out of scope

integriq's own checks (signature, echo, age, approver) live in integriq (#2421).

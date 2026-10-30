# Tasks: approval-verification-contract

Kind: code. Size M. Issue hermiq#1045 (DECISIONS rows 31 and 40). No matrix row: the contract serves integriq's hermiq-ai-tooling (REQ-MCP-107).

### Task 1: The signer and its published key
- **spec_ref**: `openspec/changes/approval-verification-contract/specs/human-approval-gate/spec.md#requirement-hermiq-answers-a-signed-verdict-on-a-toolcall-approval-req-apver-001`
- **files**: `lib/Service/Approval/ApprovalVerdictSigner.php`
- [x] Implement
- [x] Test (PHPUnit: a key pair is made once and reused; the signature verifies against the published key over integriq's canonical JSON)

### Task 2: The verdict
- **spec_ref**: `openspec/changes/approval-verification-contract/specs/human-approval-gate/spec.md#requirement-hermiq-answers-a-signed-verdict-on-a-toolcall-approval-req-apver-001`
- **files**: `lib/Service/Approval/ApprovalVerdictService.php`
- [x] Implement
- [x] Test (PHPUnit: approved, pending, rejected, expired, unknown, binding-mismatch, another agent, approver-is-agent)

### Task 3: The verify endpoint
- **spec_ref**: `openspec/changes/approval-verification-contract/specs/human-approval-gate/spec.md#requirement-hermiq-answers-a-signed-verdict-on-a-toolcall-approval-req-apver-001`
- **files**: `lib/Controller/ApprovalVerifyController.php`, `appinfo/routes.php`
- [x] Implement
- [x] Test (PHPUnit: 200 with a signed verdict, 400 on a missing field, a throttle on unknown)

### Task 4: The binding is stored when a batch is staged
- **spec_ref**: `openspec/changes/approval-verification-contract/specs/human-approval-gate/spec.md#requirement-a-staged-batch-raises-an-approval-that-keeps-its-binding-req-apver-002`
- **files**: `lib/Service/ApprovalService.php`, `lib/Service/Engine/FacadeToolInvoker.php`, `lib/Settings/hermiq_register.json`
- [x] Implement
- [x] Test (PHPUnit: the payload validates against the real Approval fragment with Opis; the tool result carries approvalId; a second staging returns the same approval)

### Task 5: The acting agent reaches integriq's tools
- **spec_ref**: `openspec/changes/approval-verification-contract/specs/human-approval-gate/spec.md#requirement-hermiq-passes-the-acting-agent-to-integriqs-agent-tools-req-apver-003`
- **files**: `lib/Service/Engine/FacadeToolInvoker.php`
- [x] Implement
- [x] Test (PHPUnit: agentId injected and a model-supplied one overwritten)

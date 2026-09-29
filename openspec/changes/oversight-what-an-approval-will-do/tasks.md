# Tasks: oversight-what-an-approval-will-do

Kind: code. Size M. Row `hermiq:ov-approval-context`.

### Task 1: The preview builder
- **spec_ref**: `openspec/changes/oversight-what-an-approval-will-do/specs/human-approval-gate/spec.md#requirement-a-reviewer-sees-what-a-held-action-will-do-req-apprev-001`
- **files**: `lib/Service/ApprovalPreviewBuilder.php`
- **acceptance_criteria**: GIVEN a toolcall Approval for a destructive tool WHEN the preview is built THEN effect is deletes or changes, reach is named and the redacted arguments are included
- [ ] Implement
- [ ] Test (PHPUnit with the real catalog entry shape)

### Task 2: The inbox record carries it
- **spec_ref**: `openspec/changes/oversight-what-an-approval-will-do/specs/human-approval-gate/spec.md#requirement-a-reviewer-sees-what-a-held-action-will-do-req-apprev-001`
- **files**: `lib/Service/ApprovalService.php`
- **acceptance_criteria**: GIVEN pending approvals WHEN the reviewer lists them THEN each record has sourceType and preview, and only the reviewer's own approvals are listed
- [ ] Implement
- [ ] Test (PHPUnit)

### Task 3: Details in the inbox
- **spec_ref**: `openspec/changes/oversight-what-an-approval-will-do/specs/human-approval-gate/spec.md#requirement-a-reviewer-sees-what-a-held-action-will-do-req-apprev-001`
- **files**: `src/views/ApprovalInbox.vue`, `src/modals/ApprovalPreviewModal.vue`, `l10n/`
- **acceptance_criteria**: GIVEN a row WHEN "Details" is chosen THEN the modal shows the tools, their effect and reach, and approve and deny work from it
- [ ] Implement
- [ ] Test (eslint, test:l10n)

### Task 4: The Talk line
- **spec_ref**: `openspec/changes/oversight-what-an-approval-will-do/specs/human-approval-gate/spec.md#requirement-the-talk-request-names-the-tool-and-its-reach-req-apprev-002`
- **files**: the Talk approval notifier
- **acceptance_criteria**: GIVEN a toolcall approval posted to Talk THEN the message names the tool and its reach
- [ ] Implement
- [ ] Test (PHPUnit on the message text)

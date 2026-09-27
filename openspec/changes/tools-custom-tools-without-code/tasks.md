# Tasks: tools-custom-tools-without-code

Kind: code. Size M. Row `hermiq:tl-custom-tool`.

## Implementation tasks

### Task 1: The CustomTool schema and its write rights
- **spec_ref**: `openspec/changes/tools-custom-tools-without-code/specs/custom-tools/spec.md#requirement-an-organisation-admin-can-define-a-custom-tool-without-code-req-custool-001`
- **files**: `lib/Settings/hermiq_register.json` (CustomTool), `lib/Service/CustomTool/CustomToolService.php`, `lib/Controller/CustomToolController.php`, `appinfo/routes.php`
- **acceptance_criteria**:
  - GIVEN an organisation admin WHEN they save a valid tool THEN it is stored in their organisation with a unique slug
  - GIVEN a non-admin WHEN they create or edit THEN it is refused
  - GIVEN an input schema with a nested object WHEN saved THEN it is refused with the allowed types named
- [ ] Implement
- [ ] Test (PHPUnit on validation and rights; Newman for admin and non-admin)

### Task 2: Descriptors in the catalogue
- **spec_ref**: `openspec/changes/tools-custom-tools-without-code/specs/custom-tools/spec.md#requirement-a-custom-tool-is-offered-under-a-two-segment-id-and-only-by-exact-grant-req-custool-002`
- **files**: `lib/Mcp/HermiqToolProvider.php` (getTools), `lib/Mcp/CustomToolDescriptors.php`
- **acceptance_criteria**:
  - GIVEN enabled tools in two organisations WHEN a user's catalogue is built THEN only their organisation's appear, as hermiq.custom_<slug>
  - GIVEN an integriq target marked read-only WHEN described THEN reach stays external
  - GIVEN a wildcard grant WHEN resolved THEN no custom tool is granted
- [ ] Implement
- [ ] Test (PHPUnit on descriptor building and grant resolution)

### Task 3: The invoker
- **spec_ref**: `openspec/changes/tools-custom-tools-without-code/specs/custom-tools/spec.md#requirement-a-custom-tool-validates-its-input-and-calls-only-through-the-flow-tool-or-integriq-req-custool-003`
- **files**: `lib/Service/CustomTool/CustomToolInvoker.php`, `lib/Mcp/HermiqToolProvider.php` (invokeTool)
- **acceptance_criteria**:
  - GIVEN bad arguments WHEN invoked THEN invalid_argument is returned before any call
  - GIVEN a flow target WHEN invoked THEN openregister.runFlow runs for that flow only, under the owner rule
  - GIVEN an integriq target WHEN invoked THEN CallService is called, the body template is rendered, and the answer is capped and delimited
- [ ] Implement
- [ ] Test (PHPUnit with the facade and CallService stubbed)

### Task 4: Governance and the run record
- **spec_ref**: `openspec/changes/tools-custom-tools-without-code/specs/custom-tools/spec.md#requirement-a-custom-tool-passes-the-same-governance-as-every-tool-req-custool-004`
- **files**: `lib/Service/Engine/FacadeToolInvoker.php` (trace extra), `lib/Service/Engine/ToolLoop.php`
- **acceptance_criteria**:
  - GIVEN a confirm classification WHEN called THEN the approval gate holds it
  - GIVEN any call WHEN traced THEN the step names the tool, its audit version and its target
- [ ] Implement
- [ ] Test (PHPUnit on the chain for a custom id)

### Task 5: The Custom tools page and editor
- **spec_ref**: `openspec/changes/tools-custom-tools-without-code/specs/custom-tools/spec.md#requirement-an-organisation-admin-can-define-a-custom-tool-without-code-req-custool-001`
- **files**: `src/manifest.json` (CustomTools index page), `src/modals/CustomToolFormModal.vue`, `src/views/McpTools.vue` (link), `src/api/customTools.js`, `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN an organisation admin WHEN they build inputs, pick a target and press Try it THEN the warning is shown and the real answer appears
  - GIVEN the editor WHEN a field uses NcSelect THEN it has an inputLabel
- [ ] Implement
- [ ] Test (Playwright under tests/e2e/spec-coverage/)

## Verification
- [ ] `openspec validate tools-custom-tools-without-code --type change --strict` passes
- [ ] PHPUnit, Newman and Playwright run once before push, exit codes read
- [ ] One live chat turn in which an agent calls a flow-target and an integriq-target custom tool

# Test Plan: tenant-model-policy

## Test Cases

### TC-1: Org-subadmin restricts organisation to local inference only
- **spec_ref**: `openspec/changes/tenant-model-policy/specs/tenant-model-policy/spec.md#requirement-per-organisation-model-policy-object`
- **type**: api
- **persona**: Noor (municipal CISO / functional admin)
- **preconditions**: An organisation with no existing `ModelPolicy`
- **steps**: `PUT /api/model-policy` (create) with `allowed: [{provider: "ollama", models: []}]`
- **expected result**: Exactly one `ModelPolicy` persists for the organisation, permitting any Ollama model and no other provider
- **test command**: /test-api

### TC-2: Organisation with no policy inherits the instance default
- **spec_ref**: `openspec/changes/tenant-model-policy/specs/tenant-model-policy/spec.md#requirement-instance-admin-fallback-policy`
- **type**: api
- **preconditions**: Organisation B has no `ModelPolicy`; an instance-wide default allows `openai` and `ollama`
- **steps**: `GET /api/model-policy/effective` as a member of organisation B
- **expected result**: The instance default is returned; `fireworks` is not among the allowed providers
- **test command**: /test-api

### TC-3: Fresh install with no policy anywhere fails closed
- **spec_ref**: `openspec/changes/tenant-model-policy/specs/tenant-model-policy/spec.md#requirement-instance-admin-fallback-policy`
- **type**: regression
- **preconditions**: No `ModelPolicy` object exists (organisation-specific or instance-wide)
- **steps**: Resolve the effective policy for any organisation
- **expected result**: Only the provider currently set in `hermiq.llm.chatProvider` is allowed; the system does not treat "no policy" as "every provider allowed"
- **test command**: /test-regression

### TC-4: Scheduled run refused for an out-of-policy provider
- **spec_ref**: `openspec/changes/tenant-model-policy/specs/tenant-model-policy/spec.md#requirement-run-time-enforcement-of-the-effective-model-policy`
- **type**: functional
- **preconditions**: Organisation's `ModelPolicy` allows only `ollama`; an `Agent` in that org has `provider: "openai"`
- **steps**: Let the agent's schedule fire (or trigger via "Run now")
- **expected result**: The run is refused before any request reaches OpenAI; the schedule's `lastStatus` is `error` with a message naming the rejected provider; the run's audit trail entry records the refusal
- **test command**: /test-functional

### TC-5: Interactive chat turn refused for an out-of-policy model
- **spec_ref**: `openspec/changes/tenant-model-policy/specs/tenant-model-policy/spec.md#requirement-run-time-enforcement-of-the-effective-model-policy`
- **type**: functional
- **preconditions**: Organisation's `ModelPolicy` allows `openai` only for `gpt-4o-mini`; an `Agent` has `model: "gpt-4o"`
- **steps**: Send a chat message to that agent
- **expected result**: The system refuses to generate a response via `gpt-4o`; the user sees a clear, generic-safe error (no leaked credentials/config)
- **test command**: /test-functional

### TC-6: Flow-triggered run respects the same policy as a schedule
- **spec_ref**: `openspec/changes/tenant-model-policy/specs/tenant-model-policy/spec.md#requirement-run-time-enforcement-of-the-effective-model-policy`
- **type**: functional
- **preconditions**: Organisation's `ModelPolicy` allows only `ollama`; an `Agent` configured with `provider: "fireworks"`
- **steps**: Trigger the agent via an OpenRegister flow event (`AgentRunRequestedEvent`)
- **expected result**: The run is refused using the same policy check as TC-4; the refusal is recorded on that run's audit entry
- **test command**: /test-functional

### TC-7: In-policy run proceeds unaffected
- **spec_ref**: `openspec/changes/tenant-model-policy/specs/tenant-model-policy/spec.md#requirement-run-time-enforcement-of-the-effective-model-policy`
- **type**: regression
- **preconditions**: Organisation's `ModelPolicy` allows `ollama` with model `qwen2.5`; an `Agent` with `provider: "ollama"`, `model: "qwen2.5"`
- **steps**: Run the agent via schedule, Run now, and interactive chat
- **expected result**: Each run proceeds exactly as before this change, with no observable extra latency beyond the single allowlist check
- **test command**: /test-regression

### TC-8: Org-subadmin cannot write another organisation's policy
- **spec_ref**: `openspec/changes/tenant-model-policy/specs/tenant-model-policy/spec.md#requirement-model-policy-authorization`
- **type**: security
- **preconditions**: Org-subadmin of organisation A; an existing `ModelPolicy` for organisation B
- **steps**: `PUT /api/model-policy/{organisation-B-policy-uuid}` as the organisation A subadmin
- **expected result**: The write is rejected (403); organisation B's policy is unchanged
- **test command**: /test-security

### TC-9: Non-admin user can read their organisation's effective policy
- **spec_ref**: `openspec/changes/tenant-model-policy/specs/tenant-model-policy/spec.md#requirement-model-policy-authorization`
- **type**: api
- **preconditions**: A regular (non-subadmin) member of organisation A with an existing `ModelPolicy`
- **steps**: `GET /api/model-policy/effective` as that user
- **expected result**: Organisation A's policy (or the instance default) is returned; no admin privilege required
- **test command**: /test-api

### TC-10: Model and Provider choices are filtered to the organisation's policy
- **spec_ref**: `openspec/changes/tenant-model-policy/specs/agent-management-ui/spec.md#requirement-create-and-configure-an-agent-mvp`
- **type**: functional
- **persona**: Priya (ZZP developer/integrator, building an agent)
- **preconditions**: Effective `ModelPolicy` allows only `ollama`
- **steps**: Open the create/edit agent form, open the Provider field, then the Model field
- **expected result**: Provider offers only `ollama`; Model is scoped to `ollama`'s allowed models (or free entry if unrestricted) — no free-text provider entry
- **test command**: /test-functional

### TC-11: Saving an out-of-policy provider/model is rejected
- **spec_ref**: `openspec/changes/tenant-model-policy/specs/agent-management-ui/spec.md#requirement-create-and-configure-an-agent-mvp`
- **type**: functional
- **preconditions**: An agent form state (e.g. via a raw API call bypassing the picker) sets a provider/model outside the effective policy
- **steps**: Attempt to save
- **expected result**: The save is rejected client- and server-side; the agent's previously-saved configuration remains unchanged
- **test command**: /test-functional

### TC-12: Model policy fields meet WCAG 2.1 AA
- **spec_ref**: `openspec/changes/tenant-model-policy/specs/agent-management-ui/spec.md#requirement-create-and-configure-an-agent-mvp`
- **type**: accessibility
- **preconditions**: Agent form rendered with the new Provider/Model `NcSelect`s
- **steps**: Run an accessibility audit against the form
- **expected result**: Both `NcSelect`s carry `input-label`; no new WCAG violations introduced
- **test command**: /test-accessibility

## Coverage Summary

- `tenant-model-policy#REQ` per-organisation model policy object — covered (TC-1)
- `tenant-model-policy#REQ` instance-admin fallback policy — covered (TC-2, TC-3)
- `tenant-model-policy#REQ` run-time enforcement — covered (TC-4, TC-5, TC-6, TC-7)
- `tenant-model-policy#REQ` model policy authorization — covered (TC-8, TC-9)
- `multi-tenant-ops#REQ` per-tenant sovereignty (enforced) — covered (TC-4, TC-6)
- `agent-management-ui#REQ` create and configure an agent (filtered picker) — covered (TC-10, TC-11, TC-12)

## Out of Scope

- Content-based/data-classification routing is not implemented in this change, so no test
  case exercises per-message provider routing based on message content.
- Performance benchmarking of the additional OpenRegister read beyond a single-request
  smoke check (TC-7's "no observable extra latency") is not a dedicated load test.

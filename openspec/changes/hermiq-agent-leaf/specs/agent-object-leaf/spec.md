# agent-object-leaf (delta)

This change adds a reusable OpenRegister integration leaf that surfaces Hermiq
agents on any object in any OpenBuild app: an Agent tab/widget (chat plus run
history), a user-initiated object-permission-scoped run-on-object endpoint that
rides the existing governed agent-run recipe, a declarative bounded-context
allowlist, and the manifest agent-action contract.

## ADDED Requirements

### Requirement: Agent integration leaf registration
Hermiq MUST register an OpenRegister integration provider with id `hermiq-agent`
that contributes both a `tab` component and a `widget` component through the
integration registry, so an Agent surface appears on any OpenRegister object in
any OpenBuild app that renders the integration registry. Registration MUST use
the load-order-safe registration hook provided by the OpenRegister
`app-leaf-provider-registration` change and MUST be gated on Hermiq being
installed and enabled for the user, so absence hides the surface rather than
rendering a broken or erroring tab.

#### Scenario: Object detail page in a consuming app shows the Agent tab
- **GIVEN** Hermiq is enabled and an OpenBuild app renders an OpenRegister object detail page
- **WHEN** the integration registry is read for that object
- **THEN** the `hermiq-agent` provider MUST be present and its `tab` and `widget` MUST render on the object

#### Scenario: Hermiq disabled hides the surface
- **GIVEN** Hermiq is not enabled for the user
- **WHEN** an object detail page is rendered
- **THEN** the Agent tab MUST NOT appear, and no error MUST be shown for its absence

### Requirement: Object-scoped agent chat reuses the tool-free surface
The Agent leaf's chat MUST reuse the existing tool-free conversational endpoint
`POST /api/assistant/converse` and MUST NOT introduce any new LLM, prompt, or
tool-execution logic. The chat MUST forward only the bounded object context
built from the schema context allowlist as the conversation context, and MUST
carry the object identity so follow-up turns stay grounded on the same object.

#### Scenario: User chats with an agent about the current object
- **GIVEN** an authenticated user viewing an object with the Agent tab open
- **WHEN** the user sends a message
- **THEN** the leaf MUST call `converse` with the bounded object context and render the reply, invoking no tool and writing no object field

#### Scenario: Chat forwards no unlisted field
- **GIVEN** a schema whose context allowlist names a subset of its properties
- **WHEN** the leaf builds the chat context for an object of that schema
- **THEN** only the allowlisted properties MUST be forwarded, and unlisted properties MUST NOT be sent

### Requirement: Per-object agent run history and status
The Agent leaf MUST display the agent-run history for the current object read
from OpenRegister's audit trail, showing each run's outcome status among ok,
error, skipped_killswitch, skipped_budget, and awaiting_approval. The leaf MUST
be render-only for this data: it MUST read the audit trail and MUST NOT write
run records itself.

#### Scenario: A completed run appears in history
- **GIVEN** a governed agent run has executed against an object and written its per-run audit entry
- **WHEN** the user opens the Agent tab on that object
- **THEN** the leaf MUST list that run with its recorded status and summary

#### Scenario: A gated run shows its gate outcome
- **GIVEN** a run was blocked by the kill-switch, the budget hard cap, or the approval gate
- **WHEN** the user views run history
- **THEN** the corresponding skipped_killswitch, skipped_budget, or awaiting_approval status MUST be shown

### Requirement: Scoped run-on-object endpoint
Hermiq MUST provide `POST /api/agents/{id}/run-on-object` declared
`#[NoAdminRequired]`, accepting a body of `register`, `schema`, `objectId`, and
optional `resultField`, `skill`, and `prompt`. On success it MUST dispatch a
governed agent run for agent `{id}` against the named object and return 202
Accepted with a correlation id. It MUST NOT execute the run inline and MUST NOT
return the run result synchronously, because v1 dispatch mode is async only.

#### Scenario: Authorized user starts a run on an object
- **GIVEN** an authenticated user who can read object O and a valid agent id A
- **WHEN** the user POSTs register, schema, and objectId for O to `/api/agents/A/run-on-object`
- **THEN** the endpoint MUST dispatch a governed run and return 202 with a correlation id

#### Scenario: Missing required body field is rejected
- **GIVEN** an authenticated user
- **WHEN** the user POSTs a body missing register, schema, or objectId
- **THEN** the endpoint MUST return 400 and MUST NOT dispatch a run

### Requirement: Run-on-object authorization is object-permission-scoped
The run-on-object endpoint MUST authorize the request against the triggering
object's own OpenRegister permissions in the caller's RBAC scope, and MUST NOT
be admin-gated. A caller who cannot read the named object MUST receive 404,
fail-closed and indistinguishable from a nonexistent object, so the endpoint
cannot be used to probe for objects the caller may not see.

#### Scenario: Non-admin with object access succeeds
- **GIVEN** a non-admin user who is permitted to read object O
- **WHEN** the user calls run-on-object for O
- **THEN** the endpoint MUST NOT reject the request for lack of admin rights and MUST proceed to dispatch

#### Scenario: User without object access is refused
- **GIVEN** a user who cannot read object O
- **WHEN** the user calls run-on-object for O
- **THEN** the endpoint MUST return 404 and MUST NOT dispatch a run

### Requirement: Run-on-object rides the existing governed recipe
The run-on-object endpoint MUST start the run by dispatching the existing typed
OpenRegister agent-run event and MUST NOT call the flow-agent run service
directly or re-implement any run logic, so the run passes through the same
kill-switch, budget hard-cap, human-approval, and redacted-audit rails as a
flow-triggered, scheduled, or webhook-triggered run. The endpoint MUST derive
whether approval is required from the agent's own policy and MUST NOT accept a
request-body field that downgrades an approval requirement.

#### Scenario: Dispatched run is governed identically to a flow run
- **GIVEN** an agent whose organisation is under the kill-switch or over its budget hard cap
- **WHEN** a run-on-object request dispatches its run
- **THEN** the governed job MUST skip execution and record the matching skipped status, exactly as a flow-triggered run would

#### Scenario: Caller cannot bypass the approval gate
- **GIVEN** an agent whose policy requires human approval
- **WHEN** a run-on-object request is dispatched for it
- **THEN** the run MUST enter the approval gate regardless of any request-body value

### Requirement: Declarative bounded agent-context allowlist
Hermiq's agent leaf and run-on-object endpoint MUST build the forwarded object
context from ONLY the properties named by a schema's `x-openregister-agent-context`
allowlist, and MUST fail closed: when the allowlist is absent or empty the
context MUST be empty, never the whole object, and a property named in the
allowlist but not present on the instance MUST be omitted rather than error. A
schema declares this allowlist as a list of property names an agent surface may
read from an object of that schema.

#### Scenario: Only allowlisted fields reach the agent
- **GIVEN** a schema allowlist naming title, status, and description
- **WHEN** a context is built for an object that also holds an unlisted confidential field
- **THEN** the context MUST contain only title, status, and description and MUST NOT contain the confidential field

#### Scenario: No allowlist yields an empty context
- **GIVEN** a schema with no `x-openregister-agent-context` declaration
- **WHEN** a context is built for an object of that schema
- **THEN** the context MUST be empty and no object property MUST be forwarded

### Requirement: Manifest agent action-type contract
The system MUST support triggering a run-on-object run from an OpenBuild
manifest action. In the interim an `api-call` action targeting
`/api/agents/{id}/run-on-object` with a token-interpolated body of register,
schema, and objectId MUST be sufficient to start a run with no nextcloud-vue
change. The end-state discriminated `agent` action-type, authored in the
companion nextcloud-vue change, MUST target the same endpoint and MUST treat
the call as asynchronous, surfacing the queued or running state rather than a
synchronous result.

#### Scenario: Interim api-call action starts a run
- **GIVEN** a manifest with an `api-call` action posting register, schema, and objectId to run-on-object
- **WHEN** a user triggers that action on an object
- **THEN** a governed run MUST be dispatched using only the existing manifest action-types

#### Scenario: End-state agent action targets the same endpoint
- **GIVEN** the companion nextcloud-vue `agent` action-type is available
- **WHEN** a manifest declares an `agent` action for an object
- **THEN** it MUST dispatch to the same run-on-object endpoint and MUST present the run as asynchronous

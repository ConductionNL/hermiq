# Contract: hydra-console-agent-leaves

Hermiq is the producer of the interfaces below. After the flows-first pivot there is
**no new Hermiq interface at all**: no new endpoint, no new MCP tool. What this document
fixes is (a) the shape of the one existing endpoint the console calls, restated because
the consumer lives in another repository, (b) the grant string grammar, which is now a
cross-repo interface because the seeded agent's grants are authored against a flow the
hydra repo owns, and (c) the two contracts Hermiq now *consumes* rather than produces.

## Consumers

- `hydra` (the `hydra-console` OpenBuild virtual app): calls
  `POST /apps/hermiq/api/agents/{id}/run-on-object` from manifest `api-call` actions on
  pipeline object detail pages, and renders the `hermiq-agent` leaf on its detail pages and
  dashboards (`hydra-console-pages`: *Detail pages reserve a slot for the hermiq agent
  leaf*). Reads run outcomes back from OpenRegister's audit trail and from the object's
  `resultField`.
- `hydra` (the orchestrator itself, indirectly): observes the effect of the command flow as
  a label change on a forge issue, which its own `stage:state` polling already treats as a
  command.
- Any other Hermiq agent in the fleet: **unaffected**. The catalog gains no tool, so no
  agent's resolved tool set changes.

## Producer surfaces

### `POST /apps/hermiq/api/agents/{id}/run-on-object`

Existing endpoint (`agent-object-leaf`), **unchanged in shape** by this change. Restated
because it is the console's only write path into Hermiq and the consumer is cross-repo.

**Auth**: Nextcloud session, `#[NoAdminRequired]`. Authorization is object-permission-scoped:
the object named in the body is resolved in the caller's own OpenRegister RBAC scope.

**Request:**
```json
{
  "register": "hydra",
  "schema": "finding",
  "objectId": "00000000-0000-0000-0000-000000000000",
  "resultField": "triageNote"
}
```

`register`, `schema` and `objectId` are required. `resultField`, `skill` and `prompt` are
optional. `requiresApproval` is derived from the agent's own policy and MUST NOT be accepted
from the body.

**Response (202):**
```json
{
  "status": "queued",
  "correlationId": "00000000-0000-0000-0000-000000000000"
}
```

The call is asynchronous. There is no synchronous run mode. The result lands on the object's
`resultField` and as an OpenRegister audit entry; the leaf's run widget reads both.

**Errors:**
| Code | Condition |
|------|-----------|
| 400  | `register`, `schema` or `objectId` missing from the body |
| 401  | No Nextcloud session |
| 404  | Agent `{id}` does not exist, **or** the caller cannot read the named object |

### The argument-scoped grant string

`Agent.tools` remains a `string[]` (ADR-035 Decision 4). This change extends the *meaning*
of an entry with an argument-scoped form: an exact tool id narrowed by one or more argument
constraints, each pinning an argument to a single literal value or to a closed set of
permitted values.

This is a cross-repo interface because the seeded triage agent's command grant names a
`flowId` the hydra repo authors and a label vocabulary hydra's state machine defines. The
**semantics** are fixed here; the literal separator syntax is a design decision recorded in
`design.md`.

Semantics that consumers may rely on:

- An argument-scoped grant resolves to the **same** catalog tool id as the bare exact-id
  grant. No second catalog entry is created and no descriptor is rewritten.
- Constraints are enforced **before** dispatch. A non-conforming call never reaches the
  facade and never reaches the flow engine.
- A pinned argument must match exactly; a constrained argument must be a member of the
  declared set; an unmentioned argument is left to the tool's own validation.
- Narrowing never changes classification: a write/destructive tool stays write/destructive
  for default-deny, dry-run and approval purposes.
- An unconstrained exact-id grant for a multi-target tool remains legal and means *every*
  target.

**Refusal result** (structured, never thrown):
```json
{
  "ok": false,
  "error": "grant_constraint_violated",
  "argument": "label",
  "message": "Argument 'label' is not permitted by this agent's grant."
}
```

## Consumed surfaces

These are contracts Hermiq now *depends on*. They are listed because the pivot moved the
write out of this repo, and a consumer contract that is not written down is a contract that
drifts.

### `openregister.runFlow` (OpenRegister, `FlowMcpToolProvider`)

The tool the command grant narrows. Relied-upon shape:

```json
{
  "flowId": "00000000-0000-0000-0000-000000000000",
  "uuid": "00000000-0000-0000-0000-000000000000",
  "register": "hydra",
  "schema": "finding"
}
```

Returns `{ "runUuid": "...", "status": "queued", "queued": true }`. Queueing, not inline
execution, is load-bearing: the agent's turn must not block on a flow.

**Two gaps this change depends on being closed, and by whom:**

| Gap | Owner | Hermiq's position |
|-----|-------|-------------------|
| The queued run carries no acting user (`triggeredBy` null) | Hermiq enforces the owner at its own dispatch chokepoint; a durable fix belongs in OpenRegister | Refuse the invocation rather than queue an unattributed run |
| No parameter channel beyond the subject triple | OpenRegister, if the label must travel with the call; otherwise the command flow derives it from the subject object | Specified either way — the grant's closed value set applies to whichever argument carries the label |

### The command endpoint / node (hydra + openconnector)

Owned by `hydra-console-openbuild-app`'s `hydra-console-commands` capability
(*The command endpoint performs the forge write server-side*). Hermiq relies on it to:

- perform the forge label write server-side with a brokered credential, never in the browser
  and never from Hermiq;
- reproduce the repository's stage-transition helper contract — target label added, every
  contradicting queued/running/pass/fail label stripped, in one atomic write;
- **validate the label against hydra's closed vocabulary independently**, because enforcing
  it at Hermiq's grant does not relieve the write path of being the last line.

**Not built yet.** OpenConnector registers no MCP tool provider and contributes no flow node
or resolver today. Until it does, the seeded triage flow terminates having recorded its
proposed label and writes nothing.

## Error Codes

| Code | Meaning | Condition |
|------|---------|-----------|
| 400 | Bad request | run-on-object body missing a required field |
| 401 | Unauthenticated | No Nextcloud session |
| 404 | Not found (fail-closed) | Unknown agent, or an object the caller may not read — deliberately indistinguishable, so the endpoint cannot be used to probe for objects |
| 202 | Accepted | Run dispatched; outcome arrives asynchronously |
| `grant_constraint_violated` | Refused command | An argument outside the grant's pinned value or closed set |
| `owner_unresolved` | Refused command | No owning NC UID could be resolved for an agent-queued flow run |

## Versioning

Both surfaces are versioned by the Hermiq app version in `appinfo/info.xml`; neither carries
an independent version segment. `run-on-object` is **unchanged** in this change — no field is
added, removed or re-typed, so a console built against the pre-change endpoint keeps working.
The grant grammar is **additive**: every existing grant string keeps its current meaning, and
no existing agent's resolved tool set changes.

Backward-compatibility guarantee: the response envelope of `run-on-object` (`status`,
`correlationId`) and its 202-async semantics MUST NOT change without a coordinated change in
the hydra repo, because the console's `api-call` action reads them.

## Breaking Change Policy

- The endpoint's request/response shape and its 202-async contract are frozen for the
  console's lifetime. A change to either requires a paired change in the hydra repo, landed
  first or simultaneously.
- **Removing or weakening argument-constraint enforcement is a security-breaking change.** It
  would silently widen every argument-scoped grant from one target to all of them. Such a
  change requires an explicit spec delta, never a quiet resolver edit.
- Narrowing a grant's value set is non-breaking (it can only refuse more). Widening it is
  breaking in the security sense and must be justified against the prompt-injection rationale
  in `design.md`.
- **Adding a Hermiq tool that writes to a forge is breaking against `nc-native-tools`.** That
  capability's remote-write rule now says so explicitly; a bespoke tool needs a spec delta
  arguing against it, not a new descriptor.
- OpenSpec cannot gate a cross-repo dependency mechanically. The coordination is a human
  contract: hydra's register and console changes land before this one is verified live.

## SLA

- `run-on-object` returns 202 within normal Nextcloud request latency because it only
  dispatches; it never waits on an LLM. It MUST NOT run the agent inline.
- Run completion has no latency guarantee — it is a background job subject to the
  kill-switch, budget hard cap and approval gate, any of which may hold or skip it
  indefinitely. Consumers MUST treat "queued" as terminal for UI purposes.
- A command invocation returns as soon as the flow run is queued (or refused). The forge
  write's latency is the command endpoint's concern, not Hermiq's.
- Constraint refusal is synchronous and costs no network call, so a refused command is
  strictly faster than an accepted one.
- Availability follows the host Nextcloud. Hermiq adds no external dependency of its own; a
  forge outage degrades the command endpoint and is invisible to Hermiq beyond a failed flow
  run.

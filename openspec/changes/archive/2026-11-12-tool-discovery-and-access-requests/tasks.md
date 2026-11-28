# Tasks

## 1. Discovery that sees past the grant

- [x] Add a `listAvailableTools` meta-tool returning `{ id, description, app, reach, held }`
- [x] Scope the listing to apps the ACTING USER can access
- [x] Return metadata only — nothing the dispatcher would accept

Acceptance criteria:
- `searchTools` today is backed by `ToolSearchService`, which holds *this run's resolved, grant-filtered, default-denied* set — so it cannot answer "does this exist elsewhere". Assert the new tool finds a tool the agent does NOT hold.
- ⚠️ Scope to the user, NOT to the agent's grants (seeing past the grant is the point) and NOT to the whole instance (the catalogue names every installed app). This is the requirement most likely to be quietly dropped: the unscoped version is easier and looks identical in a demo.
- Negative control: call a discovered-but-ungranted tool in the same run and assert it is refused exactly as an unknown tool is.

## 2. Requesting, without granting

- [x] Declare an access-request schema with a `pending → granted|refused` lifecycle
- [x] Bump `info.version` in the same commit
- [x] Add a `requestToolAccess` meta-tool that raises a request and returns pending
- [x] Bound requests per agent per interval; never duplicate a pending (agent, tool); keep a refusal refused

Acceptance criteria:
- Without the `info.version` bump the import is SKIPPED on every existing install, silently.
- A raised request MUST leave `Agent.tools` byte-identical. Assert the grants before and after.
- An agent that can ask can ask repeatedly; the failure mode is a wildcard grant issued to stop the asking, which is the pressure that produced the legacy default this programme removed.

## 3. Only the owner decides

- [x] Resolve requests only for `Agent.owner`; refuse everyone else without revealing existence
- [x] Apply a grant by writing `Agent.tools` — enforcement stays `ToolGrantResolver`, unchanged

Acceptance criteria:
- Verify FOUR ways: owner grants → allowed; non-owner grants → refused; agent self-grants → impossible; grants unchanged after every refusal.
- ⚠️ This constrains THIS surface only. An administrator can still edit `Agent.tools` directly, and the spec does not claim otherwise — do not add a check that breaks admin editing in the name of this requirement.

## 4. Make the grant visible

- [x] Notify the owner on request raised and on grant, naming tool, app and read/write reach
- [ ] Raise an alert on the agent when its capability changes
- [ ] Show the request's facts beside the agent's justification, marking the justification agent-authored

Acceptance criteria:
- A grant visible only by re-reading `Agent.tools` is how capability drifts from belief: 89% of agents were receiving the whole catalogue and nothing on the agent showed it.
- ⚠️ The justification is model-authored text aimed at the human holding the permission. The mitigation is presentation, not trust — assert the tool identity and reach are visible without expanding anything.


---

## Implementation notes (2026-08-17)

**Built and proven** except the approval UI (task 4's surface) — the API exists,
the screen does not. Grant via
`POST /api/agents/{agentId}/tool-access-requests/{requestId}` with
`{"decision":"granted"|"refused"}`.

Measured on the dev instance:

| Check | Result |
|---|---|
| Discovery finds ungranted tools | 4 pipelinq lead tools, none held, reach correct |
| Agent asks only for what it needs | requested the 2 read tools; DECLINED `createLead` ("quotation is read-only") |
| A request is not a grant | 2 pending records; `Agent.tools` byte-identical |
| Non-owner cannot grant | **404**, same answer as a missing request |
| Owner can grant | 200; both ids appended to `Agent.tools` |

⚠️ Two defects this work exposed, both fixed in the same commit:
- **The UI shows underscored ids; the resolver matched only dotted ones**, so a
  grant copied from the agent editor resolved to NOTHING — and silently, because
  `resolvesToNothing()` only fires when every grant misses.
- **The model cannot know its own uuid**, so `agentId` read from tool arguments
  was always empty on the governed MCP transport. The run token binds the agent;
  `McpRunController` now stamps it in, overwriting rather than defaulting so one
  agent cannot act as another.

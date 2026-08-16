# Tasks

## 1. Discovery that sees past the grant

- [ ] Add a `listAvailableTools` meta-tool returning `{ id, description, app, reach, held }`
- [ ] Scope the listing to apps the ACTING USER can access
- [ ] Return metadata only — nothing the dispatcher would accept

Acceptance criteria:
- `searchTools` today is backed by `ToolSearchService`, which holds *this run's resolved, grant-filtered, default-denied* set — so it cannot answer "does this exist elsewhere". Assert the new tool finds a tool the agent does NOT hold.
- ⚠️ Scope to the user, NOT to the agent's grants (seeing past the grant is the point) and NOT to the whole instance (the catalogue names every installed app). This is the requirement most likely to be quietly dropped: the unscoped version is easier and looks identical in a demo.
- Negative control: call a discovered-but-ungranted tool in the same run and assert it is refused exactly as an unknown tool is.

## 2. Requesting, without granting

- [ ] Declare an access-request schema with a `pending → granted|refused` lifecycle
- [ ] Bump `info.version` in the same commit
- [ ] Add a `requestToolAccess` meta-tool that raises a request and returns pending
- [ ] Bound requests per agent per interval; never duplicate a pending (agent, tool); keep a refusal refused

Acceptance criteria:
- Without the `info.version` bump the import is SKIPPED on every existing install, silently.
- A raised request MUST leave `Agent.tools` byte-identical. Assert the grants before and after.
- An agent that can ask can ask repeatedly; the failure mode is a wildcard grant issued to stop the asking, which is the pressure that produced the legacy default this programme removed.

## 3. Only the owner decides

- [ ] Resolve requests only for `Agent.owner`; refuse everyone else without revealing existence
- [ ] Apply a grant by writing `Agent.tools` — enforcement stays `ToolGrantResolver`, unchanged

Acceptance criteria:
- Verify FOUR ways: owner grants → allowed; non-owner grants → refused; agent self-grants → impossible; grants unchanged after every refusal.
- ⚠️ This constrains THIS surface only. An administrator can still edit `Agent.tools` directly, and the spec does not claim otherwise — do not add a check that breaks admin editing in the name of this requirement.

## 4. Make the grant visible

- [ ] Notify the owner on request raised and on grant, naming tool, app and read/write reach
- [ ] Raise an alert on the agent when its capability changes
- [ ] Show the request's facts beside the agent's justification, marking the justification agent-authored

Acceptance criteria:
- A grant visible only by re-reading `Agent.tools` is how capability drifts from belief: 89% of agents were receiving the whole catalogue and nothing on the agent showed it.
- ⚠️ The justification is model-authored text aimed at the human holding the permission. The mitigation is presentation, not trust — assert the tool identity and reach are visible without expanding anything.

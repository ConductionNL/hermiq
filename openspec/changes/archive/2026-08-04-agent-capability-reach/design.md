# Design: agent-capability-reach

## Context

Hermiq's tool governance has exactly one risk axis today. `ToolGrantResolver::isWriteOrDestructive()`
(`lib/Service/Engine/ToolGrantResolver.php:638`) answers "is this dangerous?" from the descriptor's
`scope`, then `destructiveHint`, then `readOnlyHint`, then the ADR-063 verb suffix, then fail-closed.
`FacadeToolInvoker::requiresApprovalGate()` (`lib/Service/Engine/FacadeToolInvoker.php:955`) reuses
the same call to decide whether an invocation routes to the human-approval gate.

That answer is a **data verb**. It is the right question for "may this agent write?" and the wrong
question for "how far does the damage travel?". The live catalogue makes the mismatch concrete:

- `hermiq.forgetMemory` declares `scope: delete` and `destructiveHint: true`, yet
  `MemoryService::forgetEntry()` is a soft delete of the agent's own memory — reversible, invisible
  to anyone else.
- `hermiq.sendMail` declares `scope: create`, yet `IMailer::send()`
  (`lib/Mcp/HermiqToolProvider.php:747-764`) is irreversible and third-party-visible.
- `hermiq.webSearch` and `hermiq.webFetch` declare `scope: read`, yet both make outbound HTTP
  requests — `webFetch` to a **caller-supplied URL**. They are today ungated *and* included in the
  "all discovered tools" set an empty `Agent.tools` resolves to.

Constraints this design works inside:

- `Agent.tools` is frozen as `string[]` by ADR-035 Decision 4. **Verified**: `lib/Settings/hermiq_register.json`,
  `components.schemas.Agent` v0.4.0, `properties.tools` is `{"type":"array","items":{"type":"string"}}`.
  Every new syntax must ride inside those strings. **No OpenRegister schema change is needed for the
  reach axis or the waiver grammar.** (A schema change IS needed for the owner-only fix — see
  "The owner-only finding" below — but that is a different change.)
- OpenRegister RBAC stays the sole authoritative invoke-time boundary. Reach, like the existing
  hints, may only ever NARROW.
- ADR-023 Rule 1: data RBAC is OpenRegister's job; apps never roll their own.
- ADR-004: governance is recorded in OpenRegister's `AuditTrail`.

## Goals / Non-Goals

### Goals

- Give every tool a declared blast radius that an operator can read at a glance and the engine can
  act on.
- Close the egress hole: the two tools that leave the instance stop being free.
- Make "run this narrow thing unattended" expressible, owner-only, audited and strictly narrowing.
- Document the grant model for the first time.

### Non-Goals

- Not replacing `scope`. The two axes answer different questions and both stay.
- Not relaxing anything. Reach is purely additive in this change (see Decision 3).
- Not the Mail ladder, not the widget, not the Agent-schema authorization block — all chained.
- Not an upstream OpenRegister annotation key. Reach for derived tools is inferred Hermiq-side.

## Decisions

### Decision 1: `reach` is a descriptor key, not a computed property of the id

**Chosen:** every entry in `HermiqToolProvider::TOOL_DESCRIPTORS` gains a `reach` key, sitting
alongside `scope`/`readOnlyHint`/`destructiveHint`. A new pure `ToolReachResolver` owns the
vocabulary, the ordering, and the resolution rule: declared key wins; otherwise infer from a
3-segment ADR-063 id's verb; otherwise `external`.

**Why:** this is exactly the shape `scope` already has, and it is the shape that lets a tool author
state a fact only they know. `webFetch` and `readFile` are both `scope: read`; no rule over the id
text can tell them apart. A computed-only design would have to hardcode a per-id table in the
resolver, which is the "rule is code, inputs are declarative" line ADR-031 draws — and it would put
the fact about a tool somewhere other than the tool.

**Alternative considered — overload `destructiveHint`.** Rejected: it is a boolean, it already means
something else, and OpenRegister forwards it from upstream providers. Widening its meaning would make
an upstream `destructiveHint: true` silently claim an egress property it never asserted.

**Alternative considered — a separate per-agent policy object.** Rejected: it separates the fact from
the tool, needs its own schema, and goes stale the moment a tool changes.

### Decision 2: reach means blast radius of EFFECT and DISCLOSURE, not provenance of bytes read

The vocabulary is closed and ordered. `reach` declares the widest set of principals a successful
invocation can **affect** or **disclose to**:

| value | meaning | worked example |
|---|---|---|
| `self` | only the invoking agent's own memory/state | `hermiq.rememberMemory` writes an entry only this agent recalls |
| `user` | only the acting user's own data and permission set; no other principal observes an effect | `hermiq.readFile` reads the acting user's own home folder |
| `instance` | other users of this Nextcloud can observe the effect | `{app}.{schema}.create` writes an object into a register other users read |
| `external` | the effect, or the data, leaves this Nextcloud | `hermiq.webFetch` issues an HTTP request to a caller-supplied URL |

The "effect and disclosure, not provenance" line is the load-bearing part, and it is what keeps this
design from gating the whole catalogue. `hermiq.searchContacts` can surface *other users'* directory
entries from the system addressbook, but reading them changes nothing and tells nobody — the acting
user could already see them. It is `user`. By the same rule OpenRegister's `search`/`get` are `user`
(RBAC-bounded reads with no cross-user effect) while `create`/`update`/`delete` are `instance`
(writes into shared, multi-user storage).

Without this rule the honest-looking alternative — "reach = whose data can this touch" — classifies
every OpenRegister read as `instance`, which under Decision 3 would strip every read tool from every
agent with an empty `Agent.tools`. That is a fleet-wide outage dressed as a security improvement.

### Decision 3: gating is the UNION of today's rule and the reach rule — reach may only ADD

```
gated  ==  isWriteOrDestructive(id, descriptor)   OR   reach(id, descriptor) >= instance
```

**Why not replace.** The tempting reading of "CRUD is the wrong risk axis" is that reach should
*supersede* scope, which would ungate `hermiq.forgetMemory` (`delete`, but `self` and reversible).
That is precisely a tool becoming **more permissive because its reach is low**, which is the one
outcome this design is required not to produce. Existing `destructiveHint`/`readOnlyHint` behaviour
must not regress. So the two rules compose with OR, and reach's job is to catch what scope misses,
not to overrule it.

The consequence is recorded honestly rather than hidden: `forgetMemory` stays gated even though this
design now has the vocabulary to explain why it need not be. Whether a low reach should ever relax a
write verb is carried as an Open Question on the proposal, not decided by side effect.

**Net behavioural delta** — the full list, so a reviewer can check it rather than trust it:

| tool | scope | reach | gated before | gated after |
|---|---|---|---|---|
| `hermiq.listFiles` | read | `user` | no | no |
| `hermiq.readFile` | read | `user` | no | no |
| `hermiq.searchContacts` | read | `user` | no | no |
| `hermiq.listCalendarEvents` | read | `user` | no | no |
| `hermiq.listDeckBoards` | read | `user` | no | no |
| `hermiq.searchTools` | read | `self` | no | no |
| `hermiq.recallMemory` | read | `self` | no | no |
| `{app}.{schema}.search` / `.get` | read | `user` | no | no |
| `hermiq.webSearch` | read | `external` | **no** | **YES — the change** |
| `hermiq.webFetch` | read | `external` | **no** | **YES — the change** |
| `hermiq.rememberMemory` | create | `self` | yes | yes |
| `hermiq.forgetMemory` | delete | `self` | yes | yes |
| `hermiq.recommendCourses` | update | `user` | yes | yes |
| `hermiq.delegateAgent` | create | `instance` | yes | yes |
| `hermiq.sendMail` | create | `external` | yes | yes |
| `{app}.{schema}.create` / `.update` / `.delete` | write | `instance` | yes | yes |
| hint-less, non-3-segment curated id | — | `external` (fail closed) | yes | yes |

Two tools change. Both are egress. That is the whole delta, and it is the point.

### Decision 4: fail closed to `external`, never to `self`

A descriptor declaring no `reach`, or a value outside the closed vocabulary, resolves to `external`.
`agent-tool-governance` already establishes this posture — "a hint-less curated tool fails closed" —
and the reasoning is identical: an omitted annotation is exactly the case an attacker or a careless
author produces, and it is the case where nobody has asserted anything. Defaulting to `self` would
make silence the most permissive possible declaration.

This also means the inference for derived ids is a *narrowing* of the default, not a widening: a
3-segment `{app}.{schema}.{verb}` id with a verb in the ADR-063 closed set gets `user` or `instance`;
anything else stays `external`.

### Decision 5: `#noapproval` is a fragment, and the fragment is stripped FIRST

Syntax: `{toolId}[?{constraints}][#noapproval]`, e.g.
`hermiq.mail.send?to=in:user@example.com#noapproval`.

**Why a fragment rather than a sixth grant form.** `ToolGrantResolver` documents `#` as deliberately
unused: the design chose `?` over a `#` fragment for argument constraints specifically so the two
constraint kinds stay distinguishable in a diff (`ToolGrantResolver.php:148-155`). That reservation
is what makes `#` available now for the *other* kind of modifier — one that changes nothing about
which tool or which arguments, only about whether a human is asked. Query = "which invocations does
this grant cover"; fragment = "how is that grant supervised". A reader scanning a grant list can tell
them apart without parsing.

**The parse-order trap, stated so it is not rediscovered in review.** `splitGrant()`
(`ToolGrantResolver.php:497`) splits on the FIRST `?` and hands everything after it to
`parseConstraints()`. If the fragment were stripped after that split,
`…?to=in:user@example.com#noapproval` would parse as a closed set whose last member is the literal
`user@example.com#noapproval` — a constraint nothing can satisfy, silently disabling the grant. And
on a grant with no `?`, `hermiq.webFetch#noapproval` would be passed through `expandGrant()` verbatim
as an exact id, matching no catalogue entry. Both failures are silent. **The fragment MUST be split
off before any other parsing**, and this is specified normatively rather than left to the
implementation.

### Decision 6: the waiver suppresses the gate and nothing else

The waiver is consulted in exactly one place — `requiresApprovalGate()` — and only **after** grant
expansion has decided the tool is in the resolved set and **after** `constraintViolationFor()` has
decided the arguments conform. Structurally there is no seam through which it can widen anything:

- A waiver on a tool the agent was never granted does not add it to the resolved set. `expandGrant()`
  never sees the fragment, and an ungranted tool is refused before the gate is reached.
- A waiver alongside argument constraints does not relax them. `argumentConstraints()` is built from
  the constraint query, which the fragment is not part of; the violation check runs first and refuses
  independently.
- A waiver does not affect OpenRegister RBAC, which is downstream of everything here.

This is written as its own spec requirement with a scenario per failure mode, because "it can only
narrow" is the kind of property that is true in the first implementation and quietly false in the
third.

### Decision 7: `requiresApprovalGate()` must be given the descriptor

Today `FacadeToolInvoker::requiresApprovalGate()` calls
`ToolGrantResolver::isWriteOrDestructive(id: $toolId)` with **no second argument**
(`FacadeToolInvoker.php:962`). The descriptor-hint branch of that method is therefore dead on the
approval path: a 3-segment derived id whose descriptor declares `destructiveHint: true` but whose
verb reads `get` is classified read and is **not** gated, even though `applyDefaultDeny()` — which
does pass the descriptor — classifies the same id write/destructive.

That is a pre-existing hint bypass on the gate, discovered while wiring reach. Reach needs the
descriptor anyway, so this change threads it through and the bypass closes as a side effect. It is
called out here so the fix is reviewed as a security change rather than as plumbing.

### Decision 8: `hermiq.delegateAgent` cannot launder reach

`delegateAgent` is `instance` — it starts another agent's turn on this instance. But the callee may
hold `external` grants, so a naive per-tool reading would let an agent reach the outside world by
proxy while every tool it personally holds is `user` or below.

The rule: a delegation's **effective reach is the maximum** of `delegateAgent`'s own reach and the
highest reach among the target agent's resolved grants. This composes with the existing
`human-approval-gate` protections (a delegation targeting an agent with `requiresApproval` is already
refused outright, and the org kill-switch already refuses mid-turn delegation) rather than replacing
them. Since `delegateAgent` is `instance` and therefore already gated, the practical effect is on
what a *waiver* on `delegateAgent` may suppress — a waiver cannot silently authorise the callee's
egress.

### Decision 9: the owner-only finding, and why its fix is a different change

**Finding — REPRODUCED against a running instance, not inferred from code.** Full method and result
in proposal.md § Motivation 3. In short: a `PUT /apps/openregister/api/objects/hermiq/agent/{uuid}`
issued by `hermiq-outsider`, who is merely authenticated and is not the agent's owner, returned
**HTTP 200** and replaced an `admin`-owned agent's `tools` with the attacker's list — adding
`hermiq.sendMail`, i.e. irreversible external mail capability. The object still reads back
`"owner": "admin"` and `"authorization": []`;
`occ config:app:get openregister enforce_default_closed` is unset (default OPEN).

The sentence "Only the agent owner can change tool grants" in `ToolGrantEditor.vue:80-82` is help
text. The dedicated endpoint `PUT /apps/hermiq/api/agents/{agentId}/tool-grants` **is** correctly
guarded (`ToolOversightController.php:263-270`, unit-covered) — which is precisely why the hole is
easy to miss: the path that is tested is the path that is safe. But the app's primary agent editor,
`AgentFormModal.vue:643/683`, writes `tools` through `store.saveObject('agent', …)` → the
OpenRegister object route, bypassing that controller entirely; and the Agent schema declares no
`authorization` block, so OpenRegister's evaluator is default-OPEN for `update`
(`PermissionHandler.php:1236-1261`, gated by `enforce_default_closed` which defaults to `false` and
which hermiq never sets). **Any authenticated user can rewrite any agent's tool grants on a default
instance.** The hole is wider than tool grants — the same PUT carries `prompt`, `model`,
`requiresApproval` and `delegationAllowlist`, so one request can also rewrite what the agent is told
to do, strip its approval requirement, and widen who it may delegate to.

**Why not fix it here.** The correct fix is an owner-scoped `authorization` block on the Agent schema
in `lib/Settings/hermiq_register.json`, force-imported. That is:

- **OpenRegister's layer, not hermiq's.** ADR-023 Rule 1 — "app code that mutates domain objects MUST
  go through OpenRegister's ObjectService and trust the service's filtering + per-object permissions;
  apps do not implement object-ownership checks." A hermiq-side pre-write guard would be exactly the
  re-implementation that rule forbids, and hermiq has nowhere to put one: the only registered
  listeners are `ObjectCreatedEvent`/`ObjectUpdatedEvent` (`Application.php:121-131`), which are
  post-write and cannot deny.
- **Declarative, per ADR-031** — schema metadata over a service class.
- **`kind: config`, and a schema-definition change**, needing a forced re-import
  (`importFromApp(force: false)` advances the version *without* applying the schema and still reports
  success) and therefore its own migration plan. Bundling it with this much PHP would make this
  change `mixed`, which ADR-032 rejects outright.

So it becomes `agent-object-owner-authorization`, spec 1 of the chain, and this change `depends_on`
it. This change still specifies the owner requirement normatively — on **any** path that persists a
waiver-bearing grant list, not just the guarded endpoint — so the requirement cannot be signed off by
pointing at `ToolOversightController` alone.

### Decision 10: waiving is audited as a distinct, greppable event

Per ADR-004, governance goes to OpenRegister's `AuditTrail`. Persisting a grant list is already a
write; that write alone does not distinguish "the operator changed which tools are granted" from "the
operator switched off human oversight for one of them". Waiving is a privileged act in its own right
and gets its own action name, recorded with the acting user, the agent, the exact grant string, and
whether the waiver was added or removed. A reviewer answering "who turned oversight off, and when"
must be able to grep one token rather than diff two grant arrays.

### Decision 11 (forward-looking, for `mail-app-capability-ladder`)

Recorded here because it was verified during this change's research and would otherwise be
re-derived — and because one widely-assumed detail is **wrong**.

The Mail app 5.9.3 is installed at
`.../apps-extra/openregister/custom_apps/mail`. The ladder is:

| rung | call | reversible? | intended reach |
|---|---|---|---|
| draft | `DraftsService::saveMessage(Account, LocalMessage, $to, $cc, $bcc, $attachments = [])` then `DraftsService::sendMessage(LocalMessage, Account)` | fully | `user` |
| outbox | `OutboxService::saveMessage(Account, LocalMessage, $to, $cc, $bcc, $attachments = [])` | until it flushes | `external` |
| send | `OutboxService::sendMessage(LocalMessage, Account)` → `$this->sendChain->process(...)` | no | `external` |

**Correction to record:** `DraftsService::sendMessage()` does **not** transmit despite its name — it
calls `saveLocalDraft()`, i.e. it writes the draft into the IMAP Drafts folder and clears the local
row. The only irreversible send is `OutboxService::sendMessage()`. A ladder built on the method names
alone would have shipped "draft" and "send" as the same rung.

`AccountService::findByUserId(string): list<Account>` resolves the acting user's accounts;
`LocalMessage` carries `TYPE_DRAFT = 1` / `TYPE_OUTGOING = 0` and is populated through magic setters;
recipients are passed as `array{email: string, label?: string}` and converted internally.

**`hermiq.sendMail` disposition — keep the id, freeze its meaning, deprecate in prose.** It stays
`IMailer`-backed, immediate, irreversible, `external`. It is NOT re-pointed at the outbox: an id that
already appears in operators' grant lists cannot silently change meaning, and re-pointing would
change the transport, the `From` address and whether a Sent copy appears — all observable. The three
`hermiq.mail.*` ids are purely additive, and `hermiq.sendMail` keeps a real job: it is the only mail
verb that works when the Mail app is absent.

Mail is an OPTIONAL runtime dependency and MUST be resolved lazily by FQCN string through the
container, never by type-hint, exactly as `lib/Service/Talk/TalkBridge.php` does for spreed:
FQCNs held as `private const` strings (`:102-114`), availability probed at invoke time with
`class_exists()` (`:140-146`), every entry point guarded and wrapped in `catch (Throwable)` returning
a neutral value (`:281-308`). Note `TalkBridge`'s deliberate asymmetry — listener registration is
unconditional, because a `class_exists()` guard at `register()` time can return false on a healthy
instance where the sibling app has not loaded yet.

### Why a chain, not one change

The full ask — reach axis, mail ladder, waiver, widget, docs, e2e — is roughly ten tasks. At two
checkboxes each that is exactly the 20-checkbox ceiling with zero headroom, and the individual tasks
are not comparable in size to the ceiling's intent. `talk-agent-sessions`, the closest precedent, used
16 checkboxes for 8 tasks and was PHP-only plus one e2e task. This work spans a PHP classification
change, a schema-authorization fix, a new optional-app integration with three new tools, a
581-line Vue rewrite, a new docs page and browser e2e.

ADR-032's actual criterion is not the checkbox count but the reviewer surface and the turn budget: a
`code` spec runs all mechanical gates, and the recorded Stage-A failure mode is a single cycle
spanning declarative and code surfaces burning its whole budget without producing a PR. This shape is
that shape. ADR-032's "when to NOT chain — pure code, no declarative surface" does not rescue it,
because the owner-only fix puts a declarative surface in the envelope, which makes the un-split
version `mixed` — the case ADR-032 rejects outright.

The split follows ADR-032's declare-then-consume ordering:

1. **`agent-object-owner-authorization`** (`kind: config`) — Agent schema gains an owner-scoped
   authorization block; forced re-import; migration plan. Lands first, changes no consumer.
2. **`agent-capability-reach`** (`kind: code`, this change) — the reach axis, the waiver grammar and
   its four properties, the audit event, the docs page.
3. **`mail-app-capability-ladder`** (`kind: code`) — the three Mail-app rungs, lazily resolved;
   needs the reach vocabulary to exist.
4. **`grant-waiver-ui`** (`kind: code`) — the ToolGrantEditor rewrite; needs both the vocabulary and
   the mail ids to have something to render.

Each is independently shippable and independently valuable. Nothing in step 2 depends on the Mail app
existing.

## Fleet-wide finding: `openregister.enforce_default_closed` defaults to `false`

**This section records a concern that reaches beyond hermiq. It deliberately decides nothing — the
call is an ADR-level one for Conduction and is outside this chain.**

The IDOR reproduced in Decision 9 is a hermiq bug in the sense that hermiq's Agent schema ships an
empty `authorization` block. But the reason an empty block is *dangerous* is not hermiq's: OpenRegister's
`PermissionHandler` evaluates an empty block as default-**OPEN** for `update`
(`openregister/lib/Service/Object/PermissionHandler.php:1236-1261`). The one lever that reverses
that, `openregister.enforce_default_closed`, defaults to `false` (`:1493-1512`) and was confirmed
unset on the reproduction instance.

The generalisation follows directly and is not speculative: **every apps-extra schema that ships an
empty `authorization` block is default-open for `update` on a default instance.** Hermiq's Agent
schema is one instance of that class, not the class itself. Fixing hermiq's schema closes hermiq's
hole and leaves the class untouched.

What an audit would have to establish, in this order:

1. **Which schemas ship an empty `authorization` block**, across every register file in the fleet
   (`lib/Settings/*_register.json`). This is a mechanical sweep, and it is the only step that
   produces a number rather than an opinion. Until it exists, the blast radius is unknown — note
   that a schema *not* appearing in the sweep is only safe if the sweep actually parsed its file, so
   the sweep needs a positive control (a schema known to carry a real block must show up as
   non-empty).
2. **Which of those schemas hold data whose write is privileged** — credentials, grants, approval
   flags, ownership fields, anything an attacker gains by rewriting. An empty block on a schema
   nobody can reach or nobody cares about is noise; an empty block on a schema holding capability
   grants is the hermiq case repeated.
3. **Whether the fleet should set `enforce_default_closed` globally.** This is the actual decision,
   and it is not free: **flipping it is a BREAKING change for anything relying on default-open.**
   Any app whose schemas ship empty blocks and whose users currently write those objects would start
   getting refusals, and the failure mode is a write that silently stops working rather than an
   obvious error. Sequencing matters — declaring blocks per schema first, then flipping the flag, is
   expand-then-contract; flipping first is an outage.
4. **Whether the safe default belongs upstream instead** — i.e. whether OpenRegister should evaluate
   an empty block as closed and require an explicit opt-in to open, making the flag unnecessary.
   That is the cleaner fix and the larger breaking change, which is exactly why it is an ADR
   question and not a task in this chain.

Recorded here so the finding is not lost when this change archives. Raising it is a separate action
from fixing hermiq; this chain does the second and does not wait on the first.

## Risks / Trade-offs

- **[`webSearch`/`webFetch` become gated; a scheduled research agent stops silently]** → the refusal
  envelope names the reach that triggered it, so a run trace reads `approval_required … reach:
  external` rather than a mystery denial; the tightening is documented in `docs/tool-grants.md`; the
  unchanged explicit-grant escape hatch restores the capability in one grant edit.
- **[Two axes are more to reason about than one]** → mitigated by keeping the axes orthogonal and
  single-purpose (`scope` = data verb, `reach` = blast radius) and by making the docs page lead with
  a worked example per reach level rather than with the grammar.
- **[Derived-tool reach is inferred, so an OpenRegister tool that egresses would be mis-classified]**
  → the inference covers only the closed ADR-063 verb set and falls to `external` for anything else;
  a declared `reach` key always wins, so a future upstream annotation supersedes it with no Hermiq
  change. Recorded as a proposal Open Question.
- **[The waiver is a real reduction in oversight and the docs must not soft-pedal it]** → the docs
  requirement names "plainly what waiving approval gives up" as content, and the audit event exists
  so the reduction is observable after the fact even if the operator never read the page.
- **[This change's headline property depends on another change landing]** → `depends_on` is declared
  in frontmatter and the supervisor blocks on it; the owner requirement is specified against *any*
  persisting path so it cannot be waved through.

## Migration Plan

No data migration and no schema change in this change. Deployment is a code deploy plus a docs build.

Operator-visible steps, in order:

1. Land `agent-object-owner-authorization` (forced register re-import; verify by reading the live
   register back, not by trusting the version bump).
2. Deploy this change. On first turn assembly every descriptor carries a `reach`; grant lists are
   unchanged and every existing string parses identically.
3. Any agent relying on `hermiq.webSearch` / `hermiq.webFetch` through an empty or wildcard
   `Agent.tools` must name them explicitly, or accept the approval gate. This is the only migration
   action and it is a grant edit, not a data change.

Rollback: revert the branch. A grant string carrying `#noapproval` degrades to "resolves to nothing"
under the pre-change parser — fail-closed, not an escalation — and is remediated by removing the
fragment through the grant editor.

## Open Questions

- Should a low reach ever RELAX today's write/destructive gating (the `forgetMemory` case)? This
  change says no; see Decision 3.
- Should `reach` be proposed upstream as an OpenRegister MCP annotation key?
- **Answered, not open:** `agent-object-owner-authorization` scopes to hermiq's Agent schema, so this
  chain fixes the reproduced IDOR without waiting on a fleet decision. The
  `enforce_default_closed` default is raised separately as a fleet-wide concern — see "Fleet-wide
  finding" above, which states what an audit must establish and explicitly does not decide it.

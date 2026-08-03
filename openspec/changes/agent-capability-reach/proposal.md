---
kind: code
depends_on: [agent-object-owner-authorization]
chain:
  - agent-object-owner-authorization  # kind: config — Agent schema gains an owner-scoped authorization block
  - agent-capability-reach            # this change — the reach axis, the waiver grammar, the audit event, docs
  - mail-app-capability-ladder        # next — draft / outbox / send through the Mail app, three reaches, one ladder
  - grant-waiver-ui                   # last — the ToolGrantEditor rewrite that renders reach and toggles the waiver
---

# Proposal: agent-capability-reach

## Summary

Hermiq classifies every agent tool on ONE axis today — `scope`, the CRUD verb — and derives both
default-deny and the human-approval gate from it. That axis measures the wrong thing.
`hermiq.forgetMemory` is `delete` yet reversible and confined to the agent's own memory;
`hermiq.sendMail` is `create` yet irreversible and visible to a third party outside the building;
`hermiq.webFetch` is `read` yet emits an HTTP request to a caller-supplied URL. The most dangerous
tools in the catalogue carry the least alarming scope. This change adds a second, orthogonal axis —
**`reach`**, a closed ordered vocabulary `self < user < instance < external` describing the widest
set of principals an invocation can affect or disclose to — classifies the 14 native tools and the
OpenRegister derived families against it, makes default-deny and the approval gate key off reach in
UNION with today's rule (so nothing becomes more permissive), and adds a `#noapproval` per-grant
waiver that is owner-only, audited, visible and strictly narrowing. It is spec 2 of a four-change
ADR-032 chain.

## Motivation

Four concrete problems, all live today.

**1. The risk axis is wrong.** `ToolGrantResolver::isWriteOrDestructive()` and
`FacadeToolInvoker::requiresApprovalGate()` both decide "is this dangerous?" from `scope` /
`destructiveHint` / `readOnlyHint`. That is a *data-verb* question. It cannot distinguish an
irreversible outbound email from an internal cache write, and it lets `hermiq.webSearch` and
`hermiq.webFetch` — the two tools that actually leave the Nextcloud instance — through as ordinary
reads: ungated, and included in the "all discovered tools" default set an empty `Agent.tools`
produces. An operator reading the grant editor has no way to see that `webFetch` reaches further
than `{app}.{schema}.delete` does.

**2. There is no way to say "this specific narrow capability may run unattended".** The approval gate
is all-or-nothing per tool. An operator who wants an agent to email exactly one address on a schedule
must either grant the tool and lose the gate for every use of it, or keep the gate and lose the
schedule. The requirement is that operators "themselves decide when approval becomes undoable and
turn it off" — which means the waiver must attach to an *already-narrowed* grant, not to a tool.

**3. "Only the agent owner can change tool grants" is FALSE — reproduced live, not inferred.** This
is the reason the chain exists, so it is stated here in full rather than as a footnote.

**Reproduction**, against a running instance:

1. Created an agent as `admin` with `tools: ["hermiq.readFile"]`.
2. As `hermiq-outsider` — **not** the owner, merely an authenticated user — issued
   `PUT /apps/openregister/api/objects/hermiq/agent/{uuid}` with
   `tools: ["hermiq.sendMail", "hermiq.readFile"]`.
3. **Response: HTTP 200.** Reading the object back shows `"owner": "admin"` and
   `"authorization": []` unchanged — but `tools` is now the attacker's list.
4. `occ config:app:get openregister enforce_default_closed` → unset, i.e. default OPEN.

A non-owner granted an agent irreversible external mail capability. The same PUT also carries
`prompt`, `model`, `requiresApproval` and `delegationAllowlist`, so the same request can rewrite what
the agent is told to do, remove its approval requirement, and widen who it may delegate to.

**Why it happens**, tracing the four contributing facts:

- `src/components/ToolGrantEditor.vue:80-82` renders "Only the agent owner can change tool grants" as
  help text. It gates nothing; the `canEdit` prop it keys on (`:225-228`) is computed client-side in
  `src/widgets/AgentToolGovernanceWidget.vue:79-80` and `save()` (`:358-370`) never consults it.
- The dedicated endpoint IS guarded: `lib/Controller/ToolOversightController.php:263-270` compares
  `$agent->getOwner()` to `$user->getUID()` and returns 403 otherwise, covered by
  `tests/Unit/Controller/ToolOversightControllerTest.php:366-375`. This is why the hole is easy to
  miss — the path that *is* tested is the path that *is* safe.
- **`tools` is writable through a second, unguarded path that the app's own primary agent editor
  uses.** `src/modals/AgentFormModal.vue:643` puts `tools` in the payload and `:683` calls
  `store.saveObject('agent', …)`, which `src/store/store.js:38-41` binds to the OpenRegister object
  route above. The hermiq owner check is never reached.
- OpenRegister does not owner-scope that write. The Agent schema declares no `authorization` block
  (`lib/Settings/hermiq_register.json:1909-1912` carries only inert `publicRead`/`publicWrite`), and
  OpenRegister's evaluator is default-OPEN for `update` when the block is empty
  (`openregister/lib/Service/Object/PermissionHandler.php:1236-1261`), gated by
  `openregister.enforce_default_closed` which defaults to `false` (`:1493-1512`) and which hermiq
  never sets — confirmed unset on the reproduction instance in step 4.

Adding a waiver that suppresses human oversight on top of a write path any authenticated user can
reach would make this strictly worse, so the enforcement has to land first — which is why the chain
opens with a `kind: config` change. The finding also reaches beyond hermiq: see design.md
"Fleet-wide finding".

**4. The grant model is undocumented.** `docs/` contains one page, `agent-object-leaf.md`. The grant
grammar — exact ids, `{app}.{schema}.*`, `.*:write`, argument constraints — exists only in the
`ToolGrantResolver` class docblock and in `openspec/`. Operators configuring a 98-tool catalogue
through a combobox have nothing to read. Shipping a waiver that suppresses human oversight without a
page that plainly says what is given up would be indefensible under ADR-004's AI-Act framing.

Why now: `hydra-console-agent-leaves` has just landed argument-scoped grants, so the grant grammar is
already being extended and the parser already splits on a delimiter. Adding the reach axis and the
waiver fragment in the same grammar generation avoids a second migration of operators' grant lists.

## Affected Projects

- [ ] Project: `hermiq` — new `reach` descriptor key + `ToolReachResolver`; `ToolGrantResolver` gains
      `#noapproval` fragment parsing; `FacadeToolInvoker` gate keys off reach and honours the waiver;
      the waiver write path is owner-verified and audited as a distinct event; a new
      `docs/tool-grants.md`; Playwright e2e.
- [ ] Project: `openregister` — **consumed, not changed**. The derived `{app}.{schema}.{verb}`
      catalogue and the `AuditTrail` this change writes to are OpenRegister's; no OpenRegister edit is
      required or requested. `reach` for derived tools is inferred Hermiq-side from the ADR-063 verb
      vocabulary (see design.md), so no upstream annotation key is needed to ship.

## Scope

### In Scope

- A closed, ordered `reach` vocabulary — `self` < `user` < `instance` < `external` — declared as a
  descriptor key alongside `scope`, with a defined comparison order.
- **Fail-closed**: a descriptor that declares no `reach`, or an unrecognised value, is treated as
  `external`. A hint-less tool is precisely the case that must not slip through — the same posture
  `agent-tool-governance` already takes with "a hint-less curated tool fails closed".
- Reach classification for all 14 native `hermiq.*` tools and for the OpenRegister derived
  `{app}.{schema}.{verb}` families.
- Default-deny and the approval gate keying off reach **in union with** today's write/destructive
  rule: gated when write/destructive **OR** reach ≥ `instance`. Reach may only ever ADD restriction.
- A rule that `hermiq.delegateAgent` cannot be used to launder reach — a delegation's effective reach
  accounts for the target agent's own granted reach.
- The `#noapproval` grant fragment: grammar, parse order (fragment stripped before the `?` split),
  and its four non-negotiable properties — owner-only (server-verified), audited as a distinct
  greppable event, visible wherever the grant is shown, and strictly narrowing.
- `docs/tool-grants.md`: the full grant syntax, the reach vocabulary with a worked example per level,
  default-deny, when the gate fires, and plainly what waiving approval gives up.
- Playwright e2e under `tests/e2e/spec-coverage/` for owner-only enforcement on both write paths and
  for waiver persistence, at the API level.

### Out of Scope — deferred to the chain

- **The Agent-object authorization block** (`agent-object-owner-authorization`, `kind: config`, this
  change's `depends_on`). Closing the finding above means declaring owner-scoped `update`/`delete`
  authorization on the Agent schema in `lib/Settings/hermiq_register.json` and force-importing the
  register. That is a declarative data-RBAC fix and therefore OpenRegister's layer, not hermiq's
  (ADR-023 Rule 1 — apps never roll their own data RBAC; ADR-031 — prefer schema metadata over a
  service class). Bundling a schema change with this much PHP would make this change `mixed`, which
  ADR-032 rejects. Note the hole is wider than tool grants: on a default instance the same path lets
  any authenticated user rewrite an agent's prompt, model and schedule.
- **Mail through the Mail app** (`mail-app-capability-ladder`). Today `hermiq.sendMail` calls core
  `OCP\Mail\IMailer` directly, so the only mail verb is the irreversible one. The Mail app (5.9.3,
  installed at `openregister/custom_apps/mail`) offers a three-state ladder — draft, outbox (queued,
  still recallable), send — each with a different reach. That ladder is the whole point of a reach
  axis, but it is a second app integration with its own optional-dependency risk and its own
  grant-id compatibility decision, and it needs this change's vocabulary to exist first.
- **The grant editor rewrite** (`grant-waiver-ui`). `src/components/ToolGrantEditor.vue` (581 lines)
  is a flat list of 98 tools with a combobox. Rendering reach, scope, approval applicability and
  argument constraints per entry, grouping and filtering by reach and by app, and an owner-only
  waiver toggle is a full Vue surface with its own ADR-004 modal-isolation, WCAG AA and browser
  e2e obligations.
- No OpenRegister schema migration **in this change**. `Agent.tools` is already `string[]` (verified —
  `Agent` v0.4.0, `lib/Settings/hermiq_register.json`); every new syntax rides inside those strings.
- No change to OpenRegister RBAC, which remains the sole authoritative invoke-time boundary. Reach,
  like the existing hints, is a governance layer that only ever NARROWS.

## Approach

Reach is added as a **descriptor key**, exactly as `scope` was, so a tool declares its own blast
radius next to its own data verb. A small pure `ToolReachResolver` owns the vocabulary, the ordering
and the fail-closed default; `ToolGrantResolver` and `FacadeToolInvoker` consult it in addition to —
never instead of — the existing classification.

The `#noapproval` waiver is a URI **fragment**, so it composes with the existing `?arg=value` query
form without ambiguity: `hermiq.mail.send?to=in:user@example.com#noapproval`. The fragment is
stripped FIRST, before the `?` split, so it can never be mistaken for the tail of a constraint value.
It suppresses one thing only — the approval gate — and is consulted after grant expansion and after
constraint enforcement, so there is no seam through which it could widen anything.

The waiver's write path is verified against the agent's owner and writes a distinct audit event, so
waiving human oversight is itself recorded as a privileged act (ADR-004).

Chain rationale is in design.md under "Why a chain, not one change".

## New Dependencies

None. `ToolReachResolver` is pure PHP. The Mail app dependency arrives with
`mail-app-capability-ladder`, and will be optional and lazily resolved by FQCN string exactly as
`lib/Service/Talk/TalkBridge.php` resolves spreed.

## Impact

- `lib/Mcp/HermiqToolProvider.php` — every entry in `TOOL_DESCRIPTORS` gains a `reach` key.
- `lib/Service/Engine/ToolGrantResolver.php` — fragment parsing; default-deny consults reach.
- `lib/Service/Engine/FacadeToolInvoker.php` — `requiresApprovalGate()` consults reach and the waiver.
- New `lib/Service/Engine/ToolReachResolver.php`.
- `lib/Controller/ToolOversightController.php` — the waiver audit event on the guarded write path.
- New `docs/tool-grants.md`.
- New `tests/e2e/spec-coverage/tool-grant-reach.spec.ts`.
- **Behavioural change operators will notice**: `hermiq.webSearch` and `hermiq.webFetch` are `read`
  today and therefore free; under reach they are `external` and become default-denied and
  approval-gated unless explicitly granted. This is the intended tightening, and it is the only
  capability an existing agent can lose.

## Cross-Project Dependencies

None blocking. OpenRegister's derived catalogue and `AuditTrail` are consumed through the unchanged
`ToolRegistryFacade` and `ObjectService` ABIs. A future OpenRegister `reach` annotation key would let
derived tools declare reach instead of having it inferred verb-side; that is an upstream nicety, not
a prerequisite. The intra-repo dependency on `agent-object-owner-authorization` is real and blocking
for the waiver's owner-only property.

## Risks

### Risk 1: The waiver ships before its owner-only backstop and hands every authenticated user an oversight switch

**Severity:** High — **Mitigation:** `depends_on: [agent-object-owner-authorization]` is declared in
this proposal's frontmatter and the Hydra supervisor blocks a spec until each named dependency
closes. In addition, this change specifies the owner check as a normative requirement on ANY path
that persists a waiver-bearing grant list, so a reviewer checking the requirement cannot be satisfied
by the guarded endpoint alone.

### Risk 2: The waiver becomes a general-purpose "turn the gate off" switch

**Severity:** High — **Mitigation:** strict narrowing is specified as its own requirement with a
scenario that would catch the mistake — a waiver on a tool that was never granted MUST NOT make it
runnable, and a waiver alongside argument constraints MUST NOT relax those constraints. The waiver is
consulted at the gate only, after expansion and after constraint checking.

### Risk 3: A previously-free read tool becomes gated and a scheduled agent silently stops working

**Severity:** High — **Mitigation:** `webSearch`/`webFetch` moving to `external` is the only
capability regression, and it is deliberate. Mitigate by (a) documenting it in `docs/tool-grants.md`
and in the change's release note, (b) making the refusal envelope name the reach that triggered it so
the cause is legible in a run trace rather than appearing as a mystery denial, and (c) the
explicit-grant escape hatch, which already exists and is unchanged: an agent that names
`hermiq.webFetch` in `Agent.tools` is not gated.

### Risk 4: Reach is inferred for OpenRegister derived tools rather than declared

**Severity:** Medium — **Mitigation:** derived-tool reach is inferred Hermiq-side from the ADR-063
verb vocabulary (a closed set), which is exactly the fallback `ToolGrantResolver` already uses for
classification, and the inference fails closed for anything outside it. A descriptor that DOES carry
a `reach` key always wins, so an upstream annotation can supersede the inference with no Hermiq
change.

### Risk 5: The chain stalls mid-way and the mail ladder never lands

**Severity:** Low — **Mitigation:** this change is independently valuable and independently shippable
once its one dependency closes — it gates the two egress tools, documents the grant model, and makes
waiving oversight an audited act. Nothing in it depends on the Mail app existing, and
`hermiq.sendMail` keeps working unchanged if the chain stops here.

## Rollback Strategy

Revert the branch. `Agent.tools` strings written under this change remain syntactically valid to the
pre-change parser with one exception: a grant ending in `#noapproval` would be read as part of the
base tool id (or of the last constraint value) and would therefore resolve to nothing — a
fail-closed degradation, not a privilege escalation. Remediation is an operator removing the fragment
through the grant editor; no data rewrite and no schema migration exist to reverse in this change.
Reverting does NOT re-open the owner-only hole, because the fix for that lives in the preceding
`kind: config` change and is independently revertible.

## Open Questions

- Should a LOW reach ever be allowed to RELAX today's write/destructive gating — e.g. should
  `hermiq.forgetMemory` (`delete`, but `self` and reversible) stop tripping the gate? This change
  says NO and makes reach purely additive, because "must not become more permissive because its reach
  is low" is the binding constraint. Revisiting it is a follow-up, not a silent decision here.
- Should `reach` be proposed upstream as an OpenRegister MCP annotation key so derived tools declare
  it rather than having it inferred? Recommended, but out of this chain.
- **NOT open — decided, and split in two.** The reproduced IDOR is fixed *here*, by scoping
  `agent-object-owner-authorization` to hermiq's Agent schema, so this chain is not blocked on a
  fleet-wide decision. Separately, the same root cause —
  `openregister.enforce_default_closed` defaulting to `false`, which makes every apps-extra schema
  shipping an empty `authorization` block default-open for `update` — is raised as a fleet-wide
  concern in its own right. It is an ADR-level call for Conduction and is deliberately NOT decided
  by this change; see design.md "Fleet-wide finding" for what an audit would have to establish.

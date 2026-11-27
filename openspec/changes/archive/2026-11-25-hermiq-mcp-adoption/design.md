# Design: hermiq-mcp-adoption

## Architecture Overview

ADR-063 gives OpenRegister three tool-supply chains. After this change Hermiq uses exactly two:

1. **Schema-declared CRUD** — `x-openregister-mcp` in `lib/Settings/hermiq_register.json`;
   `SchemaDerivedToolProvider` emits `hermiq.{schema}.{verb}`. Used for 3 schemas, **reads only**.
2. **`#[McpTool]` on services** — `AttributeToolScanner` + `AttributeToolProvider`, opted in via
   `IMcpScannableServices::hermiq`. Used for the 7 genuinely non-CRUD tools.
3. **Hand-written `IMcpToolProvider`** — **eliminated.** `HermiqToolProvider` is deleted.

### How the shadowing rule actually works (verified at OpenRegister HEAD)

Worth stating precisely, because the fleet brief's wording ("permanently shadows") is stronger
than the mechanism, and the difference changes what we must do.

`Application::registerMcpToolProviders()` appends providers in a fixed order: built-ins →
hand-written per-app → schema-derived → attributed. `SchemaDerivedToolProvider` is constructed with
a `suppressedIds` list (`collectExistingToolIds()`), and in `getTools()` it **omits** any derived
descriptor whose id is already claimed by a hand-written tool (REQ-DERIVED-003). Suppression is by
**exact tool id**.

Hermiq's hand-written ids are all 2-segment (`hermiq.listFiles`); derived ids are 3-segment
(`hermiq.agent.search`). **So nothing is literally suppressed today** — the two sets would simply
*coexist*. The real harm of leaving the provider in place is therefore not hard shadowing but
(a) duplicate capability and tool-catalog bloat, which measurably degrades LLM tool-selection, and
(b) the fail-closed misclassification in Motivation 1. Deleting the provider is still correct; the
reason is honest.

`addProvider()` *does* skip a provider whose `appId` is already registered — but that is the leaf-app
self-registration path, not the factory path, so it is not the mechanism at play here.

## Curation table — 3 of 21 schemas ON, all read-only

Every `filters` entry below was cross-checked against the schema's real `properties` in
`lib/Settings/hermiq_register.json` (OpenRegister's `McpAnnotationValidator::validateFilters()`
rejects the schema at import if a filter names a non-property).

### ON

| Schema | Verbs | Filters (all verified real properties) | One-line justification |
|---|---|---|---|
| `Agent` | `search`, `get` | `active`, `type`, `provider` | The app's primary noun — "which agents do I have, what model does X use" is the one question users actually ask, and reading an agent definition is not escalation. |
| `Schedule` | `search`, `get` | `enabled`, `agentId`, `lastStatus`, `kind` | `nextRun` / `lastStatus` / `lastError` *are* the human question ("did last night's digest run?"); read-only they leak nothing. |
| `Session` | `search`, `get` | `agentId` | Conversation **headers only** (`title`, `startedAt`, `lastActivityAt`) — enough for "what have we been working on", and it carries no transcript. |

**No `create`, `update` or `delete` is declared on any of the three.** Rationale in proposal Risk 1.

### OFF — and why

| Schema(s) | Why OFF (both verbs) |
|---|---|
| `Approval` | **Refused indefinitely.** Update = an agent approving its own pending action, defeating `human-approval-gate` outright. Read is refused too: it is the control plane for the agent's own governance, humans work it in the Approval Inbox UI, and keeping it off the surface closes the whole class rather than one verb. |
| `AgentWebhook` | Carries `secretHash` + `secretPrefix` — **credential material**. Never a tool. |
| `GuardrailPolicy`, `ModelPolicy`, `TenantControl`, `Budget` | The guardrail / kill-switch / budget control plane. `inputFilters`+`outputFilters` **are the blocklist** — reading them tells an agent what to evade; `TenantControl.engaged` is the tenant kill-switch. An agent must not see, let alone touch, its own restraints. |
| `Skill`, `SkillSource` | A `Skill` is **executable instruction content** (`body`, `files`) with a `state`/`quarantineReason` the agent could clear. Also unbounded tokens on read (no field projection → `get` returns `body`). |
| `Memory`, `UserProfile` | Write = **memory poisoning** (persistent injection surviving the session). Read = the exfil payload. "What do you remember about me" is a UI feature, not a tool. |
| `SessionTurn`, `Conversation`, `Message` | Raw transcripts. Next to the retained `hermiq.sendMail`, a transcript search is a two-call read-everything-then-email-it-out chain. Headers ship via `Session`; bodies stay off. |
| `Context`, `Feedback` | `Context` is admin-shaped config; `Feedback` written by an agent = it rating itself, poisoning the quality signal. Low value, non-zero harm. |
| `AiFeature`, `Incident` | The AI-Act / algoritmeregister compliance surface and the oversight trail. An agent writing its own incident record is **tampering with its own audit trail**; reading is a compliance officer's job, served by its own UI. |
| `CourseRecommendation` | **De-duplicated on purpose.** The retained curated `hermiq.recommendCourses` already serves this, and serves it better (it regenerates when stale). Declaring the dialect too would ship two overlapping tools — exactly the bloat ADR-063 rule 3 forbids. |

## Surgery classification — all 8 hand-written tools

None of the eight is derivable CRUD over Hermiq's registers; all are genuine non-CRUD (five NC-native
capabilities, one engine meta-tool, one recommendation engine). **Nothing is deleted for redundancy;
everything moves.** The provider then has zero tools and is deleted — no empty seam.

| Tool id | Verdict | Destination | `#[McpTool]` hints / scope — and why |
|---|---|---|---|
| `hermiq.listFiles` | Non-CRUD → **move** | `NcNativeToolService::listFiles()` | `readOnlyHint: true`, `destructiveHint: false`, `idempotentHint: true`, `scope: 'read'` — lists the caller's own folder. |
| `hermiq.readFile` | Non-CRUD → **move** | `NcNativeToolService::readFile()` | Same as above. Size-capped; touches nothing. |
| `hermiq.searchContacts` | Non-CRUD → **move** | `NcNativeToolService::searchContacts()` | Same as above. |
| `hermiq.listCalendarEvents` | Non-CRUD → **move** | `NcNativeToolService::listCalendarEvents()` | Same as above. |
| `hermiq.listDeckBoards` | Non-CRUD → **move** | `NcNativeToolService::listDeckBoards()` | Same as above. |
| `hermiq.sendMail` | Non-CRUD → **move** | `NcNativeToolService::sendMail()` | `readOnlyHint: false`, **`destructiveHint: true`**, `idempotentHint: false`, `scope: 'create'`. Sending mail is an **irreversible external side effect** — you cannot un-send, and two identical calls send two emails. It is also the app's primary exfiltration channel. It must be default-denied and explicitly granted, and now it will be *by declaration* rather than by accident. |
| `hermiq.recommendCourses` | Non-CRUD → **move** | `CourseRecommendationEngine::getOrRegenerate()` (service already exists) | `readOnlyHint: false`, `destructiveHint: false`, `idempotentHint: true`, `scope: 'update'` — it **persists** a regenerated recommendation when stale. Labelling it `read` would be a lie of exactly the kind `hermiq#57` was written to stop. |
| `hermiq.searchTools` | Engine meta-tool → **move** | `ToolSearchService::search()` | `readOnlyHint: true`, `destructiveHint: false`, `idempotentHint: true`, `scope: 'read'`. Must keep the id byte-identical (see below). |

## Decisions

### Decision 1: refuse writes everywhere, rather than gate them behind approval

Alternative considered: enable `hermiq.schedule.update` etc. and rely on `human-approval-gate` to
catch them. Rejected. The approval gate is *itself* one of the objects in question, and a control
that protects its own configuration is not a control. Refusing at the dialect means the tool is
never emitted — a strictly stronger guarantee than one enforced at invoke time by code the agent
might be able to influence.

### Decision 2: keep `hermiq.searchTools`'s id byte-identical

`AttributeToolScanner::buildDescriptor()` computes `$name = $attribute->name ?? $method->getName()`
and `id = $appId.'.'.$name`. `ToolSearchService::search()` therefore **must** be annotated
`#[McpTool(name: 'searchTools', ...)]`, or the id silently becomes `hermiq.search` and
`Engine\FacadeToolInvoker::__call()`'s short-circuit stops matching — progressive disclosure would
break quietly. Task 5 asserts the id.

### Decision 3: one `NcNativeToolService`, not five

Alternative considered: `NextcloudFilesService` / `ContactsService` / `CalendarService` /
`MailService` / `DeckService`. Rejected **for this change**: five classes is 5× the churn, and the
existing provider already aggregates these five capabilities behind a documented
`@SuppressWarnings(PHPMD.CouplingBetweenObjects)`. Moving the bodies verbatim into one service keeps
the IDOR guards and the tested error envelopes intact and keeps the diff reviewable. The split is a
clean follow-up once the tool surface is stable. Recorded as a deferred question, not a silent choice.

### Decision 4: withdraw `hermiq-domain-mcp-tools` rather than let it sit

It is 0/13 and unimplemented, so nothing is lost. Leaving it in `openspec/changes/` invites a future
builder to implement `hermiq.listPendingApprovals` and `hermiq.runAgentNow` against a superseded ADR.
`hermiq.runAgentNow` deserves a specific mention: an agent able to trigger agent runs is
self-re-entry — unbounded recursion and budget burn on top of the escalation.

## Nextcloud Integration

- Services: `NcNativeToolService` (new), `CourseRecommendationEngine`, `ToolSearchService`.
- OCP interfaces (unchanged, relocated): `IRootFolder`, `Contacts\IManager`, `Calendar\IManager`,
  `IMailer`, `IAppManager`, `IUserSession`.
- DI: `IMcpScannableServices::hermiq` alias → `lib/Mcp/HermiqScannableServices.php`.
  `IMcpToolProvider::hermiq` alias **removed**.

## Security Considerations

The per-object IDOR guards (`nc-native-tools` REQ) move **verbatim** with the tool bodies: every
NC-native method stays scoped to the acting user's own resources (`getUserFolder($uid)`, the user's
own addressbooks/calendars, `From:` = the caller's own address). Relocating logic must not relax
scoping, and Task 3 asserts it. Hints are advisory metadata for classification and UX only —
OpenRegister RBAC via `ObjectService` remains the authoritative invoke-time gate, and a
`readOnlyHint: true` descriptor can never bypass it.

## File Structure

```
lib/
  Mcp/
    HermiqToolProvider.php        ← DELETED
    HermiqScannableServices.php   ← NEW (IMcpScannableServices)
  Service/
    NcNativeToolService.php       ← NEW (6 tools, bodies moved verbatim)
    CourseRecommendationEngine.php ← #[McpTool] on getOrRegenerate()
    ToolSearchService.php          ← #[McpTool(name: 'searchTools')] on search()
  Settings/
    hermiq_register.json           ← x-openregister-mcp on Agent, Schedule, Session
  AppInfo/Application.php          ← alias swap
```

## Trade-offs

Exposing 3 of 21 schemas, read-only, is aggressive — and deliberately so. Hermiq is the one app whose
registers *are* its security boundary, so the usual "would a human plausibly ask this?" test is joined
by "and can an agent turn the answer against the platform?". Three schemas clear both. The cost is that
some genuinely convenient reads (skills, approvals, memory) are unavailable to the assistant; the
benefit is that no single tool call can widen the agent's own grants. That trade is worth taking, and
it is reversible in the safe direction (adding a read later is cheap; recalling a leaked webhook secret
is not).

## Context

`CnAiCompanion` is rendered by `CnAppRoot` when an app passes `:aiCompanion="true"`.
Hermiq does. No other app on the instance does, and the office editors cannot: they
are `onlyoffice`, `eurooffice` and `richdocuments` — third-party code.

## Goals / Non-Goals

**Goals:**
- Chat reachable from the page the user is on, including a document editor.
- The open document's id carried automatically.

**Non-Goals:**
- Suite-specific integration. Attaching per suite would make a capability depend on a
  named suite, which ADR-087 §5 bans and which the `SuiteIndependenceTest` enforces.
- Replacing the in-app companion on Hermiq's own pages.

## Decisions

### D1 — An init script, not a component

There is no template of ours on a third-party editor page. `\OCP\Util::addInitScript`
is the only seam, and this app already uses it for `hermiq-agent-leaf`.

### D2 — Detect Hermiq's pages by body class, not URL

Nextcloud sets `app-hermiq` on `body` for the active app. A URL match would have to
cover `/apps/hermiq`, `/index.php/apps/hermiq`, and any sub-path deployment — three
ways to be subtly wrong, producing two companions fighting for one corner.

### D3 — Read the file id from both shapes

`/apps/onlyoffice/{fileId}` and `/apps/eurooffice/{fileId}` put it in the path;
`richdocuments` uses `?fileId=`. Both are read. A page with neither is the normal
case and mounts without context rather than logging.

### D4 — Own container appended to body

The bundle runs on pages owned by other teams. Writing into a host element is how one
app breaks another's layout, and the breakage gets attributed to the host.

## Seed Data (ADR-001)

**None.** No OpenRegister schemas are introduced or modified.

## Declarative-vs-imperative decision (ADR-031)

| Behaviour | Path | Rationale |
|---|---|---|
| Mounting the companion | **Imperative** | Frontend bootstrap; not a lifecycle, aggregation, derived field, notification, relation or widget. |

## Risks / Trade-offs

**A bundle on every page is a cost on every page.** It must not pull the Hermiq app
shell with it. The bundle size is a required measurement, not an afterthought — the
agent-leaf entry has an explicit note in `webpack.config.js` about staying
self-contained for exactly this reason, and this entry inherits that concern.

**UNVERIFIED AT TIME OF WRITING.** The code and wiring are in place; the bundle has
not been built or loaded in a browser, because the toolchain in this working copy was
incomplete. Nothing here should be described as working until it has been seen to
mount on an editor page. That is the first task.

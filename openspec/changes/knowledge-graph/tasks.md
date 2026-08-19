# Tasks: knowledge-graph

## 1. Schema (register patch)

- [ ] 1.1 Add `GraphEntity` schema to `lib/Settings/hermiq_register.json` (slug
      `graphentity`): required `label`, `entityType`; `sourceType` (enum `object` |
      `file` | `mail` | `conversation`); `sourceRef` (object, typed per design.md);
      `aliases` (array of string, default `[]`); `confidence` (number 0–1);
      `extractedBy` (string). Flat, no `if`/`then`.
- [ ] 1.2 Add `GraphRelation` schema (slug `graphrelation`): required `subject`,
      `predicate`, `object` (subject/object = GraphEntity uuids); `sourceRef`;
      `confidence`; `extractedBy`.
- [ ] 1.3 Add `Agent.graphEnabled` (bool, default false).
- [ ] 1.4 Bump register `info.version` 0.26.0 → 0.27.0.

## 2. GraphService

- [ ] 2.1 Create `lib/Service/Graph/GraphService.php` (SPDX docblock, `@spec` tags):
      all persistence via `ObjectService`; upsert with deterministic entity
      resolution (normalised `label`+`entityType` within the organisation; proposed
      aliases appended).
- [ ] 2.2 Visibility check per `sourceType`, running as the acting user: `object` via
      `ObjectService` find; `file` via `IRootFolder::getUserFolder()` (mirrors
      `HermiqToolProvider::readFile()` guards); `mail` via the mail read tools'
      account-scoped service; `conversation` via the owner-or-listed-participant rule
      (`talk-shared-sessions`). Unresolvable ⇒ not visible, logged at debug, never
      thrown.
- [ ] 2.3 `neighbors(string $entityUuid, string $actingUserId, int $depth, array
      $predicates = [])`: bounded traversal (depth + node/edge caps from `IAppConfig`
      with safe defaults); every returned edge passes the both-endpoints check.
- [ ] 2.4 `path(string $fromUuid, string $toUuid, string $actingUserId, int
      $maxHops)`: shortest path over VISIBLE edges only; no path "with holes".

## 3. Extraction

- [ ] 3.1 Create `lib/Cron/GraphExtractionJob.php` (`QueuedJob`, pure wrapper like
      `TalkTurnJob`) + an extraction service: job argument carries the enqueueing
      user; impersonate-and-restore around the whole run.
- [ ] 3.2 Source readers: OpenRegister objects (`ObjectService`), files
      (`IRootFolder`), mail (shipped mail read tools' service layer), conversation
      history (Hermiq `Conversation`/`Message` objects, roster-checked).
- [ ] 3.3 Entity/relation proposal via the existing LLM provider layer
      (`ProviderFactory`); persist ONLY through `GraphService` with `extractedBy`
      (extractor id + version) and `confidence`; redaction before persist (existing
      redaction service) applies to labels.
- [ ] 3.4 Enqueue points: manual (per register/schema selection), and incremental on
      extraction re-runs (idempotent upserts — re-running a batch must not duplicate
      nodes/edges).

## 4. Context retrieval graph mode

- [ ] 4.1 `ContextRetrievalHandler::retrieveContext()`: add the `graph` mode branch —
      seed matching on labels/aliases, `GraphService` traversal, live hydration of
      visible records into the existing superset result shape, plus a compact
      `Relations:` text block.
- [ ] 4.2 Degradation: empty graph / no seeds / GraphService failure falls through to
      the existing keyword path with a `LoggerInterface::info()` note; the
      never-throws contract of `retrieveContext()` is preserved.

## 5. Tools

- [ ] 5.1 Register `hermiq.graphNeighbors` (`{entity, depth?, predicates?}`) and
      `hermiq.graphPath` (`{from, to, maxHops?}`) through the same registration path
      as the existing `hermiq.*` domain tools (read-only hints; local reach); results
      carry labels, predicates, `sourceRef` pointers — never record content.
- [ ] 5.2 Verify both tools flow through the grant-filtered, default-denied resolved
      set (`ToolSearchService` invariant untouched).

## 6. Verify

- [ ] 6.1 Unit tests (php:8.3-cli, the CI way): `GraphServiceTest` (upsert/resolution
      idempotency; both-endpoints edge filtering; hidden-link path refusal;
      unresolvable-sourceRef fail-closed), extraction test (user who cannot read a
      record produces no nodes from it; roster-refused conversation), handler test
      (graph mode hydrates live content; empty graph degrades to keyword),
      tool tests (RBAC-filtered results; no content inlined).
- [ ] 6.2 Fresh containerized PHPUnit run vs. the current baseline — report both
      counts.
- [ ] 6.3 `openspec validate knowledge-graph --strict`; phpcs/psalm/phpstan clean;
      hydra gates diff-scoped vs `origin/development` — report results.

## Acceptance criteria

- A graph node always points at a record and never carries a copy; hydration is live
  and acting-user-scoped.
- No read path ever returns an edge, node label, or path segment the acting user
  could not derive from records they can read.
- Extraction is enqueue-user-scoped, idempotent, redacted, and every write is
  audit-trailed with extractor provenance.
- `ragSearchMode: graph` enriches a turn when the graph has answers and degrades to
  keyword when it does not — never failing the turn.

## Quality reminders

- SPDX tags in each PHP docblock; `@spec` tags referencing this change.
- No sed/awk/scripts on code — Edit tool only.
- Single write-path via `ObjectService`; fail closed on every visibility check.
- OR-side seams (native traversal API, embedding-based entity resolution) are
  explicit deferred seams — do not stub them.

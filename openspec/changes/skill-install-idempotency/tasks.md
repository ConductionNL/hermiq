## Tasks

### 1. Identity resolution

- [ ] Add `lib/Service/SkillIdentityResolver.php` — canonicalise a bundle skill to `https://<canonical-host>/<owner>/<repo>/skills/<bundleName>` with mirror hosts normalised and NO git ref
- [ ] Resolve: exact `sourceUrl` match → one-time normalised-name fallback restricted to skills with NO `sourceUrl` → return the match plus how it matched

Acceptance criteria
- A name collision against a skill carrying a DIFFERENT `sourceUrl` does not match
- The matched-by reason is returned, so a fallback match is visible rather than assumed

### 2. Upsert policy

- [ ] Add `lib/Service/SkillUpsertPolicy.php` — merge bundle content over an existing skill, writing ONLY `body`, `frontmatter`, `files`, `description` and never the curated keys
- [ ] Re-quarantine on ANY content difference, with a reason naming the change; leave state untouched when content is identical
- [ ] Keep the local `learnings.md` when `lastAcceptedVersionAt` postdates `sourceUpdatedAt` AND the incoming file differs — apply the rest of the update and flag `learningsKept`
- [ ] Stamp `sourceUrl` and `sourceUpdatedAt` on every successful install or update

Acceptance criteria
- `maturityLevel`, `targetLevel`, `levelEvidence`, `installedOn`, `createdBy`, `publishedAt`, `archivedAt`, `lastAcceptedVersionAt` are never written by the merge
- The learnings comparison uses `sourceUpdatedAt`, never `publishedAt`

### 3. Wire into the install path

- [ ] Use the resolver and policy in `SkillMarketplaceService::installFromSource()` so create-vs-update is decided in one place
- [ ] Report `updated` / `unchanged` / `learningsKept` / `matchedBy` / `sourceUrl` per skill in `SkillBundleInstaller`, and surface them through `SkillController`

Acceptance criteria
- The HTTP route and OpenBuild's cross-app caller inherit the behaviour identically
- Response stays additive — `truncated` remains a bool, existing keys keep their meaning

### 4. Tests

- [ ] Unit-test identity, merge, re-quarantine, learnings preservation and the refresh clock per test-plan.md
- [ ] Mutation-check three ways: remove the identity match, compare learnings against `publishedAt`, and preserve state across a content change — each MUST turn the suite red

Acceptance criteria
- Every new test is proven capable of failing before it is trusted

### 5. Duplicate cleanup

- [ ] Export both duplicate pairs to a file, SELECT and eyeball their content, then delete the `quarantined` Meeting Summariser shadow and the later Nightly Backup Helper row

Acceptance criteria
- Content is inspected before deletion — an identical name is not an identical skill
- Count goes 101 → 99 and no name appears twice

### 6. Live + E2E verification

- [ ] Re-install `buildiq-hydra` on the dev instance and assert the skill count is unchanged, read from the DB before and after rather than from the response
- [ ] Playwright run on `browser-1` per test-plan.md: install through the UI, assert no duplicate rows appear, a content-changed skill shows as quarantined, and a learnings-ahead skill reports its learnings kept

Acceptance criteria
- Counts compared against the published artifact (94 skill directories), never the installer's own report
- Waits are on explicit UI state, never `networkidle`; the run targets the dev instance explicitly

### 7. Quality

- [ ] Run phpcs, phpmd, phpstan and psalm explicitly on PHP 8.3, plus the hydra gates, and open the PR

Acceptance criteria
- Each tool is confirmed to have actually run, not merely to have not failed

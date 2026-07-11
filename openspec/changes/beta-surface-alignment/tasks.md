# Tasks: beta-surface-alignment

## 1. Code metadata

- [x] 1.1 Translate `appinfo/info.xml` `<summary lang="nl">` to real Dutch
      ("Plan autonome AI-agents in Nextcloud"), replacing the English copy.

## 2. Product page (conduction-website)

- [x] 2.1 Author `conduction-website/src/pages/apps/hermiq.mdx` (EN) using the
      `shillinq.mdx`/`openbuild.mdx` structure: DetailHero (cobalt, Beta status),
      FeatureList (canonical feature list from the proposal), PairRow (OpenRegister),
      CtaBanner.
- [x] 2.2 Author `conduction-website/i18n/nl/docusaurus-plugin-content-pages/apps/hermiq.mdx`
      (NL) — real Dutch translation, not machine/English-mirrored copy.
- [x] 2.3 Verify `AppCrossLinks`/`PartnersForApp` usage matches the precedent set by
      `openbuild.mdx`/`doriath.mdx` (apps not yet in the docusaurus-preset
      `apps-registry` still render safely with these components).

## 3. Docs site (hermiq.conduction.nl)

- [x] 3.1 Rewrite `hermiq/docs/intro.md` "What is this?" section to list the real
      shipped features (agents, scheduling+Talk delivery, chat/sessions/memory,
      skills marketplace, MCP tools, human approval gate, run analytics, AI-feature
      governance, multi-tenant ops) instead of the generic app-template scaffold
      bullets (ExampleWidget, ExampleToolProvider).

## 4. Verify

- [ ] 4.1 Build `conduction-website` locally (`npm run build` or `npm run start`) and
      confirm `/apps/hermiq` (EN) and the `nl` locale build render without error.
- [ ] 4.2 Confirm `hermiq/docs` Docusaurus build still succeeds after the `intro.md`
      edit (no broken links/anchors introduced).
- [x] 4.3 Re-confirm every claim in the new product-page copy against `lib/` at HEAD
      (no fabricated compliance/standard claims; Talk delivery documented as
      optional/guarded, not a hard dependency).

## Out of scope (tracked, not fixed here)

- Adding `hermiq` to `conduction-website/src/data/apps-catalog.js` (needs a
  decision — see proposal.md "Still misaligned").
- Filling in `hermiq/docs/tutorials/user/01-first-launch.md` and
  `hermiq/docs/tutorials/admin/01-admin-settings.md` (all-TODO journeydoc
  scaffolds — needs a live instance + `/journeydoc-add-story`).
- Reconciling `hermiq/README.md` / `hermiq/project.md` (same generic-scaffold drift
  as `docs/intro.md` had, but not one of the 4 in-scope surfaces).
- `openspec/changes/remove-scaffold-leftovers` (separate, pre-existing, not-yet-applied
  change that removes the `Examples`/`example`-schema scaffold code referenced here).

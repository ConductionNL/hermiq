# Tasks: session-frontend-rename

## 1. Rename the API client and stores

- [ ] 1.1 `src/api/chat.js` — rename the helpers to session-named ones and point them at `/api/sessions/*`.
- [ ] 1.2 Rename the stores, router route names, and the registry keys referencing them. A duplicate NC route name silently displaces the existing one rather than erroring, so check the router after renaming.

Acceptance criteria
- Network requests from the Chat page go to `/api/sessions/*`, verified in the browser's network panel, not inferred from the source.

## 2. Rename the components and every string

- [ ] 2.1 `src/modals/ConversationRenameModal.vue` → `SessionRenameModal.vue`, same for the delete modal; update the registry.
- [ ] 2.2 `src/views/Chat.vue` — internal names, data keys, methods, and every rendered string.
- [ ] 2.3 Update the Dutch catalogue. Every string goes through `t('hermiq', …)`; a rename that only touches the English source leaves a Dutch user reading the old word.
- [ ] 2.4 Final sweep: `grep -ri conversation src/` must return hits ONLY where deliberate (deprecated-alias comments). Do the same for the i18n catalogues.

Acceptance criteria
- The grep sweep is run and its remaining hits are individually justified.

## 3. Fix the six Chat defects

- [ ] 3.1 "New conversation" always lands somewhere visibly different. It is NOT dead — `Chat.vue:720` clears the active thread so the agent grid shows — it is a no-op when no thread is open, which is why it reads as broken.
- [ ] 3.2 Un-clip the agent cards at the top of the thread column's scroll container.
- [ ] 3.3 Session rows show the agent icon and the session time (currently a bare date, no icon).
- [ ] 3.4 Replace the per-row archive button with an action menu: Archive, Delete, Continue.
- [ ] 3.5 Make the no-session-selected screen a "Start a session" empty state.
- [ ] 3.6 Split Active into human vs automated (cron / event / flow) using the trigger-origin property.

Acceptance criteria
- Each of the six is demonstrated in a browser, one at a time.

## 4. Verify the split against a real automated session

- [ ] 4.1 Create a session with a NON-`human` trigger origin. All 282 migrated sessions are `human`, so the automated group is empty by default and renders identically whether the split works or is broken.
- [ ] 4.2 Confirm it appears in the automated group and does NOT appear in the human one. Both halves — a filter that shows everything passes the first check and fails the second.

Acceptance criteria
- The split is proven with an object that actually carries a non-`human` origin.

## 5. Make the verification trustworthy

- [ ] 5.1 Bump the app version before verifying anything in a browser. NC's `?v=` cache-buster is keyed on the app version — measured on 2026-08-13, a DELETED component kept rendering until the version was bumped, so the browser will happily show pre-change UI.
- [ ] 5.2 Confirm the served bundle contains a string unique to this change before trusting any UI observation.
- [ ] 5.3 Verify through the UI, not the API. These are UI defects; a green endpoint says nothing about a clipped card or a dead button.

Acceptance criteria
- The bundle-freshness check is done first, and every UI claim is made against a confirmed-fresh bundle.

## 6. Quality

- [ ] 6.1 `npm run lint` clean; fix any pre-existing issues touched.
- [ ] 6.2 Confirm the deprecated `/api/conversations/*` aliases still work — they are the rollback path for this spec.

Acceptance criteria
- Lint passes and the aliases still respond.

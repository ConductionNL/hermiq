# Tasks: session-frontend-rename

## 1. Rename the API client and stores

- [x] 1.1 `src/api/chat.js` — rename the helpers to session-named ones and point them at `/api/sessions/*`.
- [x] 1.2 Rename the stores, router route names, and the registry keys referencing them. A duplicate NC route name silently displaces the existing one rather than erroring, so check the router after renaming.

Acceptance criteria
- Network requests from the Chat page go to `/api/sessions/*`, verified in the browser's network panel, not inferred from the source.

## 2. Rename the components and every string

- [x] 2.1 `src/modals/ConversationRenameModal.vue` → `SessionRenameModal.vue`, same for the delete modal; update the registry.
- [x] 2.2 `src/views/Chat.vue` — internal names, data keys, methods, and every rendered string.
- [x] 2.3 Update the Dutch catalogue. Every string goes through `t('hermiq', …)`; a rename that only touches the English source leaves a Dutch user reading the old word.
- [x] 2.4 Final sweep: `grep -ri conversation src/` must return hits ONLY where deliberate (deprecated-alias comments). Do the same for the i18n catalogues.

Acceptance criteria
- The grep sweep is run and its remaining hits are individually justified.

## 3. Fix the six Chat defects

- [x] 3.1 "New conversation" always lands somewhere visibly different. It is NOT dead — `Chat.vue:720` clears the active thread so the agent grid shows — it is a no-op when no thread is open, which is why it reads as broken.
- [x] 3.2 Un-clip the agent cards at the top of the thread column's scroll container.
- [x] 3.3 Session rows show the agent icon and the session time (currently a bare date, no icon).
- [x] 3.4 Replace the per-row archive button with an action menu: Archive, Delete, Continue.
- [x] 3.5 Make the no-session-selected screen a "Start a session" empty state.
- [x] 3.6 Split Active into human vs automated (cron / event / flow) using the trigger-origin property.

Acceptance criteria
- Each of the six is demonstrated in a browser, one at a time.

## 4. Verify the split against a real automated session

- [x] 4.1 Create a session with a NON-`human` trigger origin. All 282 migrated sessions are `human`, so the automated group is empty by default and renders identically whether the split works or is broken.
- [x] 4.2 Confirm it appears in the automated group and does NOT appear in the human one. Both halves — a filter that shows everything passes the first check and fails the second.

Acceptance criteria
- The split is proven with an object that actually carries a non-`human` origin.

## 5. Make the verification trustworthy

- [x] 5.1 Bump the app version before verifying anything in a browser. NC's `?v=` cache-buster is keyed on the app version — measured on 2026-08-13, a DELETED component kept rendering until the version was bumped, so the browser will happily show pre-change UI.
- [x] 5.2 Confirm the served bundle contains a string unique to this change before trusting any UI observation.
- [x] 5.3 Verify through the UI, not the API. These are UI defects; a green endpoint says nothing about a clipped card or a dead button.

Acceptance criteria
- The bundle-freshness check is done first, and every UI claim is made against a confirmed-fresh bundle.

## 6. Quality

- [x] 6.1 `npm run lint` clean; fix any pre-existing issues touched.
- [x] 6.2 Confirm the deprecated `/api/conversations/*` aliases still work — they are the rollback path for this spec.

Acceptance criteria
- Lint passes and the aliases still respond.

## Evidence

Measured 2026-09-07 against app version `0.1.48-unstable.20260907113505`, after
`npm run build` and `occ upgrade`, with the served `hermiq-main.js` confirmed to
contain `Started automatically`, `Started by you` and `chat-page__empty-inner`
before any observation was trusted (task 5.2).

- **1.1 / 1.2** The create call was observed going to `/apps/hermiq/api/sessions`;
  `chat.spec.ts` now asserts that URL on the POST, so a silent fall back to the
  deprecated alias fails the test rather than passing quietly.
- **2.3** `l10n/nl.json` gained 36 entries and lost the 31 that the rename left
  dead. `node tests/l10n/check-l10n.js` reports every used key present, and every
  key in `en.json` now has a Dutch value.
- **2.4** `grep -ri conversation src/` leaves hits in four places only: the Talk
  bridge (Nextcloud's own word for a Talk room), the two wire keys the chat and
  stream endpoints read, the `hermiq-skill-conversational-authoring` spec name,
  and the comments explaining the deprecated aliases.
- **3.1** With no session open, activating the control moves focus from `BODY` to
  the first agent card's Start session button.
- **3.2** At a 420px-tall window the start surface overflows by 379px. Fixed, the
  heading sits at +104px and the first card at +188px. Restoring the pre-fix rule
  (`justify-content: center` on the scrolling element) in the live page puts the
  heading at -85.5px and the first card at -1px, and `scrollTop` cannot go below
  0, so neither can be scrolled back into view.
- **3.3 / 3.4** Rows render an origin icon, the agent name and the time; the row
  menu offers Continue, Archive session and Delete session.
- **3.6 / 4** Proven with a session carrying `triggerOrigin: 'cron'`. It appears
  in the automated group and NOT in the human group; the five `human` sessions
  appear in the human group and NOT in the automated one. `chat.spec.ts` asserts
  all four of those, because the two presence assertions alone would pass a
  filter that let everything through.
- **6.1** `npm run lint` and `npm run format` both exit 0. 94 dead entries were
  pruned from `eslint-suppressions.json`; each named a file the agentflow
  retirement (`91bc82dc`) had already deleted.
- **6.2** `GET /api/sessions` and `GET /api/conversations` both answer 200 with
  the same total, so the rollback path is intact.

### Not covered here

`triggerOrigin` was absent from the API's serialiser, so the split would have
rendered identically whether it worked or not. It is now returned, defaulting to
`human` for any session stored before the property existed.

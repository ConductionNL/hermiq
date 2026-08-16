---
kind: code
depends_on: [session-api-rename]
---

# Proposal: session-frontend-rename

## Summary

Move hermiq's frontend onto "session" — the API client, the Chat page, the modals, the stores, the router and every user-visible string — and fix the six Chat-page defects that live on the same surface. The vocabulary change and the defects ship together because they touch the same 1,600-line component, and splitting them would mean editing it twice.

## Motivation

This is the spec the user actually sees. Until it lands, the app still says "conversation" on screen while the backend says "session", which is the confusion the chain exists to remove.

The six defects are bundled deliberately. Four of them (the empty state, the row content, the action menu, the Active/automated split) are *about* what a session is, so fixing them while renaming is cheaper and produces a coherent result rather than a rename followed by a redesign.

## Affected Projects

- [ ] Project: `hermiq` — `src/api/chat.js`, `src/views/Chat.vue`, `src/modals/Conversation*.vue`, stores, router, i18n strings

## Scope

### In Scope

**Vocabulary**
- `src/api/chat.js` → session-named helpers hitting `/api/sessions/*`.
- `src/views/Chat.vue` — internal names, data keys, methods, and every rendered string.
- `src/modals/ConversationRenameModal.vue` / `ConversationDeleteModal.vue` → `Session*`.
- Stores, router route names, and the registry keys that reference them.
- All strings run through `t('hermiq', …)` so the Dutch catalogue is updated rather than bypassed.

**The six defects**
1. **"New conversation" does nothing observable.** It is not dead — `Chat.vue:720` clears the active thread so the agent grid shows — but with no thread open it is a no-op. It must always land somewhere visibly different.
2. **Agent cards clipped above the scroll container.** The first row is cut off in the thread column.
3. **Session rows show a date and nothing else.** Add the agent icon and the session time.
4. **Per-row archive button → action menu** with Archive, Delete, Continue.
5. **No-session-selected screen** reads as a "Start a session" empty state rather than a bare agent grid.
6. **Active lumps human and automated sessions together.** Split them, using the trigger-origin property declared in `session-schema-declaration`.

### Out of Scope

- Removing the deprecated `/api/conversations/*` aliases — the API spec keeps them; retiring them is a later, evidence-driven change.
- Renaming the "Chat" menu entry or route. Whether the *page* is called Chat or Sessions is a product decision, not a vocabulary one, and is deliberately left alone here rather than assumed.

## Approach

One pass over the Chat surface: rename as the file is edited, and fix each defect in the region being touched.

Defect 6 depends on the trigger-origin property. Existing sessions are all `human` (set by the migration), so the automated group will be **empty on this instance** until an automated run creates one. An empty group is not evidence the split works — the split must be verified with an object that actually carries a non-`human` origin, or the test proves only that the code did not crash.

## New Dependencies

None.

## Impact

- The user-visible vocabulary changes everywhere at once. That is the point.
- The Chat page's list column gains an action menu and a grouping; the thread column gains an empty state.

## Cross-Project Dependencies

Depends on `session-api-rename` (same repo). The frontend keeps working through the deprecated aliases right up until this spec repoints it, so there is no window where the UI is broken.

## Risks

### Risk 1: A missed string leaves the old word visible
**Severity:** Medium — **Mitigation:** A rename is only done when nothing says the old word. Grep for `conversation` (case-insensitive) across `src/` at the end and expect hits ONLY in deliberate places — the deprecated-alias comments. Do the same for the Dutch catalogue, which is easy to forget and is what a Dutch-language user actually reads.

### Risk 2: The automated/human split is verified against an empty group
**Severity:** Medium — **Mitigation:** Stated above: create a session with a non-`human` origin and confirm it lands in the automated group and NOT in the human one. A split verified only against 282 human sessions and an empty other tab has tested nothing.

### Risk 3: A stale bundle makes the change look broken (or look fine)
**Severity:** Medium — **Mitigation:** Nextcloud's `?v=` cache-buster is keyed on the app version, so rebuilding JS without bumping it leaves the browser running the previous bundle — measured on 2026-08-13, where a deleted component kept rendering until the version was bumped. Bump the app version and confirm the served bundle contains a string unique to the change before believing any UI verification.

### Risk 4: Verified through the API instead of the UI
**Severity:** Low — **Mitigation:** These are UI defects. A green endpoint says nothing about a clipped card or a dead button. Verify each of the six in a browser.

## Rollback Strategy

Revert. The deprecated `/api/conversations/*` aliases still exist, so a reverted frontend keeps working against them.

## Open Questions

- Should the "Chat" menu entry become "Sessions"? Deliberately out of scope above, but it is the obvious follow-up question and the user should answer it rather than have it assumed.

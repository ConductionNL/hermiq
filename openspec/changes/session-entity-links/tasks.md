# Tasks

## 1. Record what a session is about

- [ ] Declare the link property on the chat-thread schema in `lib/Settings/hermiq_register.json`
- [ ] Bump `info.version` in the same commit
- [ ] Write the link when a turn is sent from a context, idempotent per (session, type, id)
- [ ] Capture the label at link time, display-name only

Acceptance criteria:
- Without the `info.version` bump the import is SKIPPED on every existing install, silently. Verify the property is live in `oc_openregister_schemas`, not merely present in the file.
- Opening the panel records nothing; only a sent turn does. A link recorded on open turns the assistant into a record of what a user looked at.
- Re-sending in the same context leaves exactly one link and touches its timestamp.
- The label is the file's display name. A path leaks folder structure to readers who may not have it.

## 2. Scope the session list

- [ ] Filter the session list server-side by linked entity
- [ ] Head the scoped list with the entity's label, and keep "view all sessions"
- [ ] Fall back to the unscoped list when no context is present

Acceptance criteria:
- ⚠️ Seed a linked session BEYOND the first page and assert it is FOUND. OpenRegister's `filters` addresses JSON properties: a filter naming a non-property matches nothing, for every value, and logs nothing — so a broken filter and a genuinely empty result are the same screen.
- An unlabelled filtered list is worse than no filter: an unexplained empty list reads as "I have no sessions".

## 3. Make the record answerable to the user

- [ ] Add a session-settings entry to the chat window's titlebar
- [ ] List the session's links there and allow removing one

Acceptance criteria:
- A link recorded automatically and removable only automatically is a tracker.
- After removal the session must not reappear in that entity's scoped list.

## 4. Composer: speech beside attachment

- [ ] Add a speech-input control to `CnAiInput`, immediately left of the attachment control
- [ ] Wire it to hermiq's existing speech backend

Acceptance criteria:
- ⚠️ This is an ADDITION. `SpeechClient` / `AudioToTextProvider` / `TextToSpeechProvider` exist and a speech container runs, but no control has ever existed in this panel — it was reported as a regression and is not one. Do not spend time looking for what broke.

## 5. Say "session" in new text only

- [ ] Use "session" in every string this change introduces
- [ ] Leave existing identifiers, routes and stored properties untouched

Acceptance criteria:
- ⚠️ `Session` is ALREADY a different schema here (the FTS5 recall port, with `SessionTurn`). Renaming `Conversation` collides with it, and `Message.conversationId` is a stored property, so the rename is a data migration with a name collision in it — `sessions-terminology`, sequenced after this. Converging new strings costs nothing and blocks on nothing.

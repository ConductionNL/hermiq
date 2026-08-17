## Context

Three facts, measured on the running instance 2026-08-16:

1. The companion mounts on every page and already receives a `contextFileId` when the
   page is showing a file — `src/companion.js` reads it from the query string or the
   last path segment, so `/apps/eurooffice/24753` yields `24753`. **The context
   already arrives; nothing consumes it.**
2. `CnAiChatPanel` fetches `/api/chat/conversations?limit=50` and shows the top 5,
   with no filter of any kind.
3. hermiq's register declares `Conversation`/`Message` for the live thread and a
   separate `Session`/`SessionTurn` pair for the FTS5 recall port. The names a
   reader expects to be synonyms are two different schemas.

## Goals / Non-Goals

**Goals:**
- A session can name what it is about, across entity kinds.
- A context-scoped session list, with the unscoped list one click away.
- Links are removable by the person they describe.

**Non-Goals:**
- The `conversation` → `session` rename (see D4).
- Changing `Context`. It is a curated, reusable bundle; this is an incidental record.
- Cross-user session discovery. A link says what a thread touched, not who may read
  it; the existing per-object authorisation still decides that.

## Decisions

### D1 — Store the link as a polymorphic reference, not one property per kind

A session may be about a Nextcloud file, an OpenRegister object of any schema, a
contact, or a future kind nobody has named. A property per kind (`fileId`,
`contactId`, …) forces a schema change per kind and makes "sessions about X" a
different query each time.

```
{ "type": "file",   "id": "24753",                                "label": "subsidiebesluit.docx" }
{ "type": "object", "id": "b08fe950-…", "schema": "docudesk/template", "label": "Alice's letter" }
{ "type": "contact","id": "…",                                    "label": "F. de Boer" }
```

`label` is denormalised on purpose: the session list must render without resolving N
references across N apps, and a session should still say what it was about after the
target is deleted or the caller loses read access to it.

⚠️ **A denormalised label is a disclosure surface.** It is written at link time from
what the linking user could see, and it is shown to whoever can read the session. It
MUST NOT carry anything the session's readers could not otherwise obtain — for a
file, the name, never the path.

### D2 — Record on use, not on open

The link is written when a **turn is sent** from a page carrying a context, not when
the panel is opened. Opening a document and glancing at the assistant is not a
statement that the conversation is about that document, and a link recorded on open
would fill every session list with pages someone passed through.

Idempotent per (session, type, id): re-sending in the same context updates
`lastUsedAt`, never appends a duplicate.

### D3 — Scope the list by query, not by filtering client-side

`GET /api/chat/conversations?linkedType=file&linkedId=24753`. The client already
fetches 50 and shows 5; filtering those 50 client-side would show "no sessions about
this document" whenever the relevant session is the 51st.

⚠️ Whatever backs this must be a real filter. OpenRegister's `filters` addresses
**JSON properties** — a filter naming something that is not a property matches
nothing, for every value, and logs nothing. The scoped list returning empty is
indistinguishable from a correct empty answer, so the acceptance test seeds a linked
session and asserts it is FOUND, not merely that the endpoint answers.

### D4 — The rename is a separate, sequenced change, and here is why it is not trivial

`Session` is taken. `Conversation` → `Session` collides with the FTS5 port schema;
going via a third name is two migrations. Beyond the collision: 45 `lib/` files, the
`/api/chat/conversations` route, and `Message.conversationId` as a **stored
property** — renaming a property is a data migration, not a string replace.

This change therefore:
- uses **"session"** in every NEW user-facing string,
- leaves existing identifiers alone,
- and blocks nothing on the rename landing first.

The alternative — rename first — puts a migration in front of the capability and
risks the classic outcome where the rename half-lands and the boundary that still
says `conversation` is the API contract.

### D5 — The speech control is a sibling of the attachment control

Both are input affordances on the same row, so speech goes in `CnAiInput` beside the
paperclip, to its LEFT. It is not a titlebar control: the titlebar names the session
and switches context; the input row acts on the message being composed.

hermiq already ships the backend — `SpeechClient`, `AudioToTextProvider`,
`TextToSpeechProvider`, and a running `hermiq-speech` container. **What is missing is
only the control**, which is worth stating plainly: this was reported as a
regression, and it is not one — the mic was never wired into this panel.

## Seed Data (ADR-001)

No new register objects. Links are properties on existing chat threads; nothing is
seeded, because a seeded link would assert a relationship no user made.

## Declarative-vs-imperative decision (ADR-031)

| Behaviour | Path | Rationale |
|---|---|---|
| The link property itself | **Declarative** | A property on the schema in `hermiq_register.json`. |
| Scoped session list | **Declarative** | An OpenRegister `filters` query on that property — no service class. |
| Record-on-send | **Imperative** | It reacts to a turn being dispatched and must be idempotent per (session, type, id); a derived field cannot express "write once, then touch a timestamp". |
| Denormalised label capture | **Imperative** | Reads the target's name through whichever app owns it, at link time. |

## Risks / Trade-offs

**A scoped list that silently matches nothing** is the failure this design is most
exposed to — see D3. It looks exactly like a correct empty state.

**The denormalised label goes stale.** Accepted: a session about "Q3 budget" that was
later renamed still says what it was about when it happened, which is more useful for
recall than a live lookup that fails when the object is gone.

**Automatic linking is a privacy surface.** A record of which documents someone
consulted an assistant about is sensitive on its own. Hence D2 (only on an actual
turn) and the settings surface that makes links visible and removable to the person
they describe.

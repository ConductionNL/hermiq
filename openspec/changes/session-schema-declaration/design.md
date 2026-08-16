# Design: session-schema-declaration

## Context

hermiq uses three words for one concept. The UI says "conversation", the page is called "Chat", the OpenRegister schema is `conversation`, and parts of the backend (the agent-memory routes, `/api/agents/{agentId}/sessions`) already say "session". A user archives a conversation from the Chat page and the backend records a session.

**session** is the chosen word, and it is the only accurate one. The thing being named is a bounded interaction with an agent that may be started by a human OR by a cron, an event, or a flow. "Chat" is wrong for the automated ones. "Conversation" implies a human at both ends.

Measured on the dev instance, 2026-08-13:

| Fact | Value |
|---|---|
| `conversation` objects in register `hermiq` (id 2428) | 282 |
| Backing table | `oc_openregister_table_2428_4494` |
| Existing instance-wide schema with slug `session` | scholiq's, id 1286 |
| Property distinguishing human from automated sessions | **none exists** |

## Goals / Non-Goals

**Goals:**

- Declare `session` with full parity to `conversation`, so the migration is a copy rather than a translation.
- Add the trigger-origin property the Chat page needs to split human from automated sessions.
- Stay inert. Nothing reads this schema until the migration spec runs, so this spec is safe to merge alone.

**Non-Goals:**

- Moving data, changing routes, touching the frontend — the three specs after this one.
- Removing the `conversation` schema. Deleting a schema is how 282 objects get lost.

## Decisions

### Decision 1: Take the plain `session` slug, on the strength of the OpenRegister fix

The alternative was a defensive slug (`agent-session`) titled "Session", immune to collision regardless of how resolution behaves.

**Chosen: the plain slug**, gated on `register-scoped-schema-slug-resolution` landing first. The defensive slug treats the symptom and leaves the disease: `agent` is already colliding between hermiq and openbuild and producing a live bug, and every future generic slug would need the same workaround. Fixing resolution fixes the class.

The cost is a hard, human-enforced ordering across two repositories — see Decision 3.

### Decision 2: Trigger origin is an enum on the session, not a derived value

The Chat page must separate human sessions from cron/event/flow ones. Two candidates:

- **Derive it** from whether a schedule or flow run created the session.
- **Record it** on the session at creation.

**Chosen: record it.** Deriving means a join back to schedules or flow runs on every list render, and the derivation breaks exactly when it matters — a session whose originating schedule was deleted becomes unclassifiable. That failure mode is not hypothetical here: the analytics surface was reading zero runs on 2026-08-13 precisely because it keyed on live schedules, and every schedule had been deleted.

Values: `human` | `cron` | `event` | `flow`. Default `human`.

### Decision 3: The cross-repo ordering is a human gate, and must be verified not assumed

`depends_on` resolves spec slugs to issue numbers **within one repository**, so Hydra's supervisor cannot gate this spec on an OpenRegister change. `depends_on` is therefore empty and the ordering is enforced by a person.

Because "the OpenRegister PR is merged" is not the same as "the fix is live on this instance", task 1.2 is a positive check with a defined failure: resolve slug `session` with register `hermiq` and confirm the result is **not** id 1286. A green PR in another repo is not evidence about this instance.

### Declarative-vs-imperative decision (ADR-031)

This change is **wholly declarative** — a schema declaration in `lib/Settings/hermiq_register.json`, which is why it is `kind: config`. No lifecycle, aggregation, calculation, notification, relation or widget behaviour is introduced, and no service class is added. The trigger-origin property is a plain enum recorded at creation (Decision 2), not a derived field, so it needs no `x-openregister-calculations` entry.

## Seed Data (ADR-001)

The new schema needs seed objects, and one of them exists for a specific reason.

| Object | Purpose |
|---|---|
| A `human` session — a municipal policy officer asking an agent to summarise incoming Woo requests | The ordinary case; realistic general-organisation data per ADR-001 |
| A `cron` session — a nightly agent run producing a monitoring digest | **Load-bearing for verification.** All 282 migrated sessions are `human`, so without a seeded automated session the human/automated split renders an empty group and looks identical whether it works or is broken |

Both carry realistic titles, timestamps and message counts. Neither uses a value that could be mistaken for a real credential.

## Risks / Trade-offs

- **Applied before the OpenRegister fix ships.** The whole design rests on Decision 1. Task 1.2 is the check; if it returns 1286, stop.
- **Property drift between the two schemas.** The migration copies field-by-field, so a property on one and not the other drops data silently. The property list must be machine-derived from the live `conversation` schema (task 2.1), never transcribed.
- **A schema that fails import VANISHES** — OpenRegister logs the failure rather than raising it. So verification must confirm the schema EXISTS after import; the absence of an error message is not evidence that anything was created.

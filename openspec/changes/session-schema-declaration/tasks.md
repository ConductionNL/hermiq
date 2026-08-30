# Tasks: session-schema-declaration

## 1. Confirm the dependency actually shipped

- [ ] 1.1 Verify OpenRegister's `register-scoped-schema-slug-resolution` is merged AND released to this instance. It lives in another repo, so nothing gates this automatically — this is the human gate.
- [ ] 1.2 Prove the fix is live before declaring anything: resolve slug `session` with register `hermiq` in context and confirm it does NOT return schema id 1286 (scholiq's "a scheduled occurrence of a Cohort meeting"). If it returns 1286, STOP — the fix is not in and declaring the schema now walks into the collision this chain exists to avoid.

Acceptance criteria
- The resolution check is run and its result recorded, not assumed from the OpenRegister PR being green.

## 2. Derive the property list from the live schema

- [ ] 2.1 Read the CURRENT `conversation` schema from the instance and list its properties. Do not transcribe from memory or from the Vue components — the migration copies field-by-field, and a property that exists on one schema and not the other drops data silently.
- [ ] 2.2 Record that list in design.md as the contract the migration spec will assert against.

Acceptance criteria
- The property list is machine-derived from the live schema and pasted into design.md.

## 3. Declare the session schema

- [ ] 3.1 Add the `session` schema to `lib/Settings/hermiq_register.json` carrying every property from task 2.1, with the same types, titles and descriptions.
- [ ] 3.2 Add the trigger-origin property (`human` | `cron` | `event` | `flow`) with `human` as the default — it is what all 282 existing objects are, and it is what the Chat page needs in order to split human sessions from automated ones.
- [ ] 3.3 Give every property a title AND a description. The Skills page needs header tooltips sourced from property descriptions; a schema that ships without them makes that impossible later.
- [ ] 3.4 Leave the `conversation` schema declared and untouched. Nothing is removed in this chain until the migration is verified in production.

Acceptance criteria
- `hermiq_register.json` parses and the schema imports without error — note that a schema which fails import VANISHES and the failure is logged rather than raised, so confirm it EXISTS after import rather than assuming absence of an error means success.
- Resolving slug `session` in register `hermiq` returns the new schema.

## 4. Seed data (ADR-001)

- [ ] 4.1 Add two illustrative session objects to the seed data — one `human`, one `cron` — so the automated/human split has something to render against on a fresh install. The 282 migrated objects are all `human`, so without a seeded automated session the split cannot be verified anywhere.

Acceptance criteria
- A fresh install shows at least one session in each of the two groups.

## 5. Hand-off

- [ ] 5.1 Confirm nothing reads the new schema yet — this spec is inert by design and safe to merge alone.
- [ ] 5.2 Record the property list and the trigger-origin default in design.md for `session-data-migration` to assert against.

Acceptance criteria
- The migration spec can start without re-deriving anything this spec already established.

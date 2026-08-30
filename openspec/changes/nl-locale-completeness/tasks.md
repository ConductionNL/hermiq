# Tasks: nl-locale-completeness

## 1. Audit

- [ ] 1.1 Diff `l10n/en.json` keys against `l10n/nl.json` keys; confirm the exact set of
      107 missing keys (re-run the audit at implementation time — the count in the
      proposal is a point-in-time snapshot and `en.json` may have grown).
- [ ] 1.2 Identify which of the current 32 `nl.json` keys have no counterpart in
      `en.json` at all (the scaffold-only entries: `"App Template settings"`,
      `"Placeholder: comment added"`, `"Placeholder: status changed to Review"`,
      `"Placeholder: user opened a record"`, `"app-availability.action"`,
      `"app-availability.description"`, `"app-availability.title"`, `"sample"`, and
      similar) — these get removed, not translated.

## 2. Translate

- [ ] 2.1 Write accurate Dutch translations for every key in `en.json` that is missing
      or empty in `nl.json`. Use existing Conduction-app Dutch translations as a style
      reference where the same/similar string already exists in a sibling app's
      `l10n/nl.json` (e.g. "Save" → "Opslaan", "Settings" → "Instellingen" are already
      established fleet-wide conventions — reuse them for consistency).
- [ ] 2.2 Preserve any ICU/plural placeholders (`%s`, `{count}`, etc.) exactly as they
      appear in the English source.
- [ ] 2.3 Remove the scaffold-only keys identified in 1.2 from `l10n/nl.json`.

## 3. Verify

- [ ] 3.1 Re-run the audit script from 1.1 — zero keys should remain missing/empty.
- [ ] 3.2 Validate `l10n/nl.json` is well-formed JSON (`python3 -m json.tool l10n/nl.json`
      or equivalent) after hand-editing.
- [ ] 3.3 Manually switch a test user's language to Dutch (`nl`) on a dev instance and
      click through Dashboard, Agents, Schedules, Approvals, Memory, Skills Marketplace,
      Settings — confirm no unexpected English strings remain on primary flows.

## Acceptance criteria

- Every key present (and non-empty) in `l10n/en.json` is present and non-empty in
  `l10n/nl.json`.
- No key remains in `l10n/nl.json` that has no corresponding key in `l10n/en.json`.

## Quality reminders

- i18n keys stay English (the `en.json` key, e.g. `"Agent not found"`) — never invent a
  Dutch key. This change only edits values in `nl.json`, never keys.
- No sed/awk/scripts on this JSON file — hand-edit via the Edit/Write tool and
  re-validate JSON after.

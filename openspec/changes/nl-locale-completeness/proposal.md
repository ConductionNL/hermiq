---
kind: code
---

# Proposal: nl-locale-completeness

# Why

`l10n/en.json` carries 115 translation keys — the real, current product strings ("Agent
not found", "Create agent", "Run now", "Attach a schedule to start recording run
history.", etc., all confirmed present via `l10n/en.json`). `l10n/nl.json` carries only
32 keys, and every one of them is scaffold-template boilerplate left over from the
app-template generation step: `"App Template settings"`, `"Placeholder: comment added"`,
`"Placeholder: status changed to Review"`, `"Starter overview with sample KPIs and
activity placeholders. Replace this view with your own data."`, `"app-availability.title"`,
`"sample"`, etc. (full list in `l10n/nl.json`). None of Hermiq's actual shipped strings —
agent management, schedules, approvals, memory, skills marketplace, analytics, tenant
ops — exist in the Dutch locale file at all.

That is 107 of 115 real keys (93%) with no Dutch translation. Per hydra ADR-007
(i18n: English primary, Dutch required) and ADR-010 (NL Design System — Dutch government
theming target), this app is positioned for Dutch government users, and Nextcloud's
l10n loader silently falls back to the English string for any key missing from
`nl.json` — so a Dutch-locale user sees an almost entirely English UI with no visible
error, warning, or degradation signal. `l10n/en_US.json` is present but empty
(`{"translations": {}, ...}`), which is normal (en_US inherits from en) and not part of
this finding.

This is a distinct, unrelated problem from `openspec/changes/remove-scaffold-leftovers`
(which removes dead scaffold *code* — the `example` schema/pages/MCP provider) — that
change does not touch `l10n/` at all. Confirmed by reading its proposal/tasks: no
mention of locale files.

# What Changes

- Populate `l10n/nl.json` with accurate Dutch translations for all 115 keys currently in
  `l10n/en.json`, replacing the 32 leftover scaffold-template keys that don't correspond
  to any real Hermiq string.
- Remove/replace the scaffold-only keys (`"App Template settings"`, `"Placeholder: ..."`,
  `"app-availability.*"`, `"sample"`, etc.) that don't map to any real product string —
  they are dead translation entries regardless of language.
- Not BREAKING: `l10n/en.json` (the i18n source-of-truth per ADR-025) is unchanged;
  this only affects the Dutch translation file's content.

# Impact

- Affected: `l10n/nl.json` only.
- Process note: per this fleet's i18n-keys-english convention, `en.json` keys stay the
  canonical English source strings; this change only fills in their Dutch values.

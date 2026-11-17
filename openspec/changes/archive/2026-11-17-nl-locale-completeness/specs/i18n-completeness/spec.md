# i18n-completeness (delta)

Ensures the Dutch locale file carries a translation for every real product string, instead
of leftover app-template scaffold keys that don't correspond to any shipped Hermiq string.

## ADDED Requirements

### Requirement: Every English translation key MUST have a non-empty Dutch translation
The system's `l10n/nl.json` MUST contain a non-empty translation for every key present in
`l10n/en.json`.

#### Scenario: A user sets their Nextcloud language to Dutch
- **GIVEN** a user with language preference `nl`
- **WHEN** the user views any Hermiq page whose strings are declared in `l10n/en.json`
- **THEN** every one of those strings MUST render in Dutch, not silently fall back to
  English

#### Scenario: An auditor diffs the two locale files
- **GIVEN** `l10n/en.json` and `l10n/nl.json`
- **WHEN** the set of keys in `en.json` is compared against `nl.json`
- **THEN** there MUST be zero keys present (non-empty) in `en.json` and missing/empty in
  `nl.json`

### Requirement: The Dutch locale file MUST NOT carry keys unrelated to shipped product strings
`l10n/nl.json` MUST NOT contain translation keys that have no corresponding key in
`l10n/en.json` (e.g. leftover app-template scaffold strings).

#### Scenario: An auditor inspects `l10n/nl.json` for orphan keys
- **GIVEN** `l10n/nl.json`
- **WHEN** each of its keys is checked against `l10n/en.json`
- **THEN** every key MUST have a matching key in `l10n/en.json`

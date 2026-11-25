# form-fill Specification

## Purpose
Hermiq turns pasted text into proposed values for a form's fields, for forms that offer smart paste (buildiq's handler over nextcloud-vue's `form-smart-paste` renderer). Hermiq is the only side that talks to a model; the feature stays off until an administrator acknowledges it, and every proposal is fitted to the allowed fields. Created by archiving change `forms-smart-paste-fill` (2026-11-24).

The consumer side is buildiq `ai-smart-paste-into-forms`: REQ-BQSP-003 there (the authored behaviour fixed for the renderer: signed-in users only, the pasted text and the allowed fields only, fill only empty visible allowed fields, values marked as suggestions, never submitted on its own) is what this feature serves, and its D4 request and response shape is the one under REQ-FFILL-002. Hand-over: `openspec/changes/archive/2026-11-24-forms-smart-paste-fill/handover-buildiq.md`.
## Requirements
### Requirement: Form fill is off until an admin acknowledges it (REQ-FFILL-001)

The system MUST seed an `AiFeature` with slug `form-fill`, risk category `limited` and lifecycle `disabled`. While it is not `enabled`, the fill endpoint MUST make no model call and MUST answer unavailable, and the availability endpoint MUST answer `{available: false}`.

#### Scenario: A built app hides Paste to fill while the feature is off
- GIVEN a fresh install where nobody has switched `form-fill` on
- WHEN the form renderer calls `GET /apps/hermiq/api/assistant/form-fill/available`
- THEN the answer is `{available: false}` and the form shows no "Paste to fill"

### Requirement: A signed-in user asks for proposals (REQ-FFILL-002)

`POST /apps/hermiq/api/assistant/form-fill` MUST require a signed-in user, MUST read only `text`, `fields` and `language`, and MUST answer 400 for a text over 10,000 characters, no fields, or more than 50 fields. Every call MUST write one log line with the outcome, the user, the provider, the text length and the field count, and without the text or the values.

#### Scenario: An oversized paste is refused before any model call
- GIVEN a signed-in user
- WHEN they post 10,001 characters of text
- THEN the answer is 400 and no model is called

### Requirement: Proposals fit the allowed fields (REQ-FFILL-003)

The answer MUST hold `values`, `draft: true` and a notice. A value MUST be returned only for a key among the given fields and only when it fits the field: one of its options when options are given, a number for a number field, an ISO date for a date field. A value the text does not state MUST be left out.

#### Scenario: An email signature fills three fields
- GIVEN the `form-fill` feature enabled, and the fields `naam` (text), `adres` (text), `telefoon` (text) and `soort` (options `particulier`, `zakelijk`)
- WHEN a user posts the signature "Jansen BV, Kerkstraat 1, 3511 AB Utrecht, 030 123 4567"
- THEN `values` holds `naam`, `adres` and `telefoon`
- AND `soort` is absent because the text does not state it
- AND the answer carries `draft: true` and the notice


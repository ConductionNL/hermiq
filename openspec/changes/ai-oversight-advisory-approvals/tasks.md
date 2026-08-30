## 1. Schema

- [x] 1.1 Add `advisory` to `Approval.sourceType`; document it as the only non-blocking variant.
- [x] 1.2 Add `overridden` to `Approval.status`; document it as advisory-only and terminal-at-creation.
- [x] 1.3 Add the `advisoryContext` object (originApp, suggestionType, action, subjectType, subjectId, model, suggestion, confidence, actualValue, responseTimeMs).
- [x] 1.4 Bump the Approval schema version (additive → minor, 0.4.1 → 0.5.0).

## 2. Contract

- [x] 2.1 `lib/Event/AiOversightRecordedEvent.php` — one associative payload, result slots for `approvalId` / `handled`.
- [x] 2.2 `lib/Service/AiOversightService.php` — validate, map humanAction → status, write the Approval.
- [x] 2.3 `lib/Listener/AiOversightRecordedListener.php` — synchronous, sets the result slots.
- [x] 2.4 Register the listener in `Application.php`.

## 3. Surface

- [x] 3.1 `/ai-oversight` dashboard: three status counts + an object-table filtered to `sourceType: advisory`.
- [x] 3.2 `/ai-oversight/:id` detail: one data widget, no lifecycle actions, audit history in the sidebar.
- [x] 3.3 Menu entry in the settings section.

## 4. Tests

- [x] 4.1 Unit tests for the service: each humanAction mapping, verbatim subject, every required-key refusal, unknown action, storage failure, missing timestamp.
- [ ] 4.2 e2e: the oversight surface renders and shows only advisory records.

## 5. Verify

- [x] 5.1 `npm run check:manifest` Ajv PASS.
- [x] 5.2 `phpunit --filter AiOversightServiceTest` green.
- [x] 5.3 Schema extension confirmed live after `POST /api/settings/load` (Approval 0.5.0, advisory + overridden + advisoryContext present).
- [x] 5.4 `/ai-oversight` renders with one `<h2>`, the three counts and the empty log state.
- [ ] 5.5 `composer check:strict`.

## Acceptance Criteria

- An advisory record from a consumer app becomes a terminal `Approval` with `sourceType: advisory`.
- `overridden` survives as its own status; the corrected value is kept beside the suggestion.
- An incomplete record is refused and logged, never stored half-formed.
- A storage failure never propagates to the dispatching app.
- `/approvals` still shows only actionable decisions; `/ai-oversight` shows only advisory ones.

## Quality Checklist

- No consumer app writes into hermiq's register; the event is the only path.
- `advisoryContext.subjectId` is not a `$ref` — hermiq never resolves another app's register.
- The gating variants and their state machine are untouched.

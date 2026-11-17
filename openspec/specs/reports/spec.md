# reports Specification

## Purpose

Hermiq gives people one place to read what its agents did: a Reports page that lists the reports the app has, each as a card that opens the report. Today it holds one report, AI oversight, the EU AI Act Art. 14 record of what a human did with each AI suggestion that was not gated. This spec describes the page as built (hermiq#765, moved under Advanced in hermiq#1106). The oversight report itself, how advisory decisions are recorded and what its log shows, is specified in `openspec/changes/ai-oversight-advisory-approvals`. Reports on a schedule are not built: every report is read on demand.

Code: `src/manifest.json` menu entry `ReportsMenu` (section `settings`, order 95), page `Reports` (route `/reports`, type `reports`) and page `AiOversight` (route `/ai-oversight`, type `dashboard`); `lib/Service/AiOversightService.php` writes the records the report reads.

## Requirements

### Requirement: The Reports page lists every report as a card

The system SHALL offer a Reports entry in the navigation that opens `/reports`, a page of type `reports` described as "Pick a report to open it." Each report SHALL appear as one card with a label, a one-line description and an icon, and selecting a card SHALL open that report's own route. A new report SHALL be added as a card in the manifest, with no change to the page itself.

#### Scenario: A person opens a report from the Reports page
- **GIVEN** a signed-in person in hermiq
- **WHEN** they choose Reports in the navigation
- **THEN** `/reports` shows the card "AI oversight" with the description "What the agents did, and what a human still has to answer for."
- **WHEN** they select that card
- **THEN** the AI oversight report opens at `/ai-oversight`
- @e2e exclude already driven by tests/e2e/app-chrome.spec.ts:93 ('AI oversight is a card on Reports'); its @e2e tag is not added here because this spec round changes no tests

### Requirement: The AI oversight report counts and lists advisory decisions

The AI oversight report SHALL read `Approval` objects in the `hermiq` register with `sourceType: "advisory"` only, so no pending gating approval appears in it. It SHALL show three counts, Accepted (`status: approved`), Overridden (`status: overridden`) and Rejected (`status: denied`), and an oversight log of the 50 newest decisions ordered by `decidedAt`, with the columns Decided at, App, Suggestion, Model, Human decision and By. The report SHALL read through OpenRegister's object API, so OpenRegister's access rules decide which records a person sees. The report SHALL stay reachable at `/ai-oversight` directly, because other apps link to it.

#### Scenario: The report shows the recorded decisions
- **GIVEN** an origin app recorded two accepted and one overridden AI suggestion through `AiOversightService::record()`
- **WHEN** a person opens `/ai-oversight`
- **THEN** Accepted reads 2, Overridden reads 1 and Rejected reads 0
- **AND** the oversight log lists the three decisions, newest first
- @e2e exclude needs advisory records seeded through an origin app's event; the record path is covered by PHPUnit in tests/Unit/Service/AiOversightServiceTest.php, and the page's e2e is task 4.2 of ai-oversight-advisory-approvals

#### Scenario: A gating approval stays out of the report
- **GIVEN** a pending approval raised by the approval gate (`sourceType` other than `advisory`)
- **WHEN** a person opens `/ai-oversight`
- **THEN** that approval appears in neither the counts nor the log
- @e2e exclude the advisory-only filter is task 4.2 of ai-oversight-advisory-approvals, not yet written

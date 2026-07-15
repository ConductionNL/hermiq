# algoritmeregister-publication Specification (delta)

**OpenSpec changes**:
- `algoritmeregister-publication` (original)
- `inapp-settings-section` (this delta — adds a dedicated UI)

## ADDED Requirements

### Requirement: The Algoritmeregister publication capability MUST be discoverable via a dedicated Settings page
The system MUST expose a dedicated in-app UI for the Algoritmeregister publication capability
(Settings → Algorithm register), independent of the AI-feature governance register's own
table. This UI MUST NOT re-implement any publish-readiness or delegation logic — it MUST call
the same, unmodified `AiFeatureController::publishToAlgoritmeregister()` /
`withdrawFromAlgoritmeregister()` endpoints the existing (relocating) AI-feature register
already calls, so the server-side `AlgoritmekaderMapper` readiness gate and `PublicationGateway`
delegation remain the single source of truth.

#### Scenario: The dedicated page reuses the existing publish endpoint, not a new one
- **GIVEN** the Settings → Algorithm register page
- **WHEN** a permitted caller publishes a feature from it
- **THEN** the system MUST call `POST /api/ai-features/{id}/publish` (the existing route) —
  no new backend endpoint MUST be introduced for this UI

#### Scenario: The page degrades gracefully without OpenCatalogi
- **GIVEN** OpenCatalogi is not installed
- **WHEN** a caller opens the Algorithm register tab
- **THEN** the list of high-risk `AiFeature` records MUST still render
- **AND** no Publish/Withdraw action MUST be offered (mirrors the existing
  `algoritmeregister-publication` graceful-degradation requirement)

@e2e exclude the underlying gate/delegation behavior is already unit-tested (AlgoritmekaderMapperTest, PublicationGatewayTest); this delta only adds a UI consumer of the unchanged endpoints, covered by test-plan.md TC-2/TC-3.

## Notes
This delta does not change any requirement from the original `algoritmeregister-publication`
spec (publish-gating conditions, delegation-not-reimplementation, graceful degradation without
OpenCatalogi) — it only adds a new, independent UI entry point. See the full requirement text
in `openspec/specs/algoritmeregister-publication/spec.md`.

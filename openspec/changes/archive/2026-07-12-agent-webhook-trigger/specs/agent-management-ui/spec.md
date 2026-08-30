## ADDED Requirements

### Requirement: Agent detail manages the webhook trigger in place [MVP]

The agent detail view MUST show whether a webhook trigger is configured for
the agent, its enabled state, masked secret prefix, and last-used time, and
MUST let the owner create, rotate, and revoke the webhook secret without
navigating away from the detail page (see `agent-webhook-trigger` for the
backend secret-lifecycle contract this panel drives). A newly created or
rotated secret MUST be shown in a copy-once reveal dialog that cannot be
reopened after dismissal — the panel never displays the full secret again
afterward, only its prefix.

#### Scenario: Creating a webhook from the agent detail page

- **GIVEN** an agent detail view for an agent with no webhook configured
- **WHEN** the owner clicks "Create webhook"
- **THEN** the system MUST create the secret and show it once in a
  copy-to-clipboard dialog
- **AND** the panel MUST subsequently show the webhook as enabled with a
  masked secret prefix, never the full secret

#### Scenario: Rotating a webhook secret from the agent detail page

- **GIVEN** an agent detail view showing an enabled webhook
- **WHEN** the owner rotates its secret
- **THEN** the system MUST show the new secret once in the same copy-once
  dialog
- **AND** the panel MUST reflect the updated `rotatedAt` timestamp afterward

#### Scenario: Revoking a webhook from the agent detail page

- **GIVEN** an agent detail view showing an enabled webhook
- **WHEN** the owner revokes it
- **THEN** the panel MUST show the webhook as disabled
- **AND** the trigger endpoint MUST reject subsequent requests for that agent

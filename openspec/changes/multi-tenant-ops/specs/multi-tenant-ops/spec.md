# multi-tenant-ops (delta)

Implements the Hermiq-ownable surface of `multi-tenant-ops`: quota reporting + advisory, a
per-tenant EU AI Act audit export, and verified native isolation. Create-time hard quota
reject + the authoritative agent inventory are documented OpenRegister seams.

## ADDED Requirements

### Requirement: Per-organisation quota reporting
The system MUST report the caller's organisation usage — schedules and agents-in-use —
against a configurable per-organisation limit, and MUST advise when a limit is reached.
The authoritative create-time hard reject is an OpenRegister seam (creation flows through
OR's object API).

#### Scenario: An organisation views its quota usage
- **GIVEN** an organisation with schedules and agents
- **WHEN** the quota endpoint is called
- **THEN** the current schedule + agent counts, the configured limits, and an at-limit flag
  MUST be returned for the caller's organisation

### Requirement: Strict per-tenant isolation across all object types
Every object type Hermiq introduces (schedules, memory, skills, approvals, sessions) MUST
carry `organisation`/`owner`/`groups`, and no API response MUST include another tenant's
objects.

#### Scenario: A tenant-scoped read excludes other organisations
- **GIVEN** organisations A and B each with their own Hermiq objects
- **WHEN** a user in organisation A reads any Hermiq object type or the audit export
- **THEN** only organisation A's objects/records MUST be returned; organisation B's MUST NOT

### Requirement: Per-tenant EU AI Act audit export
The system MUST provide a per-tenant export of AI Act-relevant audit records scoped strictly
to the caller's organisation, sourced from OpenRegister's hash-chained `AuditTrail` (redacted
at write), and MUST NOT require data to leave the local instance when local inference is
configured.

#### Scenario: An org admin exports their AI Act audit trail
- **GIVEN** an organisation with run + decision history in OR `AuditTrail`
- **WHEN** an org admin requests an AI Act audit export
- **THEN** the export MUST contain only that organisation's records (the caller's own
  objects' audit entries)
- **AND** it MUST be producible entirely on the self-hosted instance

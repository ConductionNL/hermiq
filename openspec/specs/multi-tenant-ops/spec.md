# Multi-Tenant Ops Specification

**Status**: idea

**Feature tier**: V2

**OpenSpec changes:** _(none yet)_

## Purpose

Adds MSP/organisation-level operational controls on top of Hermiq's multi-tenant foundation: per-org
agent and schedule quotas, strict per-tenant isolation across all object types, and sovereignty
features such as local Ollama inference and per-tenant EU AI Act audit export. Builds directly on
OpenRegister's `organisation`/`owner`/`groups` model rather than introducing a parallel tenancy layer.

## Requirements

### Requirement: Per-organisation agent and schedule quotas
The system MUST enforce a configurable maximum number of agents and active schedules per
organisation, rejecting creation attempts that would exceed the quota.

#### Scenario: An organisation attempts to exceed its agent quota
- GIVEN organisation A has a configured quota of 10 agents and currently has 10 agents
- WHEN a user in organisation A attempts to create an 11th agent
- THEN the system MUST reject the creation request
- AND the system MUST inform the user that the organisation's agent quota has been reached

### Requirement: Strict per-tenant isolation across all object types
Every object type Hermiq introduces (agents, schedules, memory, skills, approvals, runs) MUST carry
`organisation`/`owner`/`groups` fields, and no API response MUST include another tenant's objects.

#### Scenario: A cross-tenant list request is made
- GIVEN organisations A and B each have their own agents, schedules, and memory objects
- WHEN a user in organisation A requests a list of any Hermiq object type
- THEN the system MUST return only objects belonging to organisation A
- AND objects belonging to organisation B MUST NOT appear in the response

### Requirement: Per-tenant sovereignty — local inference + AI-Act export
The system MUST allow each organisation to configure local-only inference (Ollama/Qwen) and MUST
provide a per-tenant export of AI Act-relevant audit records scoped strictly to that organisation.

#### Scenario: An org admin exports their AI Act audit trail
- GIVEN organisation A has run history recorded in OR `AuditTrail`
- WHEN an org admin of organisation A requests an AI Act audit export
- THEN the system MUST produce an export containing only organisation A's records
- AND the export MUST NOT require data to leave the local/self-hosted instance when local Ollama inference is configured

## User Stories

- As an MSP operator, I want to set per-org agent and schedule quotas so that one tenant cannot exhaust shared infrastructure.
- As a tenant admin, I want absolute certainty that my organisation's agents, memory, and skills are invisible to other tenants so that I can trust the platform with sensitive data.
- As a sovereignty-conscious customer, I want to run inference entirely locally via Ollama so that no data leaves my infrastructure.
- As a compliance officer, I want a per-tenant AI Act audit export so that I can respond to regulator requests without manual data extraction.

## Acceptance Criteria

- [ ] Configurable per-organisation agent quota is enforced at creation time
- [ ] Configurable per-organisation schedule quota is enforced at creation time
- [ ] Every Hermiq object type carries organisation/owner/groups and is filtered accordingly on read
- [ ] Local Ollama/Qwen inference can be configured per organisation
- [ ] AI Act audit export is available per-tenant and excludes other tenants' data

## Notes

Depends on OpenRegister's `ObjectEntity` (owner/organisation/groups/version/locked/deleted) and
`AuditTrail` GDPR/DSAR endpoints, and on the existing LLPhant `OllamaChat` local-Qwen path. Related:
ADR-001 (Option C+ — multi-tenant via Nextcloud groups), ADR-004 (governance via OR AuditTrail).
Quota enforcement mechanism (hard block vs. soft warning) is an open question for the `planned` spec.

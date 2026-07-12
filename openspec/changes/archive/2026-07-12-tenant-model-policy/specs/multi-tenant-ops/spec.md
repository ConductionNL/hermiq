# multi-tenant-ops (delta)

Extends the existing sovereignty requirement with enforced model-policy scenarios: "local
inference is possible" (already shipped) becomes "local inference — or any other provider
restriction — is enforced," closing the gap between an available option and a binding
guarantee. See the new `tenant-model-policy` capability for the full policy object and
enforcement mechanics; this delta only extends the sovereignty requirement's scenarios.

## MODIFIED Requirements

### Requirement: Per-tenant sovereignty — local inference + AI-Act export
The system MUST allow each organisation to configure local-only inference (Ollama/Qwen) and
MUST provide a per-tenant export of AI Act-relevant audit records scoped strictly to that
organisation. The system MUST also allow each organisation to configure a `ModelPolicy` that
enforces which chat providers and models its agents may use, and MUST refuse — with a clear,
audited error — any agent run that would resolve to a provider or model outside that
organisation's effective policy, so that a sovereignty guarantee (e.g. "no data leaves this
instance") is a binding constraint rather than an unenforced configuration option.

#### Scenario: An org admin exports their AI Act audit trail
- **GIVEN** organisation A has run history recorded in OR `AuditTrail`
- **WHEN** an org admin of organisation A requests an AI Act audit export
- **THEN** the system MUST produce an export containing only organisation A's records
- **AND** the export MUST NOT require data to leave the local/self-hosted instance when local
  Ollama inference is configured

#### Scenario: An organisation enforces a no-external-cloud model policy
- **GIVEN** organisation A has configured a `ModelPolicy` allowing only the `ollama` provider
- **WHEN** any agent belonging to organisation A attempts to run using the `openai` or
  `fireworks` provider (whether via an agent's own override or the instance's configured
  provider)
- **THEN** the system MUST refuse the run before any request reaches an external provider
- **AND** the refusal MUST be recorded in the run's audit entry, giving the organisation
  verifiable proof — not just a configuration assertion — that no data left the local
  instance

## Non-Functional Requirements

- **Performance:** unchanged from the existing spec.
- **Accessibility:** unchanged from the existing spec.
- **Internationalization:** Dutch and English MUST be supported (ADR-005) for the
  policy-refusal error surfaced to the org admin/user.

## Acceptance Criteria

- [ ] Configurable per-organisation agent quota is enforced at creation time
- [ ] Configurable per-organisation schedule quota is enforced at creation time
- [ ] Every Hermiq object type carries organisation/owner/groups and is filtered accordingly on read
- [ ] Local Ollama/Qwen inference can be configured per organisation
- [ ] AI Act audit export is available per-tenant and excludes other tenants' data
- [ ] An organisation's `ModelPolicy` is enforced at run time, not merely advisory, and a
      refused run is provably auditable

## Notes

Full mechanics (the `ModelPolicy` schema, resolution order, enforcement seam) live in the new
`tenant-model-policy` capability spec; this delta only records that the sovereignty
requirement now includes an enforced guarantee, not just an available local-inference option.

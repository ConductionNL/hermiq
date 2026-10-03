# Tasks: a-provider-and-a-place-per-ai-feature

## Implementation Tasks

### Task 1: Bind a provider and model to an AI feature
- **spec_ref**: `openspec/changes/a-provider-and-a-place-per-ai-feature/specs/ai-feature-governance/spec.md`
- **acceptance_criteria**:
  - `AiFeature` carries an optional `provider` and `model`
  - An unbound feature resolves to the effective `ModelPolicy` default, as today
  - A binding outside the effective policy is refused at write time, naming the policy

- [x] Add the fields to the `AiFeature` register fragment
- [x] Add the write-time narrowing check

### Task 2: Resolve and re-check on every turn
- **acceptance_criteria**:
  - Resolution order is feature binding, then policy default
  - The resolved pair is checked against the effective policy on every turn, whatever
    the trigger
  - A policy narrowed after the binding was written refuses the next run

- [x] Fold the binding into the existing model-policy resolution, not beside it

### Task 3: Residency on a configured provider
- **acceptance_criteria**:
  - `residency` is one of `on-premise`, `eu`, `outside-eu`, plus a free-text
    `location`
  - Nothing infers residency from a hostname or an address

- [x] Add the fields to the provider configuration
- [x] Add the admin surface for them

### Task 4: Refuse a run outside a required residency, before the call
- **acceptance_criteria**:
  - Order is: resolve binding, narrow by policy, check residency, call
  - No request reaches the provider when residency refuses
  - Each refusal names the check that refused it

- [x] Add `requiredResidency` to `AiFeature`
- [x] Add the check in the existing pre-call path

### Task 5: Record the answer on the run
- **acceptance_criteria**:
  - Each run entry carries feature, provider, model, residency and location
  - Residency is copied, not referenced, so relabelling does not rewrite history

- [x] Extend what `run-audit-log` writes

### Task 6: Verification
- [x] Unit tests for the narrowing check, the stale-binding refusal and the
      pre-call ordering
- [x] e2e coverage or a reason-bearing exclusion per scenario, per gate 19

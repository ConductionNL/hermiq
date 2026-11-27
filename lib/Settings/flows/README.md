<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->
<!-- SPDX-License-Identifier: EUPL-1.2 -->
# Bundled flow definitions

Copies of hydra's `flows/*.flow.json` (ConductionNL/hydra, `development` at 402b5ce),
seeded on install and upgrade by `OCA\Hermiq\Repair\SeedBundledFlows`.

- **hydra is the source of truth.** Do not edit these files here; change the flow in
  hydra and re-vendor with `scripts/sync-bundled-flows.sh <hydra checkout>`.
- Each flow is seeded **disabled and without an owner**, once, by name. A flow that
  already exists under that name (seeded earlier, or imported by hand) is never
  touched again, so an operator's edits survive every upgrade.
- `$bindings` names what an operator must fill in (agent uuids, source ids) before
  enabling a flow; those are instance-specific and cannot ship in a definition.
- Left out on purpose: `hydra-triage` (hermiq's `SeedHydraTriageFlow` owns
  "Hydra Triage") and `hydra-applier-live-4-1-proof` (a provenance duplicate).

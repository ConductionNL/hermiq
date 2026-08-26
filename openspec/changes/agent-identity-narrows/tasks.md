# Tasks — agent-identity-narrows

Implements ADR-099 in hermiq. Supersedes `agent-capability-profile` task 3-1.

## 1. A document may not name the identity

- [x] 1.1 Remove `$config['owner']` from `HermiqAgentNode` and `HermiqWorkloadNode`; both read the acting identity from the run context only.
- [x] 1.2 Rewrite the twelve `HermiqWorkloadNodeTest` cases that supplied the identity as `config['owner']` with an EMPTY context. They depended on the escalation path itself, so no test exercised how a node actually receives an identity in production.
- [x] 1.3 Add a negative control asserting a config-supplied `owner` cannot override a context identity. Asserting only "the context identity is used" would pass against the OLD code too, which also used the context whenever config carried nothing — the conflict is what distinguishes them.

## 2. A declared identity is never substituted

- [x] 2.1 `ScheduleService::resolveActingUser()` refuses when a declared `actingUser` does not resolve to an enabled user, instead of falling back to the schedule owner.
- [x] 2.2 Keep the UNDECLARED fallback. Expressing no preference is not the same as naming an identity that has gone, and conflating them is the defect.
- [x] 2.3 Invert `testActingUserFallsBackToOwnerWhenNonexistent`, asserting the engine is NEVER invoked — a refusal that still ran the agent would be a refusal in name only.

## 3. Follow-ups, deliberately not here

- [x] 3.1 Switch both nodes from `context['triggeredBy']` to `context['runAs']`.

  Done as `runAs` FIRST with `triggeredBy` as a fallback, rather than as a
  straight swap. The two answer different questions — `triggeredBy` is PROVENANCE
  (who caused this), `runAs` is AUTHORIZATION (whose rights the work uses) — and
  they are equal for a run a person started, differing exactly where it matters:
  a scheduled run, whose cause is a schedule and whose acting identity is the
  user its trigger declares. An agent turn and a workload stage are both access
  decisions, so they read the authorization field.

  ⚠️ **The fallback is a COMPATIBILITY SHIM with a known end, not a design.**
  `runAs` reaches the node context from openregister#2835. On an engine older
  than that build `triggeredBy` is the only identity there has ever been, so
  falling back reproduces the old behaviour rather than inventing one. Reading
  `runAs` alone would make these nodes refuse every turn against an un-upgraded
  instance — an outage that looks like a safety property. Remove the fallback
  once the fleet's target instances are known to carry #2835.

  Both tests assert the CONFLICT (`triggeredBy: schedule-cause`, `runAs: ruben`),
  because a case where only one key is present passes against the old code too
  and distinguishes nothing. Each is paired with a positive control proving the
  fallback still works. Verified by reverting the node line: exactly one test
  fails, and restoring it makes it pass.
- [ ] 3.2 Relocate the capability-grant grammar (`ToolGrantSet`, `ToolGrantCodec`, `ToolReachResolver`) to OpenRegister per ADR-099 §5 — a relocation WITH its tests, not a rewrite. That codec carries a measured scar (35 of 87 tools parsed wrong) and ADR-095's persistence constraint; a rewrite-while-moving reopens both.

# repair-steps Specification

## Purpose

Every hermiq repair step that WRITES through OpenRegister runs during
`occ upgrade`, and an upgrade has no session. OpenRegister then resolves the
acting user as `Anonymous` and REFUSES the write before validation is reached —
probing five registers (`decidiq`, `shillinq`, `trust-configuration`, `stackiq`
and `dossiq`) with a deliberately invalid payload, so that nothing could be
created either way, every one answered `User 'Anonymous' does not have permission
to 'create'`.

The failure is silent in both directions. A step reports it with
`$output->warning()`, which does not fail an upgrade, so the upgrade prints
"Update successful" while nothing was written. On an instance whose data already
exists it is quieter still: the step's idempotency check skips, so it never
attempts the write that would have failed — which is why this survived across so
many apps.

This capability owns the invariant that removes it: a writing repair step
executes its work under OpenRegister's system identity, degrades rather than
refuses when OpenRegister is absent, and is proven to do so by a test double that
models the elevation instead of stubbing it away.

The per-step *content* — which objects a seed writes, and what they contain —
belongs to that step's own capability, not here. This spec governs only the
identity the work runs under and the shape of the helper that establishes it.

## Requirements

### Requirement: A writing repair step runs its work under OpenRegister's system identity

The system MUST execute the write portion of every repair step that creates or
patches OpenRegister objects inside `ObjectService::runAsSystem()`, via the shared
`OCA\Hermiq\Repair\Support\RunsUnderSystemIdentity` trait.

A per-call `_rbac: false` on the step's own writes is NOT sufficient and MUST NOT
be treated as an alternative: the refusals also arrive from writes further down
the call chain, where no per-call flag reaches. Measured in a sibling app, a step
that flagged every one of its own writes still failed eight times.

#### Scenario: A seed writes under an elevated identity

- GIVEN a repair step whose `run()` creates OpenRegister objects
- WHEN the step executes with an ObjectService that exposes `runAsSystem()`
- THEN the step establishes the system identity exactly once
- AND every `saveObject()` / `patchObject()` call it makes happens while that
  identity is active

@e2e exclude Backend-only `occ upgrade` write path with no UI surface; asserted by
`SeedAiFeaturesTest::testSeedsRunUnderTheSystemIdentity()`,
`SeedCourseRecommendationFeatureTest::testTheSeedRunsUnderTheSystemIdentity()` and
`SeedPairedEvalDatasetTest::testTheSeedRunsUnderTheSystemIdentity()`.

### Requirement: The helper falls through rather than refusing when OpenRegister cannot supply an identity

The system MUST run the step's work unelevated when the resolved object service is
`null` or does not expose `runAsSystem()`, rather than aborting the step.

Adding an identity MUST NOT cost the degradation behaviour that was there before:
each step already handles its own write failures, and an eager resolve that
aborted on failure would turn a recoverable install into a dead one.

#### Scenario: No object service means the work still runs

- GIVEN a repair step whose object service is `null`, or is an object with no
  `runAsSystem()` method
- WHEN the step's work is passed to `withSystemIdentity()`
- THEN the work is invoked exactly once, unelevated
- AND no exception is raised by the helper itself

@e2e exclude Backend-only helper branch with no UI surface; asserted by the
`testNoopsWhenOpenRegisterUnavailable()` case on each repair step's test class.

### Requirement: A repair step never aborts the repair pass

The system MUST catch write failures inside a repair step, report them on the
`IOutput` channel, and continue — both to the next seed row within a step and to
the next step in the pass.

#### Scenario: One failed write does not stop the seeds that follow it

- GIVEN a repair step that seeds several rows
- AND the object service throws on every write
- WHEN the step runs
- THEN each row is still attempted
- AND the failure is reported through `$output->warning()`
- AND no exception escapes `run()`

@e2e exclude Backend-only `occ upgrade` failure path with no UI surface; asserted by
`SeedAiFeaturesTest::testAFailingWriteIsReportedAndDoesNotAbortTheRest()` and
`SeedCourseRecommendationFeatureTest::testAFailingWriteIsReportedAndNotThrown()`.

### Requirement: A test double for a writing repair step models the elevation

The system's unit tests MUST use an ObjectService double whose `runAsSystem()`
INVOKES the callable it is given.

Both failure modes of this double are silent and opposite, which is why the
requirement is stated rather than left to convention:

- A double that omits `runAsSystem()` entirely leaves `method_exists()` false, so
  the helper takes the fall-through branch. The elevated path — the entire point
  of the helper — is then never executed by any test, and a step that forgot it
  still looks green. This is the shape that let the defect ship: sixteen passing
  tests could not have caught it.
- A bare `createMock()` stubs `runAsSystem()` to return `null` WITHOUT running
  anything, so the step's whole body silently does not execute and every
  assertion fails against an empty store — making a CORRECT step look broken.

The corresponding stub at `tests/Stubs/Service/ObjectService.php` therefore
declares `runAsSystem()` and invokes its argument, matching the real
`SystemOperationContext::run()`.

#### Scenario: A standalone unit run exercises the elevated branch

- GIVEN a standalone unit run, where `OCA\OpenRegister\Service\ObjectService`
  resolves to the test stub
- WHEN a repair step calls `withSystemIdentity()`
- THEN `method_exists($objectService, 'runAsSystem')` is true
- AND the callable passed to `runAsSystem()` is invoked

@e2e exclude Test-harness invariant with no runtime UI surface; asserted by the
elevation assertions listed under the first requirement, which fail if the stub
stops invoking its callable.

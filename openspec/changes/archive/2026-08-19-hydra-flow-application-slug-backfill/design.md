# Design: hydra-flow-application-slug-backfill

## Architecture Overview

hermiq owns exactly one repair step that writes into OpenRegister's native flow store:
`lib/Repair/SeedHydraTriageFlow.php`, which seeds the "Hydra Triage" agentflow (ADR-065).
Confirmed by `grep -rl FlowMapper lib/` returning only that one file, and by a live query
of `GET /apps/openregister/api/flows?app=hermiq&_limit=100`, which returns 16 flows: 4
hermiq "Demo — ..." fixtures, and 12 hydra-pipeline flows of which only "Hydra Triage"
traces back to committed hermiq seed code. The other 11 ("Hydra dispatch", "Hydra
sequencer", "Hydra applier" [+ a live-proof variant], "Hydra lock reaper", "Hydra label
transition", "Hydra retry and escalate", `hydra-record-stage`, `hydra-file-findings`,
"Hydra commit by API", "Hydra analyze verdicts") exist as `*.flow.json` files in the
separate `hydra` repository's `flows/` directory, deployed independently of hermiq's
install/upgrade lifecycle — `hydra/flows/README.md` states the reason explicitly: they live
outside any app's seed code "because... the flow decides what runs next, and that decision
is hydra's, not a leaf app's" (`hydra-flows-first-port` design decision D3). This change
therefore touches exactly one hermiq file's seeding logic, not eleven.

## Nextcloud Integration
- Repair step: `OCA\Hermiq\Repair\SeedHydraTriageFlow` (`OCP\Migration\IRepairStep`),
  registered in `<install>`/`<repair-steps>` in `appinfo/info.xml` (unchanged by this
  change).
- Mapper: `OCA\OpenRegister\Db\FlowMapper` (extends Nextcloud's `QBMapper`) — already used
  via `insert()`; this change adds a call to its inherited `update()`.
- Entity: `OCA\OpenRegister\Db\Flow` — gains a `setApplicationSlug()`/`getApplicationSlug()`
  call site here (the accessor itself is declared by the companion openregister change, via
  `Entity::__call` magic methods, matching every other accessor on this class).

## Create-vs-backfill mechanism (read from the actual code, not assumed)

`SeedHydraTriageFlow::run()` today:

```php
if ($this->flowExists(mapper: $mapper) === true) {
    $output->info('Hydra Triage flow already present — skipped.');
    $this->recordOutcome(outcome: 'present');
    return;
}
```

`flowExists()` returns a bare boolean — it walks `findAllFlows()`, matches by `getName()`,
and returns `true`/`false`. The found branch does nothing else: no field on an existing row
is ever read or rewritten. `SeedHydraTriageFlowTest::testRunDoesNotInsertWhenTheFlowIsAlreadyPresent()`
pins this with `$mapper->expects($this->never())->method('insert')`.

This is unambiguously the **skip-entirely-if-found** shape (not the re-apply-some-fields
shape `SeedSkillCreator.php` uses for its `lastActivityAt` freshness refresh). A payload
change to `flowObject()` alone would therefore never reach the ~1 already-seeded row on any
instance that ran this repair step before this change existed — exactly the situation the
task set out to check for.

**Decision:** replace the boolean `flowExists()` with `findExisting(): ?Flow`, returning the
matched entity instead of a bool, and add a narrow backfill inside the existing found
branch:

```php
$existing = $this->findExisting(mapper: $mapper);
if ($existing !== null) {
    $this->backfillApplicationSlug(mapper: $mapper, flow: $existing, output: $output);
    $this->recordOutcome(outcome: 'present');
    return;
}
```

`backfillApplicationSlug()` reads `getApplicationSlug()`; if non-empty, it does nothing
(matches REQ-003 — never overwrite a value already present, whether from an operator's own
retag or a previous run of this same backfill). If empty, it sets the constant
`hydra-console` and calls `$mapper->update($flow)`, wrapped in the same try/catch-and-log
shape already used elsewhere in this class (a failed backfill is non-fatal and simply
retries on the next repair run).

The create path (new-flow branch) gets one added line, `$flow->setApplicationSlug(self::APPLICATION_SLUG);`,
alongside the existing `setOrganisation()`/`setEnabled()`/`setOwner()` calls before
`insert()`.

### Why not extend `flowObject()` alone
`flowObject()` is a pure array builder consumed only by the create path and by the unit
tests that assert shape (`testTheFlowDeclaresItsTrigger()` etc. — none of them exercise
`run()`'s write path). Adding `applicationSlug` there is necessary for the create path but,
per the trace above, insufficient on its own for any instance where the row already exists —
hence the separate `backfillApplicationSlug()` on the found branch.

### Why `update()` and not delete+reinsert
`FlowMapper extends QBMapper`, which provides `update()` alongside the already-used
`insert()` — the standard Nextcloud entity-mapper pattern, persisting only the entity's
tracked dirty fields. Only `applicationSlug` is set on the fetched entity before the call,
so only that column is written; a delete+reinsert would needlessly touch `id`/`uuid`
identity and risk losing anything OpenRegister tracks by primary key (e.g. flow-run
history foreign keys).

## Application slug: `hydra-console` (verified, not assumed)

Two independent sources agree:

1. **Source code**, `openbuild/tests/Unit/Service/FlowAndAgentExportBundlerTest.php`: the
   literal `'hydra-console'` is passed as the export bundler's application argument
   throughout the suite, and is asserted as the exact value that must appear in an
   `ObjectService::findAll()` filter: `'agents must be looked up by the application they
   point at'` — i.e. `hydra-console` IS the `applicationSlug` filter value openbuild itself
   uses to select this application's bound objects.
2. **Live data**, `GET /apps/openregister/api/objects/openbuild/application`: returns an
   Application object named `"Hydra Console"` with `"slug": "hydra-console"`
   (`93941bf0-18ac-4d50-97fd-1daef540911d`).

This is deliberately distinct from the register slug `openbuild-hydra-console-production`
(`GET /apps/openregister/api/registers/2513`), whose own `description` says what it is: "Per-version
schema namespace for OpenBuild app `hydra-console` version `production`" — a register, not
the Application object. Using the register slug would have been a plausible-looking but
wrong value; the task instructions flagged this exact register id as the entry point
specifically to be checked against, not copied.

## File Structure

```
lib/
  Repair/
    SeedHydraTriageFlow.php      # findExisting() replaces flowExists(); backfillApplicationSlug()
                                  # added; APPLICATION_SLUG constant added; setApplicationSlug()
                                  # added to both the create path and flowObject()
tests/
  Unit/Repair/
    SeedHydraTriageFlowTest.php  # new/updated cases for create-path slug + backfill-path
  Stubs/Db/
    Flow.php                     # + @method getApplicationSlug()/setApplicationSlug()
    FlowMapper.php                # + update() (inherited from QBMapper on the real class)
```

## Security Considerations

No security impact — this reads and writes one additional non-secret string field on a
system-context repair step that already runs with no user session and no RBAC (matches
the existing `flowExists()`/`findAllFlows()` call, which is also unscoped by RBAC). No new
input is accepted from any request; the value written is a hardcoded class constant.

## Trade-offs

- **Hardcoded slug constant vs. a resolved lookup**: `SeedHydraTriageAgent`'s
  `triageAgentUuid()` resolves its cross-seed reference dynamically (by name, at seed time)
  because the referenced agent is *also seeded by hermiq* and could not exist yet at
  install time in some ordering. `hydra-console` is different: it is an OpenBuild
  Application that exists independently of hermiq's own install sequence, has no
  hermiq-owned seed step that could race it, and — per Risk 1 in the proposal — is a single
  named constant if it ever needs to change. A live lookup would add a new soft dependency
  on `openbuild`'s `ObjectService` schema being installed, for a value that is not expected
  to change at runtime.
- **Scoping this change to hermiq's one flow vs. also touching the `hydra` repo's 11**: the
  task explicitly scoped this change to hermiq only. Investigation showed the other 11 are
  not reachable from hermiq's codebase at all (see Architecture Overview) — extending scope
  to them would mean editing a different repo's `flows/*.flow.json` deployment path, which
  is out of bounds for this change and is called out as an open item for a possible future
  `hydra`-repo change instead.

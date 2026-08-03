# Proposal: hermiq-seed-hydra-flows

## Summary

Seed the ten `hydra/flows/*.flow.json` definitions on install, so an instance
arrives with hydra's pipeline flows instead of waiting for someone to POST them
by hand.

## The blocker in the brief does not exist

This change was framed around a hard obstacle:

> Seeding needs a user session. `IRepairStep` runs with NO session, so the
> obvious "seed on install" fails closed with `User 'Anonymous' does not have
> permission to 'create' objects in schema 'Agent flow'`. This already breaks the
> fresh-install path for EVERY app that seeds via IRepairStep.

**That is not what the code does, and not what the live instance shows.**

`ObjectService::saveObject()` takes `_rbac` and `_multitenancy` flags, and hermiq
already uses them from a repair step. `lib/Repair/SeedHydraTriageFlow.php`:

```php
$objectService->saveObject(
    object: $this->flowObject(),
    register: self::REGISTER_SLUG,
    schema: self::FLOW_SCHEMA,
    _rbac: false,
    _multitenancy: false
);
```

That step is registered in `appinfo/info.xml` (both `<pre-migration>` and
`<post-migration>`), it seeds an **agentflow object** into the **same register and
schema** these ten flows would land in, and its result is on the live instance
right now:

```
name          | _owner      | enabled
Hydra Triage  | __system__  | f
```

Owner `__system__`, not `Anonymous`. **Thirteen** hermiq repair steps use this
pattern. Object seeding from an `IRepairStep` is a solved, shipped problem here;
the fresh-install path is not broken by it.

So this change is not blocked on anything. It is ordinary work, and the only
reason it is a proposal rather than a PR is the question in the next section.

## The real open question: where do the flow definitions live?

The ten definitions are in the **hydra** repo, which is not a Nextcloud app and
has no repair steps. The seeder must be a hermiq artifact. That leaves a choice
nobody should make unilaterally in a bug-fix PR:

**(a) Vendor the JSON into hermiq** — `lib/Settings/flows/*.flow.json`, iterated
by one repair step. Straightforward, and it matches how `hermiq_register.json`
already ships. Cost: two copies of every flow, and no mechanism keeps them in
step. Hydra edits a flow, hermiq keeps seeding last month's.

**(b) Keep hydra canonical and pull at install time.** No duplication, but it
puts a network fetch in a repair step, which must then fail soft — and a
soft-failing seeder is exactly the silent-success shape this batch of work exists
to remove.

**(c) Ship them as an OpenRegister configuration** and import via
`ConfigurationService::importFromApp()`, the way openregister's own
`ImportFlowRegister` repair step handles register content. This is the most
idiomatic option and the one worth costing first. Note the known trap: a
non-forced `importFromApp` advances the version WITHOUT applying the change.

Recommendation: **(c)**, falling back to **(a)**.

## What the seeder must do

Settled by the existing pattern, not open for redesign:

- **Disabled, with no owner.** `SeedHydraTriageFlow` documents the reason and it
  applies verbatim: a trigger fires with no acting user, so a flow's `owner` must
  be a real NC UID and its runs act as that person. An ownerless flow must not
  dispatch. Enabling it is the deliberate human act that supplies the owner.
- **Idempotent** — check for an existing flow of that name before writing, as
  `SeedHydraTriageFlow::flowExists()` does.
- **Preflight before writing.** With openregister's new `FlowNodePreflight` in
  place (or#2254), a seeded flow naming a step type the instance cannot run is
  refused at the save. The seeder should call `POST /api/flow/validate` (or the
  service directly) and report per flow, so an install that legitimately lacks
  openconnector gets a clear warning rather than a failed step.
- **Do NOT swallow the failure.** `SeedHydraTriageFlow` catches every `Throwable`
  into `$output->warning()`, so a seed that fails still reports a successful
  install. That is the same green-but-dead shape as the rest of this batch. The
  new seeder should surface a failure that is not "the optional app is absent".

## Current state on the live instance

Two of the ten are present, both hand-seeded (`_owner = admin`, `enabled = f`):
`hydra-file-findings`, `hydra-record-stage`. The other eight are absent. All
three node-owning apps (`openregister`, `openconnector`, `hermiq`) are enabled,
so every step type in all ten resolves — this instance would seed cleanly.

## Not proposed

Enabling the seeded flows. Every one of them writes to the forge; a flow that
arrives enabled and unattributed would start doing that on someone else's
credential the moment a trigger fires.

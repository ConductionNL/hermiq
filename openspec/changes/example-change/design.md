# Design — example-change

> ⚠️ **EXAMPLE DESIGN DOC** — Design decisions recorded here are for the
> template's own reference material, not for a real app you are building.
> Use this file as a worked example of what a `design.md` looks like inside
> a real OpenSpec change.

## Context

The template's `lib/**/*.php` code is peppered with `@spec openspec/changes/example-change/tasks.md#task-N` docblock tags (nine tasks in total) that originally pointed at nothing — the change directory did not exist. This change fills in the missing scaffold so the template is internally consistent and so future `openspec-*` skill runs over a fresh clone resolve the references cleanly.

The change was not built forward in time — the code came first, the specs came second. That makes this a **retrofit-shaped** change in substance even though the `example-change` name reads as if the change describes a forward build. The `example: true` frontmatter on every spec makes the demonstration-only intent explicit to readers.

## How REQs were derived

Each spec was written by reading the implementing file(s) and documenting observable behaviour: inputs, outputs, preconditions, postconditions, failure modes. Where a docblock named an ADR (ADR-003 thin controllers, ADR-004 path-based deep links, ADR-005 per-object authorisation, ADR-006 observability endpoints, ADR-011 schema.org Article), the spec carries the same reference so that the architectural rationale and the concrete REQ live one click apart.

No aspirational behaviour was added. Where the template's code is a stub (e.g. `MetricsController` publishes a hardcoded version `0.1.0` as a placeholder rather than reading the actual app version), the spec documents the stub state with a `TODO`-flavoured note rather than pretending the implementation is production-ready.

## Mapping tasks to capabilities

Seven capabilities were carved from nine tasks along natural boundaries:

- API + Service layers for the same feature share a capability (`settings-management` covers both `SettingsController` and `SettingsService`).
- Nextcloud framework-integration classes (`ISettings` + `IIconSection`) share a capability (`admin-ui`) because they are joined by a section-id string that they MUST keep in sync.
- `MetricsController` + `HealthController` share `observability` because ADR-006 requires both endpoints together and they're useless apart.
- `DashboardController` is its own capability because the SPA entry is independent of any feature domain.
- `DeepLinkRegistrationListener` is its own capability because the Nextcloud event subscription pattern is a distinct architectural concern.

## task-5 and the manifest-renderer refactor

An earlier draft of the template shipped a standalone `ItemController` +
`ItemService` pair to demonstrate the ADR-005 per-object authorization pattern,
and task-5 annotated both that pair and the `Repair\InitializeSettings` first-
install step. The template's UI has since been refactored to the manifest-
renderer pattern (hydra ADR-024), and the bespoke `Item*` files were removed.

As a result task-5 now covers only the real, present code: the
**first-install repair step** in `Repair\InitializeSettings` that imports the
app's bundled JSON configuration via OpenRegister (`configuration-initialization`).

The ADR-005 per-object authorization pattern that the `item-management`
capability documents is now demonstrated concretely by `ActionAuthService`
(ADR-023, `openspec/architecture/adr-023-action-authorization.md`). The
`item-management` spec is retained as the canonical documentation of the
per-object-auth REQ shape that template-derived apps adopt when they add real
mutation endpoints; it no longer points at deleted `Item*` files.

## What this change deliberately does not do

- It does not invent new business logic. The `@spec example-change/task-N` docblock tags in `lib/**/*.php` are the source of truth this triad documents; the only PHP added alongside this change are the two observability controllers (`HealthController`, `MetricsController`) whose routes were already declared but whose classes were missing.
- It does not archive itself. Archival would move the delta into `openspec/specs/` and remove the tasks — but the task file is what the code's `@spec` tags point at. Keeping the change `in-progress` (spiritually `example`) is what keeps the template internally consistent.
- It does not add any ADRs. The capability specs refer to ADRs by number (003, 004, 005, 006, 011) but those ADRs live in `openspec/architecture/` and are out of scope for this change.
- It does not generate any `openspec/changes/example-change/specs/` delta directory. Since the change is "already there" in spirit (the code was written first) and the `openspec/specs/` directory now holds the full end-state specs directly, duplicating them as a delta would add maintenance cost without improving the demonstration.

## What future edits might change

- Add a real mutation endpoint that wires `ActionAuthService::requireAction()` so the `item-management` spec points at live code again instead of being documentation-only.
- Promote one of the ADR references into an actual ADR file under `openspec/architecture/` so the template showcases the full ADR → capability → code chain.
- Add tests under `tests/` that exercise the example scenarios so the REQs are enforced by CI, turning the specs from aspirational to load-bearing.
- Once the template has a second stable change (e.g. `add-export-api`), consider archiving `example-change` and resetting the code's `@spec` tags to the new change, so newcomers see a fully-archived historical record plus an active change.

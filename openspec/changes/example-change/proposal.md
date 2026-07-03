# Example Change — initial template build-out

> ⚠️ **EXAMPLE CHANGE** — This directory lives in the `nextcloud-app-template`
> repository as a demonstration of the OpenSpec change structure. Every file
> in this change is reference material. Apps built from this template should:
> 1. Rename this change to something meaningful (e.g. `initial-scaffold`)
> 2. Update the task list to reflect the real work they're doing
> 3. Keep the docblock `@spec` references in code aligned with the renamed
>    tasks, OR archive this change cleanly first and let future real changes
>    start from zero

## Why this change exists

A template-derived Nextcloud app starts from a set of patterns that cover
the basics of every Conduction app: a Vue SPA entry, settings CRUD, admin-UI
registration, per-object authorization, repair-step initialization, deep-link
registration, and ADR-006 observability endpoints. The `example-change`
records those patterns as a single "initial build-out" change so that:

- The `@spec openspec/changes/example-change/tasks.md#task-N` docblock tags
  peppered through the template's `lib/**/*.php` code resolve to real tasks
  inside this change.
- A fresh-cloned template is **internally consistent**: running the
  openspec-propose / openspec-apply / openspec-archive skills against it
  reflects a well-formed example instead of pointing at missing files.
- Developers new to OpenSpec see a worked example of the proposal /
  tasks / design triad with concrete REQ anchors, before they write their
  own.

## What this change produces

Seven capability specs in `openspec/specs/` (all marked `example: true`):

| Capability | Source files | Implementing tasks |
|---|---|---|
| `dashboard-page` | `DashboardController.php` | task-1 |
| `settings-management` | `SettingsController.php`, `SettingsService.php` | task-2, task-3 |
| `deep-linking` | `DeepLinkRegistrationListener.php` | task-4 |
| `item-management` | documentation-only (ADR-005 per-object-auth pattern; demonstrated by `ActionAuthService`, ADR-023) | — |
| `configuration-initialization` | `Repair/InitializeSettings.php` | task-5 |
| `admin-ui` | `Settings/AdminSettings.php`, `Sections/SettingsSection.php` | task-6, task-7 |
| `observability` | `MetricsController.php`, `HealthController.php` | task-8, task-9 |

See `tasks.md` for the flat task list the code's `@spec` annotations reference,
and `design.md` for decisions made while writing the specs (including how the
manifest-renderer refactor reshaped task-5).

## Status

`example` — this change is deliberately **not archived**. Archiving would
merge the change's spec deltas into `openspec/specs/` and remove the tasks
this change documents; the template needs those tasks to stay in place so
that the `@spec` references in `lib/*.php` continue to resolve after clone.

When an app is generated from this template:

- Either rename the change and keep it in `openspec/changes/` (reflecting the
  initial scaffold that the real app begins with), or
- Delete this change + clear the `@spec` tags in a single first commit, then
  build up the app's own change history via `/opsx-new`, `/opsx-ff`, and
  `/opsx-apply`.

The `status: example` frontmatter in each spec and the "EXAMPLE" banner at the
top of each file make the demonstration nature explicit.

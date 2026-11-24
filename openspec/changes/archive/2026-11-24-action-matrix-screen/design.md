# Design: action matrix screen

## Decisions

- **D1. Where.** A section "Rechten per actie" on the tenant management page (route `TenantOps`,
  board `hermiq/HmTenantOps`), rendered only for a Nextcloud administrator. It is a manifest widget,
  not a `/settings/admin` page, because the board places it next to the other organisation controls.
  The section states that the matrix applies to every organisation on the instance.
- **D2. Endpoints.** `GET /api/actions` returns every action known to the seed or the stored matrix,
  with label, description, its groups and whether it differs from the seed. `PUT /api/actions/{action}`
  replaces the groups of one action. Both live on a new `ActionMatrixController` without
  `#[NoAdminRequired]`, so Nextcloud's middleware refuses non-admins before the method runs.
  Writes go through `ActionAuthService::setMatrix()`.
- **D3. Validation.** An unknown action name is refused (404), a group id that does not exist is
  refused (400) naming the group, and `admin` is always kept, so the matrix cannot lock out
  administrators. Saving the seed's value for an action is the reset.
- **D4. Labels.** Labels and descriptions live next to the seed in `lib/actions.seed.json` as an
  `labels` map keyed by action, translated through `l10n`, so a new `requireAction()` call site adds
  its label in the same place it adds its seed entry.

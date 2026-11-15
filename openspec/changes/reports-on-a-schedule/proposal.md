# Send reports on a schedule

## Why

Ruben decided on 12 Nov (decision 390) that reports about agent use come on a schedule, not
only on demand. The reports spec says so plainly today: "Reports on a schedule are not built:
every report is read on demand."

### Matrix rows (hermiq `openspec/parity/capabilities.json`)

| row | what changes |
|---|---|
| `ob-reports` | a person subscribes to a report and receives it weekly or monthly |

## What changes

- A person can subscribe to any report on the Reports page, weekly or monthly.
- A background job sends each due report as a Nextcloud notification with a link to the
  report for that period.

## Impact

- `src/manifest.json` (Reports page), a new background job under `lib/BackgroundJob/`, a
  subscription schema in the hermiq register. Not built yet: the matrix row moves to `specified`.

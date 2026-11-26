# Proposal: adopt-connection-registry

## Why

Hermiq reaches several systems outside itself: a language-model provider, the
`hermiq-llm-runner` ExApp, a speech sidecar, a web-search backend, the webhook
targets agents deliver to, and GitHub for the template and skill store.

An admin cannot see which of these work. The answer is spread over two admin
sections, app-config keys only `occ` can set, and run logs.

The hydra umbrella change `connection-registry` (hydra#667, amended in hydra#673
and hydra#674) gives every app one page for this. The app declares its
connections in `lib/Settings/connections.json`. Integriq keeps one row per
connection in its `app_connection` schema and works out the status. The app
reports what only it can observe.

## What changes

- New `lib/Settings/connections.json` with six connections: `llm`, `llm-runner`,
  `speech`, `web-search`, `webhook-delivery` and `github-templates`.
- An Integrations page at `/settings/integrations`: an index page over
  `integriq/app_connection`, preset to `app=hermiq` by its menu entry, admin
  only, gated by `requiresApp` integriq. The menu entry sits in the settings
  foldout, so the main menu does not grow (ADR-097).
- An Add integration header action that opens
  `/apps/integriq/connections?app=hermiq&link=1`.
- Local `connectionStatus` and `connectionSettingsLabel` formatters. The pinned
  `@conduction/nextcloud-vue` 2.42.0 does not ship them.
- `ConnectionReporter` sends `ConnectionStatusReportedEvent` by string class name
  behind `class_exists`.
- Reports where hermiq already observes a state: the AI provider save, the web
  research save, a store search against GitHub and a webhook delivery. The last
  two are throttled.
- Stable ids on the AI provider, web research and organisation credentials
  admin sections, so a settings link lands on them.

## Depends on

- hydra `openspec/changes/connection-registry`, design D2, D4, D6, D8, D9, D12,
  and hydra#674 for how a refresh retires older observations.
- integriq's `app_connection` schema, sync, listeners and overview.

Hermiq takes no hard dependency on integriq. Without it the menu entry is
hidden, the page shows the missing-app screen and no event is sent.

## Out of scope

- The Talk chat bridge and Talk delivery. Both talk to Nextcloud Talk on the
  same instance, so neither is an outside connection.
- Email delivery. It goes through the instance mail server, which Nextcloud
  owns.
- `web.fetch`. It fetches whatever URL an agent names, one record per call,
  which design D12 of the contract leaves out.
- The setup wizard's `llmendpoint`. The wizard tests it, and no chat path reads
  it.

## Rollback

Revert this change. No schema, migration or register data belongs to it. Rows
integriq synced stay in integriq until its sync runs without the file.

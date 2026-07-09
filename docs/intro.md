---
sidebar_position: 1
description: Schedule autonomous AI agents in Nextcloud.
---

# Hermiq

Hermiq brings autonomous AI agents to Nextcloud. Define an agent, give it
tools, and run it on a schedule — all inside your own Nextcloud. Agents,
their memory and skills are stored as OpenRegister objects, so every run
is governed, auditable and multi-tenant.

## What is this?

Hermiq is an agent engine built on ConductionNL conventions — a
manifest-first Vue 2 frontend rendered by CnAppRoot, an OpenRegister data
layer, and the full PHP + frontend quality pipeline. It ships:

- **Agents** — define an agent with a prompt, a model, MCP tools and
  installable skills, and manage them from the Agents catalog and
  detail page.
- **Scheduling, delivered to Talk** — attach a schedule to an agent
  (daily, hourly, or flow-triggered) and its output is delivered to a
  Nextcloud Talk conversation, falling back to a notification when Talk
  isn't installed.
- **Chat, sessions and memory** — a live streaming chat thread per
  agent, a full conversation-session history, and consolidating memory
  so long-running agents stay within their context budget.
- **Skills marketplace** — import and export agentskills.io skill
  packages and install them onto an agent.
- **MCP tools** — a catalogue of Model Context Protocol tools available
  to agents.
- **Human approval gate** — a reviewer inbox that gates sensitive agent
  actions behind an approve/deny step, with an org-level kill switch.
- **Run analytics** — KPIs and a per-agent run/status breakdown.
- **AI feature governance** — a DPO-gated enable/disable register for
  AI features (EU AI Act).
- **Multi-tenant ops** — per-org quota usage and an audit export for
  tenant admins.
- **OpenRegister integration** — every agent, run, memory and skill is
  stored as an OpenRegister object, so it is governed, audited and
  multi-tenant by construction. `manifest.dependencies` lists
  `openregister`, so the dependency-check phase ensures it is installed
  before the UI mounts.
- **The quality pipeline** — PHPCS, PHPMD, Psalm, PHPStan, ESLint,
  Stylelint, plus manifest/register/JSON-strict validators.
- **This documentation site** — Docusaurus on `@conduction/docusaurus-preset`,
  the journeydoc tutorial scaffold, and a Playwright `docs-capture`
  project for screenshots (ADR-030).

## Getting started

Clone the repo and build:

```bash
cd /var/www/html/custom_apps
git clone https://codeberg.org/Conduction/hermiq.git hermiq
cd hermiq
npm install && npm run build
php occ app:enable hermiq
```

> OpenRegister must be installed first unless you remove the dependency
> from `src/manifest.json`, `appinfo/info.xml`, and `openspec/app-config.json`.

- New here? Start with the **[User guide](/docs/category/user-guide)** — open
  the app for the first time.
- Setting things up? See the **[Admin guide](/docs/category/admin-guide)** —
  manage the app's settings.

Free and open source under the EUPL-1.2 license. For support, contact
support@conduction.nl.

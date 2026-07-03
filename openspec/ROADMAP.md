# Roadmap

This document tracks the planned development of Nextcloud App Template.

Features are defined in [`appspec/features/`](../appspec/features/). When a feature reaches `planned` status during an `/app-explore` session, it is listed here and an OpenSpec change is created with `/opsx:ff`.

## Status Overview

| Feature | Status | Priority | OpenSpec Change |
|---------|--------|----------|----------------|
| _(no features defined yet — use `/app-explore` to start)_ | — | — | — |

## Phases

### Phase 1 — Foundation

_Define the core features needed for a working app. These are the minimum set that make the app useful._

### Phase 2 — Enhancement

_Add features that improve the experience, extend functionality, and cover more use cases._

### Phase 3 — Polish

_Performance, accessibility improvements, full localization, and hardening for production._

---

## How This Works

1. Run `/app-explore` to define features in `appspec/features/`
2. When a feature is `planned`, add it to the table above
3. Run `/opsx:ff {feature-name}` to create the implementation spec
4. Update the **OpenSpec Change** column with a link to the change directory
5. When all changes for a feature are done, mark the feature `done`

# Skills

## What it is for

A skill is an ability you add to an agent: a written procedure it follows, packaged so
it can be installed, versioned and improved.

## How to reach it

**Skills** in the navigation. Click one for its detail page.

## What a skill carries

A body in Markdown, the files that go with it, its maturity level, its provenance, and
the agents it is installed on.

Multi-file skills survive the install round trip intact. Auxiliary file paths are
validated on install, because a path is an instruction to write somewhere.

## Maturity

A skill states how proven it is. The level is not decoration: it is what the
[evaluations](evaluations.md) surface measures against, and a skill can be held back
from publication until it earns one.

## Self-improvement

An agent can propose a change to its own skill from what it learned in a run. The
proposal arrives as a draft. A person promotes it, or does not. An agent editing its
own instructions unattended is exactly the thing an approval gate is for.

## Where skills come from

Your own, or the [Store](store.md). A skill installed from another organisation or
from an external hub arrives quarantined, so importing one is not the same as trusting
it.

## API

- `GET|POST /apps/hermiq/api/skills`
- `GET /apps/hermiq/api/skills/{id}/export`
- `GET /apps/hermiq/api/skills/github/search`
- `POST /apps/hermiq/api/skills/github/install`
- `POST /apps/hermiq/api/skills/bundle/publish`

## Where to go next

Install one on an agent from its [agent page](agents.md), then check it actually helps
in [Evaluations](evaluations.md).

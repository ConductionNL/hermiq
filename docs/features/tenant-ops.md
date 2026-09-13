# Tenant ops

## What it is for

Run Hermiq for more than one organisation without them affecting each other. Budgets,
quotas, retention, access review, incidents and the kill switch live here.

## How to reach it

**Settings > Tenant ops**. Restricted to organisation owners and instance
administrators.

## Budgets and quotas

Set a spend or token budget per organisation. Only recorded usage enforces a budget: a
pre-run estimate is shown to you as an estimate and never used to block a run, because
blocking on a guess is how a working agent stops for no reason.

Quotas cover how many agents and schedules an organisation may have. The dashboard shows
where you are against them.

## The kill switch

Halt every run for an organisation, immediately. A run that would have started is
refused and recorded as refused, so the gap in the history explains itself.

## Retention and export

Set how long run records are kept, and export a per-tenant audit trail for the EU AI
Act. The export is the artefact you hand a regulator, so it is generated from the audit
entries themselves rather than assembled by hand.

## Access review

List who can reach what, so an annual review is a page rather than a project.

## Incidents

Record an incident against the organisation, with what happened and what was done. The
[Compliance](compliance.md) surface reads them.

## API

- `GET /apps/hermiq/api/tenant-ops/quota`
- `GET /apps/hermiq/api/tenant-ops/retention`
- `GET /apps/hermiq/api/tenant-ops/access-review`
- `GET /apps/hermiq/api/tenant-ops/audit-export`
- `GET|POST /apps/hermiq/api/tenant-ops/incidents`

## Where to go next

Set the rules agents work under in [Guardrail policy](guardrail-policy.md).

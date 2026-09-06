# Compliance

## What it is for

Show that the controls you claim to operate are actually operating, and record it when
they are not.

## How to reach it

**Settings > Compliance**.

## Control frameworks

A framework is a named set of controls. A control states what must be true and how that
is evidenced. Hermiq tracks their state so an audit is a report rather than an
excavation.

## Incidents

Record what happened, when, and what was done about it. Incidents are objects with an
audit trail, so the record of a response cannot be quietly revised afterwards.

## Export

Export the compliance picture for a period. It is generated from the same records the
dashboard reads, so what you hand out and what you looked at cannot diverge.

## API

- `GET /apps/hermiq/api/compliance/dashboard`
- `GET /apps/hermiq/api/compliance/export`
- `GET|POST /apps/hermiq/api/tenant-ops/incidents`

## Where to go next

Record the features these controls cover in
[Algorithm register](algorithm-register.md), and give each one its risk category.

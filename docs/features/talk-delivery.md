# Talk delivery

## What it is for

A scheduled run is only useful if its answer reaches you. Delivery puts a run's output
where you already are.

## How to reach it

Set a delivery target on a schedule, from the agent's detail page.

## Where output can go

- **A Nextcloud Talk room**, the default
- **A Nextcloud notification**, when Talk is unavailable
- **Email**
- **A signed outbound webhook**, for another system

## When delivery fails

The run does not fail with it. A delivery failure is recorded on the schedule as a
derived last-delivery-error, and the run's own result is unaffected. Losing a completed
run because a chat room was archived would be the wrong trade.

A separate failure alert goes to the schedule owner, so a silently undelivered run does
not stay silent.

## Webhooks

Each schedule can mint, rotate and revoke its own signing secret. Payloads are size
capped before they are signed and sent, and delivery retries with bounded exponential
backoff rather than indefinitely.

## Redaction

Output crossing the instance boundary is redacted first. Email and webhooks leave your
server, so what leaves is not simply what the model wrote.

## The link in the message

Every delivery carries a link to the run it describes. It opens the run list narrowed to
that schedule.

That link used to point at a route this app never declared, so it answered 200 with the
app shell and dropped the reader on the dashboard. If you see that behaviour on an older
version, this is what it was.

## Where to go next

Read what was delivered, and everything else, in [Runs](runs.md).

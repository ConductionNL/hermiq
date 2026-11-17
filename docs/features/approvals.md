# Approvals

## What it is for

Some things an agent wants to do should wait for a person. Approvals is where you say
yes or no.

## How to reach it

**Approvals** in the navigation. A pending approval also reaches you as a Nextcloud
notification, and in a Talk room when the agent is bound to one.

## How a run gets here

A run is held when either gate fires:

1. The schedule is marked as requiring approval.
2. The agent tried to call a tool it has no standing grant for, and the tool is
   classified as writing or destructive.

Held means held. The run does not start, and it does not start later by itself.

## What you decide

Approve and the gated run executes. Deny and it does not, and the reason you give is
recorded against it.

Both actions are guarded server-side. Being able to see an approval is not the same as
being able to decide it.

## The kill switch

An organisation owner or an instance administrator can halt every run for an
organisation at once, from **Tenant ops**. Use it when something is wrong and you want
it to stop before you know why.

While it is engaged, a run that would have started is refused and recorded as such,
rather than silently skipped.

## API

- `GET /apps/hermiq/api/approvals`
- `POST /apps/hermiq/api/approvals/{approvalId}/approve`
- `POST /apps/hermiq/api/approvals/{approvalId}/deny`

## Where to go next

If you approve the same thing every day, grant it once in
[MCP tools](mcp-tools.md) instead.

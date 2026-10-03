# Chat

## What it is for

Talk to an agent directly, without waiting for a schedule. Useful for trying a prompt
before you automate it, and for asking an agent about something once.

## How to reach it

**Chat** in the navigation. Pick an agent, start a conversation, type.

The AI companion is the same conversation from anywhere else in Nextcloud. Click the
hex button in the corner of any page and you are talking to the same agents, with the
page you are on as context.

## What the agent can see

A turn carries what you typed, the conversation so far, and whatever grounding the
agent is configured for: attached context objects, RAG over your files and
OpenRegister objects, and its own memory.

From the companion it also carries where you are. On a document, the agent knows which
document. Without that it would answer questions about "this file" with no idea which
file you meant.

## What it may do

The same tool grants that apply to a scheduled run apply here. Chat is not a way
around the guardrails: an ungranted destructive tool routes to
[Approvals](approvals.md) from a chat turn exactly as it would from a schedule.

## A standing goal

Some work is not done in one answer. In a session, choose **Set a goal** (the flag
in the session header), write the goal in your own words and say how hermiq knows it
is reached:

- **A count of objects reaches a target.** For example: the overdue permit
  applications without a reminder, target 0. The count is read as you, so it only
  sees what you may see. Prefer this one: it needs no model to decide.
- **A model answers a question about the last answer.** Use it when there is
  nothing to count.

Then pick how often the agent takes a turn (every 15 minutes up to once a day) and
the most turns it may take (1 to 50). The agent keeps working in the same session,
so it sees its earlier turns. Every turn passes the same checks as a scheduled run:
when the agent is switched off, the kill switch is on or the budget is used up, the
turn waits and the header says the goal is blocked. The header shows the goal, the
turn and the last check. You get a notification when the goal is reached or the turns
run out. **Stop goal** ends it; only you and the agent's owner can stop it.

## When there is no model

If no LLM is reachable, the turn fails and says so. It does not hang, and it does not
invent an answer.

## API

- `GET|POST /apps/hermiq/api/conversations`
- `POST /apps/hermiq/api/chat/message`
- `GET /apps/hermiq/api/chat/health`
- `GET|POST /apps/hermiq/api/sessions/{uuid}/goal` and `POST /apps/hermiq/api/goals/{id}/stop`

## Where to go next

Happy with how it answers? Give it a schedule from its [agent page](agents.md).

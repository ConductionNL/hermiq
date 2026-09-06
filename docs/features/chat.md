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

## When there is no model

If no LLM is reachable, the turn fails and says so. It does not hang, and it does not
invent an answer.

## API

- `GET|POST /apps/hermiq/api/conversations`
- `POST /apps/hermiq/api/chat/message`
- `GET /apps/hermiq/api/chat/health`

## Where to go next

Happy with how it answers? Give it a schedule from its [agent page](agents.md).

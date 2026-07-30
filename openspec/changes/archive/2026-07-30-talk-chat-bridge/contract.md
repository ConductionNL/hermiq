# Contract: talk-chat-bridge

This change consumes an interface it does not own. The authoritative surface below is
**Nextcloud Talk's (spreed) bot interface**, pinned here so that a spreed upgrade breaking any
of it is diagnosed as a contract break rather than rediscovered from first principles. Hermiq
publishes no new cross-app API in this change.

Verified against **spreed 24.0.1**.

## Consumers

- `hermiq`: consumes spreed's bot interface for inbound dispatch and outbound replies. No other
  Conduction app consumes anything introduced here.
- No app consumes Hermiq's `Conversation` / `Message` schemas — Hermiq owns that register — so
  the four properties added by `talk-chat-bridge-schema` are not a cross-project interface.

## Consumed interface: spreed bot API

### Bot registration — `occ talk:bot:install <name> <secret> <url>`

**Auth**: server operator (`occ`).

The `url` MUST use the app scheme so dispatch stays in-process:

```
nextcloudapp://hermiq
```

Relevant constants on `OCA\Talk\Model\Bot`:

| Constant | Value | Meaning for this change |
|---|---|---|
| `URL_APP_PREFIX` | `nextcloudapp://` | Selects in-process `BotInvokeEvent` dispatch — the scheme this change relies on |
| `URL_RESPONSE_ONLY_PREFIX` | `responseonly://` | A bot that only posts, never receives. Not used here; noted as the alternative shape |
| `FEATURE_WEBHOOK` | `1` | HTTP dispatch — deliberately not used |
| `FEATURE_RESPONSE` | `2` | May post messages |
| `FEATURE_EVENT` | `4` | In-process dispatch — **required** by this change |
| `FEATURE_REACTION` | `8` | May add reactions — required for the acknowledgement |

Lifecycle commands relied on: `talk:bot:install`, `talk:bot:uninstall`, `talk:bot:state`,
`talk:bot:list`. Uninstall is the rollback lever and MUST stop inbound dispatch on its own.

### Inbound — `OCA\Talk\Events\BotInvokeEvent`

Dispatched in-process by `OCA\Talk\Service\BotService::invokeBots()` for bots whose URL carries
`URL_APP_PREFIX` and whose features include `FEATURE_EVENT`. Reached from
`BotService::afterChatMessageSent`, which is a **synchronous** listener in the message sender's
request (registered at spreed `Application.php:216`).

Methods consumed:

| Method | Use |
|---|---|
| `getMessage(): array` | The invocation payload (shape below) |
| `getBotUrl(): string` | Distinguishes this bot from others sharing the listener |
| `addReaction(string $emoji): void` | The immediate acknowledgement |
| `addAnswer(string $message, bool\|int $reply = false, bool $silent = false, string $referenceId = '', bool $thread = false, ?string $threadTitle = null): void` | Not used for agent answers — the turn is async and answers post out-of-band. Retained here because it is the interface's natural reply path and may be used for synchronous refusals |

Invocation payload — ActivityPub-shaped, `type: 'Create'` for a chat message:

```json
{
  "type": "Create",
  "actor": {
    "type": "Person",
    "id": "<sender-actor-id>",
    "name": "<sender-display-name>",
    "talkParticipantType": "<numeric-string>"
  },
  "object": {
    "type": "Note",
    "id": "<numeric-string>",
    "name": "message",
    "content": "<message text>",
    "mediaType": "text/markdown",
    "inReplyTo": {},
    "threadId": 0
  },
  "target": {
    "type": "Collection",
    "id": "<room-token>",
    "name": "<room-name>"
  },
  "published": "<ISO-8601>"
}
```

Fields this change depends on: `actor.id` (speaker), `actor.name` (display name captured at send
time), `object.content` (turn text), `object.inReplyTo` (reply-based mention gating), and
`target.id` (**the room token** — the resolve key).

### Outbound — `POST /ocs/v2.php/apps/spreed/api/v1/bot/{token}/message`

**Auth**: bot signature headers, per spreed's `BotController::sendMessage`.

Used by the background job to post the agent's answer after the originating request has ended.

**Request:**

```json
{
  "message": "<answer markdown>",
  "referenceId": "<opaque-correlation-id>",
  "replyTo": 0,
  "silent": false
}
```

**Errors:**

| Code | Condition |
|------|-----------|
| 400  | Malformed or empty message |
| 401  | Bot signature rejected or bot not enabled for the conversation |
| 404  | Unknown room token — e.g. the room was deleted between the turn and its answer |

## Consumed interface: Nextcloud TaskProcessing (fast hand-off path)

Core API, not spreed. Used only when a triggerable hermiq provider is registered; the bridge
degrades to a queued job when it is not.

| Symbol | Use |
|---|---|
| `OCP\TaskProcessing\IManager::scheduleTask(Task $task)` | Hands the turn off. Core's `Manager::scheduleTask()` calls `trigger()` on an `ITriggerableProvider` **within the originating request**, so the runner picks the turn up without waiting for a cron tick |
| `OCP\TaskProcessing\ITriggerableProvider` | The interface a runner MUST implement to get the in-request nudge |
| `OCP\TaskProcessing\Events\TaskSuccessfulEvent` | Completion signal — the bridge posts the answer from here |
| `OCP\TaskProcessing\Events\TaskFailedEvent` | Failure signal — the bridge reports rather than leaving the room silent |

**Load-bearing caveat.** `ISynchronousProvider` is *not* the fast path: core schedules those via
`SynchronousBackgroundJob extends QueuedJob`, which is the same cron tick as the fallback, with
more indirection. hermiq's existing providers (`AbstractTextProvider`, `ContextAgentProvider`)
are all `ISynchronousProvider`. Registering the turn against one of them would look like an
optimisation and change nothing.

## Error Codes

| Code | Meaning | Condition |
|------|---------|-----------|
| 401  | Bot not authorised for this room | The bot was disabled or uninstalled while a turn was queued. The job MUST treat this as a terminal, non-retryable outcome and MUST NOT fail the run |
| 404  | Room gone | The room was deleted before the answer could post. Same handling as 401 |
| —    | Talk absent | `IBroker::hasBackend()` is false. Every bridge path MUST no-op silently; this is not an error condition |

## Versioning

Hermiq pins no spreed version and adds no version negotiation. The interface above is verified
against spreed 24.0.1 and is stable across the 2x series; `nextcloudapp://` dispatch and
`BotInvokeEvent` have been present since the bot framework was introduced. Hermiq declares Talk
as an **optional** runtime dependency, so a spreed absence is a supported state rather than an
incompatibility.

## Breaking Change Policy

A spreed change to any of: the `nextcloudapp://` scheme, `BotInvokeEvent`'s method set, the
invocation payload's `actor.id` / `object.content` / `target.id` fields, or the bot message
endpoint, is a breaking change for this integration. Detection is the live round-trip check in
the test plan — a mocked suite will stay green through all of them. On breakage the bridge MUST
degrade to today's behaviour (outbound delivery only) rather than failing runs, and the operator
lever is `occ talk:bot:uninstall`.

## SLA

No availability guarantee is offered or required — the bridge is best-effort and every path
degrades to existing behaviour.

Latency is explicitly **not** bounded by this contract, and depends on which hand-off path is
active. With a triggerable provider registered the runner is nudged inside the originating
request and answers land in seconds; without one the turn waits for the instance's
background-job cadence, which on a default Nextcloud can be several minutes. See design.md D2b
and proposal Risk 3. The acknowledgement reaction is the only signal guaranteed to be prompt on
either path, and it is emitted within the originating request.

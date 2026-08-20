# ADR-005: Agent output is delivered through Nextcloud Talk, not a multi-platform gateway

**Status**: accepted

**Date**: 2026-07-03

**Accepted**: 2026-07-30 — the decision is now carried by shipped, live-verified behaviour:
`talk-chat-bridge` (in-process bot, shared sessions, room-bound delivery) and
`talk-approval-reactions` (a reviewer decides a gated run with 👍/👎) are both archived
against their specs, and each was exercised end-to-end against a real spreed instance
rather than only under test doubles.

## Context

Hermes ships a gateway daemon that routes 22+ chat platforms (Telegram, Slack, Signal,
WhatsApp, Matrix, Teams, …) from a single process, with a per-platform adapter interface and
its own session store. That is a large, always-on operational surface built for a personal
agent that lives outside any collaboration suite. Hermiq lives **inside** Nextcloud, where the
user already has identity, presence, and a chat product (Talk) and email (Mail).

## Decision

Deliver agent run output through **Nextcloud Talk** (spreed) as the primary channel — a single
delivery adapter posting via the Talk bot / OCS chat API — with a **Nextcloud Notification**
fallback and **NC Mail (`IMailer`)** as an outbound option (under `nc-native-tools`). The entire
Hermes gateway daemon, its 22-platform adapter registry, and its external session store are
**dropped**. Delivery targets collapse to "the Talk room this schedule targets" plus optional
email; conversation history lives in OpenRegister (ADR-003).

## Consequences

**Positive:**
- Removes the biggest operational surface in the Hermes port (no gateway process, no per-platform
  SDKs, no reconnection logic).
- Delivery inherits Nextcloud identity, membership, and permissions.
- One adapter to build and test for the MVP.

**Negative / trade-offs:**
- **Soft dependency on Talk (spreed).** Talk is optional at runtime: Hermiq probes it via
  `IBroker::hasBackend()` and resolves spreed classes lazily, so it boots and delivers (via
  Notifications) without Talk. Where Talk is absent the experience is higher-friction and has no
  conversational thread.
- No out-of-the-box reach to external chat platforms; users who want Telegram/Slack must bridge
  via OpenConnector or a future adapter — an explicit non-goal for now.

## Status of the two-way channel

This ADR justified choosing Talk partly because it offers "a real two-way channel" and a reply
path. Only the outbound half shipped: `talk-delivery` posts run output to a room, Note-to-self or
a notification, and a delivered report could not be answered.

The inbound half is specified by `talk-chat-bridge`, which registers Hermiq as an in-process Talk
bot (`nextcloudapp://`), turns room messages into agent turns, and binds a delivered-to room to the
conversation that produced the output — so a reply continues that session. Because Talk bot
messages are server-side, this also makes agents reachable from Talk's mobile apps with no
client-side work, which was implicit in the original decision but never stated.

Two corrections to the record, verified against the current dev instance:

- Talk **is** installed here (spreed 24.0.1). The claim above that only `opentalk` video was
  present described the instance in July 2026 and is no longer true.
- No **external**-bot framework is required. spreed's `nextcloudapp://` bot URL scheme dispatches
  `BotInvokeEvent` in-process, so the bridge needs no reachable callback URL, no shared secret and
  no outbound network access.

The decision itself is unchanged; only its consequences are now more favourable than recorded.

## Alternatives Considered

| Option | Reason not chosen |
|--------|------------------|
| Port the Hermes 22-platform gateway | Enormous ops surface duplicating what Nextcloud already provides; wrong for an in-suite app. |
| Notifications only (no Talk) | No conversational thread or reply path; Talk gives a real two-way channel and is the NC-native chat product. |
| Email only | Higher latency, no interactivity; kept as a fallback/option, not the primary channel. |

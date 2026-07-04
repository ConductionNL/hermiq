# ADR-005: Agent output is delivered through Nextcloud Talk, not a multi-platform gateway

**Status**: proposed

**Date**: 2026-07-03

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
- **Hard dependency on Talk (spreed)**, which is not installed on the current dev instance
  (only `opentalk` video). Operators must install Talk and enable its external-bot framework;
  otherwise Hermiq falls back to Notifications (higher-friction, no threaded conversation).
- No out-of-the-box reach to external chat platforms; users who want Telegram/Slack must bridge
  via OpenConnector or a future adapter — an explicit non-goal for now.

## Alternatives Considered

| Option | Reason not chosen |
|--------|------------------|
| Port the Hermes 22-platform gateway | Enormous ops surface duplicating what Nextcloud already provides; wrong for an in-suite app. |
| Notifications only (no Talk) | No conversational thread or reply path; Talk gives a real two-way channel and is the NC-native chat product. |
| Email only | Higher latency, no interactivity; kept as a fallback/option, not the primary channel. |

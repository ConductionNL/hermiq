# Speech

## What it is for

Dictate to an agent instead of typing, and have it read its replies aloud.

## How to reach it

Per agent, on the agent form: dictation engine, spoken replies engine, silence timeout,
and whether hands-free conversation is offered.

## Choosing an engine

Two destinations, and the labels say which is which rather than naming an API:

- **On this instance**: audio is transcribed on your own server. Private, and slower.
- **In the browser**: instant, and in most browsers the audio goes to the browser
  vendor.

**Automatic** picks the fastest available. **Off** means no speech for this agent at
all.

## Pinning matters

An agent pinned to the instance engine does not fall back to the browser when the local
service is down. It says the service is unavailable instead.

That is the whole point of pinning. An agent chosen for privacy that quietly reroutes
audio to a third party when a server is busy would be worse than one that simply stops.

## Hands-free conversation

Off by default. When on, the composer offers a control that sends your turn once you
stop speaking and speaks the reply back. It is off by default because auto-sending on a
pause posts half-finished thoughts.

The silence timeout sets how long a pause may last before the microphone closes.

## API

- `GET /apps/hermiq/api/speech/capabilities`
- `POST /apps/hermiq/api/speech/transcriptions`
- `POST /apps/hermiq/api/speech/speech`

## Where to go next

Set it per agent on the [agent page](agents.md), then try it in [Chat](chat.md).

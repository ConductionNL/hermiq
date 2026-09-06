# Flows

## What it is for

Draw what happens when. A flow says which steps run in what order, and lets an agent be
one of those steps.

## How to reach it

**Settings > Flows**. Click a flow to open it on the canvas.

## What a flow is

One document, stored once in OpenRegister. Hermiq does not run its own flow engine: it
resolves the flow and OpenRegister schedules and executes it. That is why a flow you
build here behaves the same as one built anywhere else in the fleet.

A flow whose trigger is a schedule is reported to OpenRegister's scheduler by Hermiq's
resolver, so "run this flow every morning" needs nothing extra.

## On the canvas

A node card names its step and its type. A connection names what passes along it, and
its ports say where it may attach. What the canvas draws is what the document says,
not a second model kept in sync by hand.

## Agents inside a flow

A flow step can run an agent. When it does, the run is governed exactly as a scheduled
one: the kill switch halts it before it starts, the approval gate holds it if the
schedule requires approval, and the output is written to the configured result field.

These runs appear in [Runs](runs.md) labelled as Flow. They hang on the object that
triggered them rather than on a schedule, which is why no schedule-scoped list can see
them.

## Identity

A node's configuration cannot name the identity its step runs as. A node acts as
whoever invoked it, and that identity reaches it through the run context. A flow
document is editable by anyone who may edit flows, so a configuration key naming an
identity would be an authoring-time privilege escalation whatever the intent.

## Where to go next

Watch one run in [Runs](runs.md), then read its step timeline from the run trace.

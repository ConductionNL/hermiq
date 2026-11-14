---
kind: code
---

# Proposal: the-declared-tool-surface-and-the-prompt-library

Round 4 discovery sweep, cluster 46 "AI, and what it is allowed to read"
(`procest/_round4/discovery/build-plan.md` in ConductionNL/market-intelligence,
2026-09-14). Owner hermiq on **decision D13**. Umbrella:
`competitor-parity-2026-09`.

## Summary

An agent outside Nextcloud reaches cases through a declared tool protocol, under the
**caller's** own rights, with a right per tool. And the prompts the assistant offers
on a case are an administered library, ordered, scoped, and switchable off in one act.

## Why

Decision D13 says dossiq declares the tools and the rights and hermiq owns the
assistant. Both candidates here are hermiq's half of that sentence.

**The outside agent.** `C-integrations-19` is about the direction we do not have.
OpenProject mounts `API::Mcp` at `/mcp`, with an `MCPConfiguration` model,
`/admin/mcp_configurations`, and `app/services/mcp_output_filters/`. The sweep's note
separates it from the assistant question sharply: "Both expose the product to an agent
outside it, not an assistant inside it." hermiq's `agent-tool-governance` is about
agents **inside** hermiq consuming the OpenRegister catalogue. The lanes disagreed
about dossiq here: one read `partial` on `lib/Mcp/DossiqToolProvider.php` with
`DossiqCaseReader` and `DossiqCaseAuthorizer`, another read `yes` and noted "read
only". Read only is the gap: the candidate says "reads **and changes** cases".

**The prompt library.** `C-configuration-44` has two driven passers. OpenProject
administers Text transform actions with `toggle`, `enable_all` and `disable_all`, and
an `AI::TextTransformAction` carrying a `usage_scope` and a prompt. The lane's clause
is the reason it is not cosmetic: "a gemeente that cannot read the prompt cannot
defend the output, and a kill switch is what an incident response needs". The sweep
read dossiq `partial` on `AiSettingsController.php` and `AiService.php`, which
configure a model, and on `lib/Service/Ai/AiPromptFactory.php`, with the note "no
administered prompt object with a type scope".

## The candidates, with their lane citations

| id | capability | relevance | driven passers | lane |
|---|---|---|---|---|
| C-integrations-19 | An outside AI agent reads and changes cases through a declared tool protocol, under the caller's own rights. | should | itop, openproject | `integrations.tsv:16` |
| C-configuration-44 | An administrator maintains the prompts the assistant offers on a case. | should | opencase, openproject | `configuration.tsv:43` |

Both have two driven passers, so **decision D21**'s documented label is needed on
neither.

## What hermiq builds

- **A declared outbound tool surface.** The tools an outside agent may call are
  declared by the app that owns the data, not by hermiq. hermiq publishes the surface
  and holds no tool of its own, which is the posture ADR-063 and gate 27 already set
  for the inbound catalogue.
- **Rights are the caller's, resolved per call.** An outside agent authenticates as a
  principal, and every call is authorised as that principal by the owning app. A
  registration that granted the agent standing rights of its own would be a second
  permission model beside Nextcloud's.
- **A right per tool, on top of the caller's rights.** A registration names the tools
  it may call, default-deny on anything that writes. A caller who may write and an
  agent that was not granted the write tool still cannot write. Both must allow it.
- **An output filter per registration.** What leaves in a tool response can be
  narrowed per registration, so an agent that may read a case does not necessarily
  receive every field of it.
- **A prompt as an object.** An `AssistantPrompt` carries a label, the prompt text, a
  usage scope naming where it is offered, an order and an enabled flag. An
  administrator reads the exact text that will be sent.
- **A kill switch that is one act.** Disable all prompts, per scope or wholesale, in
  one action, recorded with who did it and when. An incident response that requires
  editing twelve rows is not a response.

## How dossiq consumes it

1. dossiq declares which of its tools are reachable by an outside agent, and which of
   those write. hermiq publishes them and enforces the per-registration grant.
2. Every call still lands on dossiq's own authoriser under the calling principal, so
   dossiq's existing per-object checks keep deciding.
3. dossiq ships the prompts it wants offered on a case as an initial library. An
   administrator edits, reorders, scopes and disables them afterwards, and dossiq does
   not hold the edited state.

## The existing specs this extends

- `agent-tool-governance`, which owns progressive disclosure, schema-scoped per-agent
  grants with default-deny on writes, and the article 12 and 14 oversight surface.
  This change adds the **outbound** direction beside the inbound one and reuses its
  default-deny rule rather than inventing a second one.
- `ai-feature-admin-surface`, which gains the prompt library.
- `run-audit-log`, which records an outside agent's calls the way it records an
  internal agent's. Unchanged in shape.
- `case-assistant-surface` stays tool-free by construction. Nothing here gives it a
  tool, and its `tools: ['__none__']` sentinel is untouched.

## Size and dependencies

**Size: M.** One publication surface, one grant check reusing an existing rule, one
output filter, one schema and one switch.

**Depends on:** nothing in this umbrella.

## What this change does not do

- It does not create tools. hermiq consumes a catalogue and never derives one
  (ADR-063, gate 27), and that holds in the outbound direction too.
- It does not give an outside agent rights of its own. A registration can only
  narrow what its caller may already do.

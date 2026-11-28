# Design: the declared tool surface and the prompt library

## D1. Two directions with one word

| | inbound, today | outbound, this change |
|---|---|---|
| who acts | an agent inside hermiq | an agent outside Nextcloud |
| what it consumes | OpenRegister's derived MCP catalogue | tools an owning app declares as reachable |
| who authorises | OpenRegister RBAC at invoke | the owning app, as the calling principal |
| grants | `Agent.tools`, per agent | a registration, per client |
| spec | `agent-tool-governance` | this change, beside it |

`agent-tool-governance` is explicit that hermiq is the sole agent consumer of the
catalogue and ships no tool code. The outbound direction is the mirror: hermiq is the
publisher and still ships no tool code.

They share one rule, deliberately: default-deny on anything that writes. Writing that
rule twice would let the two drift, and a drift in that particular rule is a silent
write.

## D2. The caller's rights, and nothing else

The candidate's wording is exact: "under the caller's own rights". Two shapes were
possible.

1. A registration holds rights. An agent is granted read on cases and acts with them.
   It is simpler and it is a second permission model beside Nextcloud's, which is the
   thing every one of these apps exists not to do.
2. A registration holds a **narrowing** of what the caller may already do.

Option 2. The agent authenticates as a principal, and the owning app authorises the
call for that principal exactly as it would for a person. Nothing in hermiq raises
what anybody may do.

The consequence worth stating: an administrator revoking a person's access revokes
their agent's too, on the next call, with nothing to update in hermiq. That property
is the reason for the shape.

## D3. Two gates, and both must open

Per call:

1. Does this registration list this tool? Default-deny on writes.
2. May this principal perform this act on this object? The owning app decides.

A caller who may write and an agent not granted the write tool cannot write. An agent
granted every tool and a caller who may not read still cannot read. Neither gate is
sufficient alone, and the spec says so, because a reviewer who sees one gate tends to
assume it is the gate.

## D4. The output filter is a narrowing, not a transformation

OpenProject carries `app/services/mcp_output_filters/`. The useful version is a field
allowlist per registration: an agent that may read a case does not necessarily receive
the whole case.

It is deliberately not a transformation. A filter that rewrote values would put a
second copy of the data model in hermiq, and a field renamed in dossiq would silently
produce the wrong shape here. Narrowing is checkable; transforming is a second model.

## D5. A prompt is an object because an administrator has to read it

`AiPromptFactory` builds prompts in code. That is the right place for a prompt nobody
outside engineering will ever tune, and the wrong place for the twelve a gemeente
argues about.

The lane's clause is the requirement: a gemeente that cannot read the prompt cannot
defend the output. "Defend" is the word that matters. When a citizen asks why the
assistant summarised their bezwaar the way it did, the answer is the prompt, and the
answer has to be retrievable by somebody who does not read PHP.

So `AssistantPrompt` holds the exact text that will be sent, plus where it is offered
(`usageScope`), in what order, and whether it is on. An administrator reading the
object reads what the model was told.

## D6. The kill switch is one act, recorded

OpenProject has `enable_all` and `disable_all` beside `toggle`. The shape is worth
copying for one reason: an incident response that requires editing twelve rows is not
a response.

Disable-all is one act, scoped or wholesale, recorded with the actor and the time.
Recording it matters as much as the speed: the first question after an incident is
when the assistant was switched off, and the answer should not be somebody's memory.

Re-enabling is deliberately **not** symmetric. Disable-all switches off in bulk;
switching back on is per prompt, so a hurried re-enable cannot restore a prompt that
was disabled for a different reason weeks earlier.

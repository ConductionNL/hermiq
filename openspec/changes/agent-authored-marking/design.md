# Design: agent-authored marking

## Context

Read at hermiq `development` 0a36e2a1: `AgentArtefactMarker::TAG_NAME = 'Agent authored'`
(deliberately untranslated, one identifier for every UI language) and
`OBJECT_PROPERTY = 'X-HERMIQ-AGENT-AUTHORED'`. The board names the label "Gemaakt met AI".

## Decisions

- **D1. One mark per subsystem, in the same write.** Unchanged from ADR-088: a system tag for
  files and notes, the `X-` property for vCard and iCalendar (tasks included). A failed mark is a
  failed write.
- **D2. Reviewed is a second mark.** A person who has read an agent-authored file adds a second
  system tag (the reviewed tag) from Files or from the oversight section; the agent-authored tag
  stays. Counting reviewed files is counting files with both tags.
- **D3. Counts come from the marks.** Files and notes: `ISystemTagObjectMapper::getObjectIdsForTags()`
  on the agent-authored tag, limited to files the viewer can read. Contacts and calendar items: the
  run audit log's successful write tool calls per organisation, since there is no index over `X-`
  properties.
- **D4. Chat answers.** The chat page already shows each answer under the agent's name and avatar in
  a conversation with that agent, so no label is added there.

## Open question

- The tag's visible name. The board says "Gemaakt met AI", the code says `Agent authored`, and the
  code keeps one untranslated name so a filter finds every tagged file. Renaming orphans existing
  tags unless they are renamed in place by a repair step. Until decided, the requirement names no
  string, and the section shows whichever name the tag has.

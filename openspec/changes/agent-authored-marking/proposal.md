---
kind: code
depends_on: []
---

# Everything an agent writes carries the AI label, and oversight counts it

## Why

Row `dm-ai-label` ("Label files and text an agent produced as AI-generated") is built:
`lib/Service/NcNative/AgentArtefactMarker.php` tags files and notes with the system tag
`Agent authored` and puts `X-HERMIQ-AGENT-AUTHORED` on vCard and iCalendar objects, in the same
write (`NotesWriteService`, `ContactWriteService`, `CalendarWriteService`, `TaskWriteService`).
But the main spec `nc-native-tools` only says it for tasks (REQ-NCTASK-002). The requirement
that covered every object, "Every object an agent writes is marked as agent-authored", sits in
the archived change `2026-08-15-nc-native-write-tools` and never reached the main spec, while
the code still cites that archived path.

The board `hermiq/HmToezicht` is the UI canon. Its section "AI-label op gemaakte bestanden en
tekst" says everything an agent writes gets the label, shows counts per kind (notes in Files,
contacts, calendar items), the share a person has reviewed afterwards, and a link to see the
labelled files in Files. The counts, the reviewed mark and the link are not built.

## What changes

- `nc-native-tools` gains the marking requirement for every object an agent writes (files,
  notes, contacts, calendar items, tasks), restoring and widening the archived one (REQ-AILBL-001).
- A person can mark a labelled file as reviewed, and the AI oversight page shows the label
  section the board draws (REQ-AILBL-002).
- Chat answers get no separate label: they show under the agent's name in a conversation with
  that agent (REQ-AILBL-003).
- `openspec/parity/capabilities.json` records `built.spec` and `built.requirement` for `dm-ai-label`.

## Non-goals

- The mark stays a hint; the run trace is the authoritative record (ADR-088).
- No label on mail an agent sends: the sending tool is already approval-gated and recorded.

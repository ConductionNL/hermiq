# Proposal: ai-oversight-subject-access

## Why

An advisory Approval is evidence about another app's record, such as a dossiq case. The Approval schema's read rule was
`authenticated`, so every signed-in user could read every advisory record on the instance, including the prompt and the
suggestion about cases they may not open. Ruben decided on 20 Oct that a record is readable only through its subject.

Hermiq also heard only about suggestions somebody acted on. A suggestion that was shown and then left had no record.

## What changes

- The Approval schema keeps advisory rows to the `ai-oversight` group and admins. Gating rows stay readable by every
  signed-in user.
- `GET /api/ai-oversight/subject` lists the advisory records about one subject. It first asks OpenRegister, as the
  signed-in user, whether that user may read the subject, and answers 403 before reading anything when not.
- `humanAction: ignored` records a shown suggestion nobody acted on as `status: unused`, with
  `advisoryContext.unusedBecause` set to `closed`, `expired` or `superseded`.

## Scope

hermiq only. Consumer apps report `ignored` through the existing event.

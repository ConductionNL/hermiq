# Download a subject's AI oversight records

## Why

Board DqZaakAssistent draws "Downloaden" on the card "Wat de assistent op deze zaak deed", with the line "Downloaden mag alleen wie de rol AI-toezicht heeft." The subject route lists the records for anyone who may open the subject, but there was no way to take them out for an audit (EU AI Act art. 14 oversight).

## What changes

- `GET /api/ai-oversight/subject/export?originApp=&subjectId=[&subjectType=]` answers a CSV (moment, user, question or suggestion, outcome, model), newest first, at most 5000 rows.
- Only an admin or a member of the `ai-oversight` group who may also open the subject downloads; both are decided before anything is read. A cell a spreadsheet would read as a formula gets a leading quote.

## Impact

AiOversightController, routes. No schema change.

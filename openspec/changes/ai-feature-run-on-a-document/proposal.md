# Proposal: ai-feature-run-on-a-document

## Why

An app that consumes hermiq's AI features needs to run one on a document: dossiq
wants a handler to summarise a case document with the feature its case type
declared (dossiq `ai-features-on-the-case-consume-hermiq`, task 3.1). hermiq's
redaction gate already sits on the document, not on the run. A feature that
declares `requiresRedaction` refuses a document filinq has not redacted. But no
endpoint takes both a feature and a document reference. `assistant/converse`
takes neither, so the gate could only ever run on a reference nobody passed.

## What changes

- `POST /api/ai-features/{slug}/run-on-document` with `documentReference` (a
  Nextcloud file id) and `instruction`. It runs as the signed-in person: the
  document is read in their own Files, so a file they cannot open is a 404.
- A missing reference is a 400 before anything is read. A run without a
  reference is a run nobody checked, and it looks exactly like success.
- `ProviderFactory::generateText()` takes the optional `aiFeature` and
  `documentReference` and passes them to `createChatDriver()`. The model
  policy, data use, residency and redaction gates then run on that document
  before any request is built. Existing callers pass neither and see no change.
- A gate's refusal is a 422 carrying `gate` (`model-policy`, `data-use`,
  `residency`, `redaction` or `guardrail`), so the app can name the step that
  refused.

Generic by design (decision 182): any app, any feature, any document. Nothing
here is named after a procedure.

## Impact

- New: `lib/Service/AiFeature/DocumentFeatureRun.php`,
  `lib/Controller/AiFeatureRunController.php`, one route.
- Changed: `ProviderFactory::generateText()` gains two optional trailing
  parameters.
- Consumer: dossiq's case page calls the endpoint from the handler's session.

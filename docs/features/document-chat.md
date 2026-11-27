# Ask about a document

## What it is for

Ask the assistant about the document or dossier you have open, and see where each
part of the answer comes from. The answer rests on that document only; when the
document does not answer the question, the assistant says so instead of guessing.

## How to reach it

Open a document or a dossier in an app that tells the companion what it shows
(filinq's file viewer and dossier pages do), then open the assistant. Above the
message box a chip reads **About this document**, or **About this folder** on a
dossier. Every question you send while the chip is there is about that document or
folder.

Remove the chip with its cross to ask about everything again. It comes back when
you open another file.

## What you get back

- Each claim in the answer carries a number, such as [1] or [2].
- Under the answer, **Sources** lists those numbers in order: the file name, a link
  that opens the file, and the page when it is known.
- A source you can no longer open is listed by name only.
- When nothing in the document matches, the answer says the document does not
  answer the question, and no sources are listed.

The chat page shows the same source list under every answer, scoped or not.

## Who sees what

The document is read as you. A file you cannot open cannot be the scope of a
question, and the assistant answers with "not found" without asking the model.

## API

A chat turn (`POST /api/chat/send` or the stream endpoint) takes an optional
`scope`:

```json
{ "message": "When is the delivery?", "scope": { "type": "file", "fileId": 24753 } }
```

`type` is `file` or `folder`. Any other shape is refused with 400. The answer's
`sources` come back on the stream's `final` event and on every stored message, in
citation order: marker [n] is `sources[n-1]`.

## Spec

`openspec/changes/archive/2026-11-25-chat-about-a-document-cites-its-sources` (`document-chat`, now `openspec/specs/document-chat`).

# Message translation

## What it is for

A school writes to parents in Dutch, and not every parent reads Dutch. Other apps, portaliq first, can ask Hermiq to translate a message or news item. Hermiq does the AI work, so your school governs it in one place, next to every other AI feature.

## What the reader sees

Every translation says that AI made it. The answer carries the source language, the target language, a label for the model that did the work, and the reference the calling app gave for the original. It also carries a short fixed sentence for the reader, such as "Translated by AI from Dutch. This translation may contain errors."

Hermiq has that sentence in 16 languages. For any other target language it sends the English sentence and says so, so the calling app can show its own text instead. The sentence is never written by the model.

The calling app shows the notice next to the translated text, with a link to the original.

## What the model sees

Only the text the calling app chose to translate, and the glossary it sent. When the app does not say which language the text is in, Hermiq asks the model for the language of the first 500 characters and marks the answer as detected.

## Turning it on

The feature arrives switched off. It appears as **Message translation** in **Settings > Algorithm register**, at limited risk, with "outputs labelled as AI-made" recorded. Your DPO acknowledges it first, and an administrator then enables it. Until then every request answers "not available", and the calling app shows the original text only.

## Endpoint

| Method | Path | What it returns |
|--------|------|-----------------|
| `POST` | `/api/translate` | The translated text with its AI provenance, or "not available" |

### Request

| Field | Required | Meaning |
|-------|----------|---------|
| `sourceText` | yes | The text to translate |
| `targetLanguage` | yes | A language tag such as `ar`, `tr`, `pt-BR` |
| `sourceLanguage` | no | The tag of the text's language. Leave it out and Hermiq detects it |
| `originalRef` | no | Your reference to the original, up to 512 characters. Sent back unchanged |
| `glossary` | no | `{term, translation}` pairs the model must use as given |

A language value that is not a language tag is refused with 400, before anything reaches the model.

### Answer

| Field | Meaning |
|-------|---------|
| `translatedByAi` | `true` on every translation, `false` when nothing was translated |
| `translatedText` | The translation |
| `sourceLanguage` | Your tag, the detected tag, or `und` when it could not be told |
| `sourceLanguageDetected` | `true` when Hermiq detected the language |
| `targetLanguage` | The tag you asked for |
| `model` | The provider and model, for example `openai/gpt-4o-mini`. Never a key or an address |
| `originalRef` | Your reference, unchanged |
| `disclosure` | The fixed sentence for the reader |
| `disclosureLanguage` | The language that sentence is written in |

Store these fields next to the translation. The full contract is in `openspec/changes/message-translation-delegate/contract.md`.

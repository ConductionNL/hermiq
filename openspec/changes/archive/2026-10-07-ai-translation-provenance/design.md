# Design: ai-translation-provenance

## Context

`message-translation-delegate` (PR #969) ships `POST /api/translate`: a gate on the `message-translation` `AiFeature`, a glossary-aware prompt through `ProviderFactory::generateText()`, and a result of `{available, translatedText, targetLanguage, machineTranslationNotice, provider}`. Decision D24 asks that every AI-made translation be visible to its reader, with the source language and a way back to the original. portaliq is the first consumer and needs fields it can store next to the message, not a sentence it has to parse.

Constraints: the engine never throws; a disabled feature touches no provider; no pupil data reaches a prompt beyond the text the caller chose to translate; hermiq ships only `en` and `nl` catalogues, so a disclosure in Arabic or Turkish cannot come from `IL10N`.

## Goals / Non-Goals

**Goals**
- A provenance envelope on every response, success or not.
- A disclosure sentence the reader can read, in their language where hermiq has one.
- A register entry that says the feature labels its outputs.

**Non-Goals**
- A register UI column (API-readable only in this change).
- Labelling for other AI features.
- Letting a school edit the disclosure sentences.

## Decisions

### D1. The envelope is built in the engine, not the controller

The engine already owns both result shapes, and a future MCP wrapper or background job would call the engine, not the controller. The controller only validates input. Alternative considered: decorate in the controller. Rejected because a second entry point would then ship unlabelled translations.

### D2. `sourceLanguage` is caller-first, detected second

A caller that knows the language passes it and pays for one call. When it is absent, a second, short prompt asks the same provider for the BCP-47 tag of the first 500 characters of the text. The answer is trimmed, validated against the tag pattern and lower-cased on the primary subtag; anything else, or a thrown provider error, becomes `und`. The response marks `sourceLanguageDetected: true`, so a consumer can say "detected" if it wants to.

Alternatives: require `sourceLanguage` (simplest, but a consumer translating an incoming guardian reply does not know it); ask for a JSON object with both the translation and the tag in one call (one call, but a parse failure would lose the translation itself, and the existing prompt says "output only the translation"). The two-call path isolates failure: detection can fail without costing the translation.

### D3. `model` is an allow-listed label

`model = chatProvider` plus `"/" + <provider>Config.chatModel` (falling back to `<provider>Config.model`) when set. Only those keys are read. The result is filtered to `[A-Za-z0-9._:/@+-]` and cut to 120 characters. This rules out credential ids, organisation ids, base URLs and anything a future config key might add. `provider` stays as before for compatibility.

### D4. The disclosure is a fixed table, never model output

A translated disclosure written by the same model it discloses would be a claim the reader cannot trust. `TranslationDisclosure` holds two sentences per language, one naming the source language and one for `und`. Languages without a case-inflected "from X" construction in English keep the "from" form; the others use "Original language: X", so the language name never needs inflecting.

Language names come from `Locale::getDisplayLanguage($source, $target)` when ext-intl is loaded, which gives the name in the reader's language. Without intl, the name is the source language's own name for itself (an endonym table), else the upper-cased tag. `disclosureLanguage` states the language the sentence is actually in, so a consumer with its own catalogue can prefer that.

The table (AI-drafted in this change, awaiting a human translator):

| Tag | Named | Unnamed |
|---|---|---|
| en | Translated by AI from %s. This translation may contain errors. | Translated by AI. This translation may contain errors. |
| nl | Vertaald door AI. Oorspronkelijke taal: %s. Deze vertaling kan fouten bevatten. | Vertaald door AI. Deze vertaling kan fouten bevatten. |
| de | Von KI übersetzt. Originalsprache: %s. Diese Übersetzung kann Fehler enthalten. | Von KI übersetzt. Diese Übersetzung kann Fehler enthalten. |
| fr | Traduit par IA. Langue d'origine : %s. Cette traduction peut contenir des erreurs. | Traduit par IA. Cette traduction peut contenir des erreurs. |
| es | Traducido por IA. Idioma original: %s. Esta traducción puede contener errores. | Traducido por IA. Esta traducción puede contener errores. |
| it | Tradotto dall'IA. Lingua originale: %s. Questa traduzione può contenere errori. | Tradotto dall'IA. Questa traduzione può contenere errori. |
| pt | Traduzido por IA. Idioma original: %s. Esta tradução pode conter erros. | Traduzido por IA. Esta tradução pode conter erros. |
| pl | Przetłumaczone przez AI. Język oryginału: %s. To tłumaczenie może zawierać błędy. | Przetłumaczone przez AI. To tłumaczenie może zawierać błędy. |
| ro | Tradus de IA. Limba originală: %s. Această traducere poate conține erori. | Tradus de IA. Această traducere poate conține erori. |
| bg | Преведено от ИИ. Оригинален език: %s. Този превод може да съдържа грешки. | Преведено от ИИ. Този превод може да съдържа грешки. |
| tr | Yapay zekâ ile çevrildi. Orijinal dil: %s. Bu çeviri hatalar içerebilir. | Yapay zekâ ile çevrildi. Bu çeviri hatalar içerebilir. |
| ar | تُرجم بواسطة الذكاء الاصطناعي. اللغة الأصلية: %s. قد تحتوي هذه الترجمة على أخطاء. | تُرجم بواسطة الذكاء الاصطناعي. قد تحتوي هذه الترجمة على أخطاء. |
| uk | Перекладено ШІ. Мова оригіналу: %s. Цей переклад може містити помилки. | Перекладено ШІ. Цей переклад може містити помилки. |
| ru | Переведено ИИ. Язык оригинала: %s. Этот перевод может содержать ошибки. | Переведено ИИ. Этот перевод может содержать ошибки. |
| fa | ترجمه با هوش مصنوعی. زبان اصلی: %s. این ترجمه ممکن است خطا داشته باشد. | ترجمه با هوش مصنوعی. این ترجمه ممکن است خطا داشته باشد. |
| zh | 由人工智能翻译。原文语言：%s。此译文可能有错误。 | 由人工智能翻译。此译文可能有错误。 |

A target tag is matched on its primary subtag (`pt-BR` uses `pt`).

### D5. Two schema properties, not a reuse of `description` or `humanIntervention`

`humanIntervention` is the Algoritmekader oversight field and means something else. A boolean the register can filter on ("which features do not label their outputs?") plus a text saying how. Alternatives: one text field where empty means "no" (not filterable); a `transparencyMeasures` array (more than this change needs).

### D6. The seed back-fills once

A development instance that ran #969 already holds a `message-translation` row without the fields. The seed step finds the row; when `outputsLabelled` is not `true`, it saves the full existing object plus the two fields under the same uuid, keeping `lifecycle` unchanged so no transition fires. A row that already says `true` is not saved.

### D7. Input validation moves to the controller

`targetLanguage` was interpolated into the prompt unchecked. A BCP-47 shape check (`^[A-Za-z]{2,3}(-[A-Za-z0-9]{1,8})*$`) on both tags and a 512-character cap on `originalRef` run before the engine, as 400s, in line with the existing 400s for missing fields.

### D8. The gate reads the feature without RBAC

portaliq calls the engine in-process from a guardian's portal request, which carries a portal subject and no Nextcloud session. `findBySlug` reads under RBAC, and the `AiFeature` schema grants read to `authenticated`, so the row reads as absent and the gate answers `feature-not-enabled` after the DPO enabled it. A new `findBySlugForGate` reads with `_rbac: false` and keeps tenant scoping; the engine uses it. The row is never returned to a client, and the REST endpoint still refuses a request without a session.

Alternative rejected: the consumer wrapping the call in OpenRegister's `runAsSystem()`. OpenRegister reserves that for install, migration, repair and seeding, pins its call sites in a boundary test, and would elevate the whole engine run, provider call included, instead of one read.

## Declarative-vs-imperative decision (ADR-031)

| Behaviour | Path | Rationale |
|---|---|---|
| Recording that a feature labels its outputs | Declarative: two properties on `AiFeature` in `lib/Settings/hermiq_register.json` | It is data about the feature, read by the register. |
| Composing the provenance envelope and disclosure | Imperative, in `MessageTranslationEngine` + `TranslationDisclosure` | ADR-031 exception: NLP/external model integration. There is no stored object; the envelope is the response of a model call. |
| Back-filling the seed row | Imperative repair step | Install/upgrade work belongs in a repair step (ADR-106). |

## Seed Data (ADR-001)

- The `message-translation` row gains `outputsLabelled: true` and `outputLabelling: "Every translation carries translatedByAi, the source and target language, a model label and a reference to the original. The reader sees a fixed disclosure sentence in their language with a link to the original text."`
- The three mock `agentaifeature` rows in `lib/Settings/hermiq_mock_register.json` gain the two fields (first row `true` with a sample text, the other two `false`), so gate 101's seed rows cover the new properties.

## Risks / Trade-offs

- [The table is AI-drafted] → listed above for a translator; `disclosureLanguage` lets a consumer prefer its own reviewed catalogue.
- [Detection costs a second call] → only when the caller omits `sourceLanguage`; the detection prompt reads at most 500 characters.
- [intl absent on an instance] → endonym fallback, then the tag; covered by tests with intl forced off.
- [The back-fill saves a lifecycle-managed object] → the value is unchanged, so the lifecycle engine sees no transition; a test asserts `lifecycle` is passed through as it was.

## Migration Plan

No database migration. The register import adds two optional properties. The seed back-fill runs on the next upgrade. Rollback: revert the PR; stored extra fields are harmless.

## Open Questions

None blocking.

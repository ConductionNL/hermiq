## 1. Text2text provider family

- [x] 1.1 Create `lib/TaskProcessing/AbstractTextProvider.php` — an
      `ISynchronousProvider` base with the 12 empty optional-shape/enum/default
      accessors and a shared `process()`.
- [x] 1.2 `process()` reads the `input` slot (throws `ProcessingException` on a
      missing/empty input), frames it via the subclass `buildPrompt()`, generates
      through `ProviderFactory::generateText(..., allowNextcloud: false)`, reports
      progress once, and returns `{output}`; any generation failure is logged and
      rethrown as `ProcessingException`.
- [x] 1.3 Create the three concrete providers — `Text2TextProvider`
      (`core:text2text`, verbatim prompt), `Text2TextSummaryProvider`
      (`core:text2text:summary`, summarise-framed), `Text2TextHeadlineProvider`
      (`core:text2text:headline`, headline-framed) — each supplying only
      id/name/taskTypeId/runtime + `buildPrompt()`.

## 2. Shared generation seam + registration

- [x] 2.1 `ProviderFactory::generateText(prompt, userId, allowNextcloud)` — one
      blocking generation call dispatching per resolved driver
      (openai/ollama/fireworks/nextcloud); rejects the `nextcloud` driver when
      `allowNextcloud` is false (a Hermiq TaskProcessing provider backed by the
      TaskProcessing driver would recurse).
- [x] 2.2 Register all three providers in `Application.php` via
      `registerTaskProcessingProvider(...)`.

## 3. Tests

- [x] 3.1 `tests/Unit/TaskProcessing/Text2TextProviderTest.php` — `process()`
      delegates to `ProviderFactory::generateText` and returns its output under
      `output`; a missing/empty input throws `ProcessingException`; a generation
      failure is wrapped as `ProcessingException`; each subclass reports its correct
      task-type id; the summary/headline prompt framing wraps the input.
- [x] 3.2 `tests/Unit/Service/Llm/ProviderFactoryGenerateTextTest.php` — the
      `nextcloud` driver is rejected when `allowNextcloud` is false, and each
      non-nextcloud driver path returns the generated text.

## 4. Reformulate and translate (REQ-T2T-003, closing round 12 November)

- [x] 4.1 Add `Text2TextReformulationProvider` (`core:text2text:reformulation`) and
      `Text2TextTranslateProvider` (`core:text2text:translate`, with the task type's
      origin and target language slots) on `AbstractTextProvider`, and register both in
      `Application.php`. Verify: `occ taskprocessing:task-type:list` shows hermiq for both.
- [x] 4.2 Unit tests: each reports its task-type id, the translation prompt names the target
      language, and the `nextcloud` driver stays banned.

Build notes (second round): 1.1–3.2 shipped in #21 and were not ticked; checked
against `lib/TaskProcessing/` and `tests/Unit/{TaskProcessing,Service/Llm}`.
Registration lives in `TaskProcessing/ProviderRegistrar`, which `Application`
calls. 4.1's `occ taskprocessing:task-type:list` check needs a running
instance and was not run; the registration is pinned by
`ApplicationRegistrationGoldenTest` and `Text2TextReformulateTranslateTest`.


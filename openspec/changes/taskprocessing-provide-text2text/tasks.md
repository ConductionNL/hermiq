## 1. Text2text provider family

- [ ] 1.1 Create `lib/TaskProcessing/AbstractTextProvider.php` — an
      `ISynchronousProvider` base with the 12 empty optional-shape/enum/default
      accessors and a shared `process()`.
- [ ] 1.2 `process()` reads the `input` slot (throws `ProcessingException` on a
      missing/empty input), frames it via the subclass `buildPrompt()`, generates
      through `ProviderFactory::generateText(..., allowNextcloud: false)`, reports
      progress once, and returns `{output}`; any generation failure is logged and
      rethrown as `ProcessingException`.
- [ ] 1.3 Create the three concrete providers — `Text2TextProvider`
      (`core:text2text`, verbatim prompt), `Text2TextSummaryProvider`
      (`core:text2text:summary`, summarise-framed), `Text2TextHeadlineProvider`
      (`core:text2text:headline`, headline-framed) — each supplying only
      id/name/taskTypeId/runtime + `buildPrompt()`.

## 2. Shared generation seam + registration

- [ ] 2.1 `ProviderFactory::generateText(prompt, userId, allowNextcloud)` — one
      blocking generation call dispatching per resolved driver
      (openai/ollama/fireworks/nextcloud); rejects the `nextcloud` driver when
      `allowNextcloud` is false (a Hermiq TaskProcessing provider backed by the
      TaskProcessing driver would recurse).
- [ ] 2.2 Register all three providers in `Application.php` via
      `registerTaskProcessingProvider(...)`.

## 3. Tests

- [ ] 3.1 `tests/Unit/TaskProcessing/Text2TextProviderTest.php` — `process()`
      delegates to `ProviderFactory::generateText` and returns its output under
      `output`; a missing/empty input throws `ProcessingException`; a generation
      failure is wrapped as `ProcessingException`; each subclass reports its correct
      task-type id; the summary/headline prompt framing wraps the input.
- [ ] 3.2 `tests/Unit/Service/Llm/ProviderFactoryGenerateTextTest.php` — the
      `nextcloud` driver is rejected when `allowNextcloud` is false, and each
      non-nextcloud driver path returns the generated text.

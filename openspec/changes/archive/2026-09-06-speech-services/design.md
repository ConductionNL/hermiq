# Design — speech-services

Local speech-to-text and text-to-speech, registered as Nextcloud TaskProcessing
providers, with per-language support that is measured rather than claimed.

## Declarative-vs-imperative decision (ADR-031)

| Behaviour | Path | Rationale |
|---|---|---|
| Transcription / synthesis | **Imperative** — TaskProcessing providers + sidecar runner | External integration: a side-effecting call into a separate model process. Owns no schema, no derived value, no lifecycle. Identical in shape to the existing `Text2TextProvider`. |
| Authorisation to process speech | **Declarative** — existing AI-feature governance register | "This instance processes voice recordings, on this engine" is a registered capability decision, not a service-side conditional. |
| Language support matrix | **Declarative** — a versioned declaration in the app | A table of measured facts, not behaviour. It is data that must be re-measured when the model changes, which is exactly what a declaration makes visible. |
| Invocation record | **Declarative** — existing run trace | One step per call, extended with engine identity and the language used. |

No `x-openregister-{lifecycle,aggregations,calculations,notifications,relations,widgets}`
block is added or modified.

## Topology: three processes, not one model

```
audio ──▶ [speech runner sidecar]  ──▶ text ──▶ [Ollama / llm-runner] ──▶ text
                 Whisper                                                    │
text ◀────────────────────────────────────────────────────────────────────┘
  └──▶ [speech runner sidecar] ──▶ audio
             TTS engine
```

Ollama has no audio input path, so the speech models cannot live there. The
runner is deployed exactly like `exapp/llm-runner` and everything stays on the
instance — the sovereignty property is preserved, only the process boundary moves.

Both directions surface through TaskProcessing, not through a Hermiq-private API,
so Assistant, Talk and any other TaskProcessing consumer gets speech without
knowing Hermiq exists. `AudioToText` is already in the vendored OCP;
`TextToSpeech` (`core:text2speech`) is not, so `nextcloud/ocp` must be bumped
first — a task, not an assumption.

## The language matrix is the substance of this change

Whisper is advertised at "99+ languages, ~12% WER". Both numbers are true and
neither is usable: the average conceals a spread from ~4.4% WER (Spanish, Common
Voice 13) to 25–35% and worse on low-resource languages. The EU's 24 official
languages sit across that whole spread, with Maltese and Irish at the weak end.

**A wrong transcript is fluent.** It is punctuated, grammatical and confident, and
it is wrong. No downstream consumer — not the agent, not the user reading it, not
a passing test — can distinguish it from a correct one. The error is therefore
invisible exactly where it is most damaging, which is why "the model supports the
language" is not an acceptable answer.

So the matrix is built from measurement:

| Field | Source |
|---|---|
| Language | each of the 24 official EU languages |
| Measured WER | a run against a **named public benchmark** (Common Voice / Fleurs), recorded with the dataset version |
| Model + version | the exact model identifier the measurement used |
| Support level | derived from the measured WER against a declared threshold |
| Measured on | the date of the run |

Rules that follow:

- **Unmeasured is not supported.** A language absent from the matrix is treated as
  unsupported, never as "probably fine because the vendor lists it".
- **Below threshold is declared, not hidden.** The provider does not quietly
  produce a poor transcript; the caller learns the language is below the supported
  bar.
- **Changing the model invalidates the matrix.** The measurement is pinned to a
  model version, so a model bump requires a re-run. A matrix that outlives its
  model is a claim about software that no longer exists.

## Language selection and code-switching

Whisper auto-detects language, and mis-detects on short utterances and on
code-switched speech. In this deployment context code-switching is the **normal
case** — Dutch or Danish carrying English technical and product terms — not an
edge case worth a footnote.

Therefore: the caller may state the language explicitly, detection is the fallback
rather than the default path, and the language actually used is recorded on the
result. A consumer that cannot see which language was assumed cannot diagnose a
transcript that came out wrong because the wrong language was picked.

## Audio is personal data

A voice recording identifies its speaker, and a transcript of a meeting or a call
contains whatever was said in it. The rule established for mail in
`nc-mail-read-tools` applies unchanged, for the same reason:

- **AI-feature gated.** Enabling speech processing is a registered decision, not a
  consequence of installing the runner.
- **The engine is recorded**, because "who processed this recording" has a
  different answer for a local runner than for anything else.
- **Neither audio nor transcript content enters the invocation record.** Duration,
  language, engine, outcome — never the words. An audit record that stores the
  transcript is a second copy of the conversation in a place with different access
  rules.
- **A transcript written as a file is an agent-authored artefact** and carries the
  ADR-088 tag.

## TTS engine: Kokoro, and the coverage gap it creates

| | **Kokoro (chosen)** | Piper |
|---|---|---|
| Size | ~82M params, ~350MB | small, real-time on Pi 5 CPU |
| Licence | **Apache-2.0** | GPL-3.0 (the MIT `rhasspy/piper` was archived read-only, Oct 2025) |
| Languages | **8** | 30+, 100+ voices |

Kokoro is chosen: permissive licence, tiny, faster than real-time on CPU, nothing
to argue about at review time.

**The cost is asymmetric coverage and it must be stated, not averaged away.**
Transcription is measured across 24 official EU languages; synthesis reaches 8.
Any single "supported languages" figure would be false in one direction whichever
number was chosen, and the failure it would cause is the ordinary one: a user who
can dictate in Latvian reasonably assumes the system can answer in Latvian.

So the declaration is **per direction**:

| | Transcription (Whisper) | Synthesis (Kokoro) |
|---|---|---|
| Coverage | measured across 24 EU official languages | Kokoro's 8 |
| Declared as | measured WER per language, model-pinned | supported / unsupported per language |

A language supported for dictation but not for synthesis is declared as exactly
that. A consumer asking "can I do voice in Estonian?" gets a direction-specific
answer, because there is no honest direction-independent one.

Piper remains the documented escape hatch — 30+ languages, GPL-3.0, acceptable in
a separate sidecar process with no linking. If the synthesis gap bites real
deployments, swapping the engine is a runner change, not a redesign, because
nothing above the runner depends on which engine produced the audio.

## Verification

- The matrix is produced by an actual benchmark run, not transcribed from a model
  card. The run's dataset, version and date are recorded alongside the numbers.
- **Negative control on the threshold**: a language declared unsupported must
  produce the declared refusal or flag — verified by running it, not by reading the
  branch. A quality gate nobody has watched refuse is a quality gate nobody has
  tested.
- Both providers appear in Nextcloud's TaskProcessing provider list and are
  invocable from a TaskProcessing consumer, not only from Hermiq.
- With the AI feature disabled, speech processing refuses; enabled, it succeeds.
  Both halves run — the disabled half is what shows the gate is load-bearing.
- Audio confirmed not to leave the instance, by observing the runner's egress
  rather than by reading its configuration.
- Invocation record asserted to carry engine and language and to carry no audio
  and no transcript text.
- Zero new PHPUnit failures against a self-measured baseline; scoped `phpcs` clean.

## Seed data

None. No OpenRegister schema is introduced or modified.

## DEFERRED_QUESTIONS

All four resolved 2026-08-15.

1. ~~Which WER threshold?~~ **15% measured WER.** Above it a language is declared
   below-threshold.
2. ~~Refuse or flag below threshold?~~ **Return with an explicit low-confidence
   flag.** A rough transcript may beat none. The residual risk is real and stays
   on the record: *a flag nobody renders is the same as no flag*, and we cannot
   force Assistant, Talk or future consumers to honour it. The flag is therefore
   part of the result's own shape rather than an out-of-band annotation, so a
   consumer must actively discard it to lose it.
3. ~~Kokoro or Piper?~~ **Kokoro** — Apache-2.0, ~82M params. See §TTS engine for
   the coverage consequence and the per-direction declaration it forces.
4. ~~Which Whisper variant?~~ **large-v3-turbo** — 809M params, 2–5× faster than
   large-v3, minimal accuracy loss. Carries one live consequence: its fine-tuning
   **excludes the translation task**, so "transcribe this Polish call into English"
   is not one call. Translation, if needed, is a separate step through the existing
   text pipeline — which is not purely a cost, since it makes the translation
   visible and auditable rather than folded invisibly into transcription.

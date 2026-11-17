---
kind: code
---

# Proposal: speech-services

# Why

Hermiq implements Nextcloud TaskProcessing providers for text
(`Text2TextProvider`, `Text2TextSummaryProvider`, `Text2TextHeadlineProvider`,
`ContextAgentProvider`). It implements none for audio. An agent can read a
document and write a note, but a user cannot dictate to it and it cannot answer
out loud — which rules out the whole class of hands-free, accessibility and
call-handling work that public-sector deployments actually ask for.

Two premises in the original framing turn out to be wrong, and both change the
design, so they are stated up front rather than discovered during implementation:

**Ollama cannot host speech models.** It has no audio input path. The
open-source local voice stack is three separate processes — Whisper for
speech-to-text, an LLM (Ollama) for reasoning, and Piper or Kokoro for
text-to-speech — glued together by the caller. So this is a **sidecar runner**
alongside the existing `exapp/llm-runner`, deployed the same way, and *not* a
model pulled into Ollama. The "small, self-hosted, no audio leaves the network"
goal is fully met; it is just met by a different process.

**Nextcloud already provides both seams, and one of them is missing from our
vendored copy.** `OCP\TaskProcessing\TaskTypes\AudioToText` is vendored in Hermiq
today. `TextToSpeech` (`core:text2speech`) exists upstream but is **absent** from
Hermiq's vendored `nextcloud/ocp` — so the vendored copy is behind and must be
bumped before a TTS provider can be registered. Building either capability on a
bespoke interface instead of these task types would put speech outside the
Assistant, Talk and every other consumer that already speaks TaskProcessing.

**And the thing that actually matters for us is language coverage, which no
vendor claim answers.** Whisper is advertised as supporting "99+ languages" at
roughly 12% average word error rate. That number is an average over a
distribution, and the distribution is what a European deployment lives in:
Spanish reaches ~4.4% WER on Common Voice 13 and Catalan ~5.7% on Fleurs, while
low-resource languages reach 25–35% or worse. The EU has 24 official languages
and Whisper's quality across them is uneven — with Maltese and Irish the known
weak points.

The failure mode this creates is the dangerous kind: **a bad transcript looks
exactly like a good one.** It is fluent, plausible, correctly punctuated, and
wrong. Nothing downstream — not the agent, not the user, not a test — can tell it
from a good one. So per-language quality must be **measured and declared**, not
inherited from a model card.

# What Changes

- **A speech runner sidecar** alongside `exapp/llm-runner`: a Whisper
  implementation for transcription and a TTS engine for synthesis, both local,
  with no audio leaving the instance.
- **`AudioToText` TaskProcessing provider** registered by Hermiq, so dictation is
  available to every existing TaskProcessing consumer rather than to Hermiq alone.
- **`TextToSpeech` TaskProcessing provider**, after bumping the vendored
  `nextcloud/ocp` to a version carrying the task type.
- **A declared, measured language matrix.** For each of the 24 official EU
  languages the system declares a support level backed by a measured WER on a
  named public benchmark, with the model and version recorded. A language whose
  measured quality falls below the declared threshold is marked unsupported, and
  the provider refuses or flags rather than returning a fluent-and-wrong
  transcript.
- **Explicit language selection, with detection as fallback and always
  recorded.** Auto-detection misfires on short utterances and on code-switched
  speech — Dutch carrying English technical terms is the normal case here, not an
  edge case. The language actually used is recorded on the result.
- **The inference-path rule from `nc-mail-read-tools` applies to audio.** A voice
  recording is personal data and identifies its speaker. Speech processing is
  gated on an explicitly enabled AI feature, the engine is recorded, and neither
  audio nor transcript content enters the invocation record.
- **ADR-088 marking** for any transcript written as a file — it is an
  agent-authored artefact and carries the tag.

# Capabilities

**New Capabilities**
- `speech-services` — local speech-to-text and text-to-speech as Nextcloud
  TaskProcessing providers, with a measured per-language support declaration.

# Impact

- New: `exapp/` speech runner, two TaskProcessing providers, a language-matrix
  declaration and the benchmark that produces it.
- `composer.json` — bump `nextcloud/ocp` for the `TextToSpeech` task type.
- No new OpenRegister schema, so no seed data.
- **TTS engine: Kokoro** — ~82M parameters, ~350MB, Apache-2.0, faster than
  real-time on CPU. A clean permissive licence in a sidecar, and small enough to
  run anywhere.

- **The consequence of that choice must not be papered over: Kokoro covers 8
  languages, against 24 measured for transcription.** Speech-in and speech-out
  therefore have *different* language coverage, and by a wide margin. A single
  "supported languages" number would be false in one direction whichever number
  was picked — so the declaration is **per direction**, and a language supported
  for dictation but not for synthesis is stated as exactly that rather than
  averaged into a figure that describes neither. Piper (30+ languages, GPL-3.0)
  remains the escape hatch if the synthesis gap turns out to bite real
  deployments.

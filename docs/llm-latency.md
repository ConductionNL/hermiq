# How long a Hermiq chat reply actually takes

Measured 2026-08-16 on the shared development instance. Provider `anthropic`,
model `claude-opus-4-8`, `executionMode: cli`, governed MCP with **120 tools**
registered.

These are real timings from `hermiq-llm-runner`'s own timestamped logs
(`docker logs -t`), not estimates. Where a number is uncertain or where the
attribution is arguable, that is said rather than smoothed over.

## The layers

| Layer | Wall clock | Added by this layer |
|---|---|---|
| Raw `claude -p`, **no MCP**, trivial prompt | **~2.2 s** | — (this is the model) |
| Hermiq chat, governed MCP, **no tool call** | **~6.3 s** | **+4.1 s** |
| Hermiq chat, **one** tool call (`readDocument`) | **14.3 s** | +8.0 s |
| Hermiq chat, **two** tool calls (read + edit) | **29.0 s** | +14.7 s |

Raw baseline, three consecutive runs: 3074 / 2210 / 2166 ms.
Governed no-tool, two runs: 6385 / 6254 ms.

## What that means, stated honestly

**The harness costs about 4 seconds before the model says anything.** On a
question that needs no tools — most conversational turns — a user waits roughly
**three times** as long as the model alone would take. That is the number to quote
when someone asks what Hermiq adds.

**Each tool call adds roughly 8 seconds.** Most of that is *not* harness overhead:
a tool call requires the model to run again after the result comes back, so a
one-tool answer is two model turns plus an MCP round trip. Attributing the whole
8 s to "the harness" would be flattering to the model and unfair to the harness in
equal measure.

Rough split of the ~8 s, and the honesty caveat matters here:

- a second model inference — comparable to the ~2.2 s baseline, likely more
  because the context now carries the tool result;
- the MCP round trip to Nextcloud and back;
- the tool's own work (`readDocument` opens a ZIP, scans XML, hashes each block).

**We did not instrument inside that 8 s**, so the split above is reasoning, not
measurement. Do not quote a breakdown of it as fact.

## Where the 4 seconds probably goes

Most likely the **tool-list injection**: 120 tools' names, descriptions and JSON
schemas are serialised into the model's context on every turn. That is a large
prompt prefix before a single user token is considered.

**This is a hypothesis, not a measurement.** Testing it means running the same
prompt with a deliberately reduced tool set and comparing — which has not been
done. If someone wants the real answer, that is the experiment.

## What this is NOT

- **Not a benchmark of the model.** One model, one instance, one afternoon,
  single-digit sample counts. Variance on the raw baseline alone was 2166–3074 ms,
  about 40 %.
- **Not measured under load.** Single user, no concurrency, warm containers.
- **Not the browser-perceived time.** These are runner-side numbers. The user also
  waits for the Nextcloud request, the UI poll interval and rendering. End-to-end
  from keystroke to visible reply was longer than the runner-side figure in every
  observation, but it was not measured cleanly enough to publish a number.

## If a reply never arrives

Check the runner is up before anything else:

```bash
docker ps --filter name=hermiq-llm-runner
```

If it is stopped, **the chat accepts the message, shows no error, and never
replies.** There is no "runner unavailable" surface — the message simply sits
there. Observed 2026-08-16 after a Docker restart killed the container.

## Reproducing

```bash
# raw model, no MCP
docker exec hermiq-llm-runner claude -p --output-format json \
  --model claude-opus-4-8 --disallowedTools "Bash,Read,Write,Edit,Glob,Grep" \
  "Reply with exactly one word: ok"

# the full path — send a message in the chat, then read the runner's own timings
docker logs -t hermiq-llm-runner | grep -E "/run |exit="
```

The gap between the `/run` line and the matching `exit=` line is the runner-side
duration for that turn.

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

## Where the 4 seconds goes — now measured

The hypothesis was right, and the size of it is the story.

| Component | Measured | Against a 250 ms budget |
|---|---|---|
| `claude` CLI process startup (`--version`, no inference, no MCP) | **408–563 ms** | **2× over, on its own** |
| MCP `initialize` | **1221–1651 ms** | 5–6× over |
| MCP `tools/list` | **1199–1417 ms** | 5× over |
| Nextcloud bare request (`status.php`) | 46–48 ms | within |

That is roughly **3.1 s** before the model has seen a single user token, against a
budget of 250 ms. The remaining ~1 s of the 4.1 s gap is the model working through
a far larger prompt — see below.

### The tool payload is the cause

```
122 tools
433,198 bytes of JSON schema
≈ 108,299 tokens  ->  54% of a 200K context window
```

**Over half the context window is spent on tool definitions before the user says
anything**, and it is re-sent every turn.

It is not spread evenly. Four apps account for 92 % of it:

| App | Bytes | ~Tokens | Share | Tools |
|---|---|---|---|---|
| **shillinq** | **229,138** | 57,284 | **53 %** | 24 |
| openconnector | 69,234 | 17,308 | 16 % | 16 |
| docudesk | 51,281 | 12,820 | 12 % | 23 |
| pipelinq | 47,954 | 11,988 | 11 % | 9 |

Two single tools are worse than most whole apps:

```
38,561 B  shillinq.ARInvoice.search
36,505 B  shillinq.ARInvoice.get
```

`shillinq.ARInvoice.search` costs **~9,600 tokens by itself** — more than the entire
`hermiq` tool set (22 tools, 12,751 B). These are schema-derived CRUD tools, and the
derivation inlines every property of the underlying OpenRegister schema into the
tool's JSON schema.

### What would actually help

Ordered by effect, and none of it has been implemented:

1. **Scope tools per agent.** A document-editing agent needs `docudesk`, not
   `shillinq`'s invoice ledger. A docudesk-only agent would carry **12 %** of the
   current payload.
2. **Cap or summarise derived schemas.** A `search` tool does not need every
   property of the object inlined; it needs the filterable ones. The two ARInvoice
   tools alone are 17 % of the payload.
3. **Cache the handshake.** `initialize` + `tools/list` is ~2.6 s of the ~3.1 s and
   the registry does not change between turns.

**None of these is measured as a fix.** They are the three places the bytes are, in
the order the bytes are there. Anyone implementing one should re-run the numbers
rather than trust this list.

### Honesty note on an earlier attempt

The first attempt at these figures timed hermiq's own MCP endpoint with basic auth
and produced a confident ~760 ms for `tools/list`. That endpoint requires a bearer
token: the calls were being **rejected**, and 760 ms was the cost of an auth failure,
not of tool discovery. The 25-byte response body is what gave it away. Check the
payload size before believing a latency number.

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

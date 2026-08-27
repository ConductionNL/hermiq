---
sidebar_position: 20
description: The Hermiq runner sidecar — what it is, how it is built, why it is shaped this way, how it is contained, and how to bring one up.
---

# The runner sidecar

Hermiq runs one **ExApp sidecar**: a container that holds the things Hermiq must
not do inside PHP — executing vendor LLM CLIs, and running speech models. Hermiq
itself stays the governed engine; the sidecar is transport and inference only.

Guardrails, per-tool approval, grants, redaction, evals, model policy and budgets
all remain on Hermiq's side of the wire. Nothing in this container decides what an
agent is allowed to do.

## What it serves

| Port | Service | API |
|---|---|---|
| 9000 | LLM CLI transport | `POST /run` — one fully-assembled turn |
| 8000 | Speech | OpenAI-compatible `/v1/audio/transcriptions`, `/v1/audio/speech` |

The speech half serves **both directions**: faster-whisper for speech-to-text and
Kokoro for text-to-speech, behind one OpenAI-compatible API.

## How it is built

`exapp/llm-runner/Dockerfile.combined`, based on the Speaches image with Node
added.

**The direction is deliberate.** Adding Node to a Python/ML base is a package
install. Adding torch to a Node base means owning the ML dependency tree
ourselves — which is precisely the maintenance avoided by not writing a bespoke
speech sidecar in the first place.

```
FROM ghcr.io/speaches-ai/speaches:latest-cpu@sha256:21e3df06…
  + NodeSource Node 22
  + @anthropic-ai/claude-code, @openai/codex
  + /app (the runner's src/ and deploy/)
```

Roughly 3.2 GB. Grok is deliberately absent: xAI ships no verified official CLI
on npm, and installing a guessed package would be wrong — a deployer mounts one
and sets `RUNNER_GROK_BIN`.

### Why images are pinned by digest

Neither speech project ships a vendor-official image, because none exists: OpenAI
publishes the Whisper *model*, hexgrad publishes Kokoro *weights*, and neither
publishes a service. The images we use are the best-maintained community
wrappers.

That is exactly why the base is pinned by **digest**, not tag. A floating tag lets
a poisoned upstream rebuild propagate on the next `docker compose pull`, and a
community image is a softer target than a vendor one.

## Design decisions

### Two runtimes, one container

Speech and the LLM transport were briefly two sidecars. They are now one, and the
arguments for splitting them did not survive scrutiny:

- **"A model server needs egress, the runner is jailed."** Wrong, and it proved
  too much — Ollama downloads models too and lives happily behind a locked-down
  runtime. The distinction missed was **provisioning-time vs runtime**: weights
  are fetched once by an operator; afterwards the service needs no route at all.
- **"One OOM in transcription kills the LLM transport."** An orchestration
  concern, answered by `mem_limit`, not by a second image.
- **"A torch CVE forces a rebuild of the transport."** True, and the accepted
  price of one container.

What remains is that the image carries two language runtimes. That is image
hygiene, not architecture.

### Speech models are not run on Ollama

Ollama cannot host them. It has no audio input path, and neither Whisper nor
Kokoro is a GGUF model — there is nothing to `pull`. Ollama remains the LLM
inference server; this container is CLI transport plus speech.

### The entrypoint exits if either service dies

`deploy/combined-entrypoint.sh` brings the whole container down when **either**
process exits. This is the point, not a nicety.

The obvious `serverA & serverB & wait` leaves PID 1 a shell that outlives both
children. The container then stays *up* — and stays *healthy*, because a probe
only hits one port — while half the sidecar is dead. Agents keep working,
dictation silently stops, and the restart policy never fires because nothing ever
exited. That is the failure nobody notices for a week.

It polls rather than using `wait -n`: dash does not implement `wait -n`, and the
failure mode would be silent (it would wait for *both*).

### The speech server must start from its own directory

`speaches.main:create_app` mounts `StaticFiles(directory="realtime-console/dist")`
— a **relative** path. Starting it from the runner's `/app` working directory
raises `Directory 'realtime-console/dist' does not exist` and the server dies at
import, presenting as an immediate unexplained exit. The entrypoint `cd`s first.

## Security

### Governed egress, for the LLM half

The runner has **no default route**. Its network is declared `internal: true`, so
Docker installs no gateway, and the only way out is a CONNECT proxy that asks
Hermiq's PDP (`POST /api/egress/authorize`) about every single connection.

Two complementary layers, not redundant ones:

- the per-agent MCP grant governs what the **agent** is authorised to do;
- this network backstop governs what the **container** can reach — including
  traffic the agent never asked for: a CLI auto-update check, a built-in fetch a
  future flag fails to disable, a compromised dependency.

The proxy is **run-scoped**: it requires a run token in `Proxy-Authorization`,
forwards it to the PDP as a bearer token, and denies with `no_run_token`
otherwise. Only CONNECT is served — plain HTTP proxying would let the enforcement
point read request bodies.

Stated plainly: CONNECT gives `host:port` granularity, not URL granularity.

### No egress at all, for the speech half

Once models are primed, the speech server needs **no network whatsoever**. It does
not use the egress proxy, and that is deliberate rather than an oversight: model
fetching is *operator provisioning*, not an agent run. There is no run to
attribute it to, and minting a token to satisfy the proxy would make the audit
trail lie about who reached HuggingFace and why.

So provisioning happens through a short-lived primer with egress, and the serving
container then runs with no route. **This is a stronger posture than the LLM half
has** — that one needs its proxy continuously.

:::warning `HF_HUB_OFFLINE=1` is required, not optional
Cached weights alone are **not** enough. Without this variable, `huggingface_hub`
still attempts a network check when a model loads and *blocks until it times out*
— so an isolated container looks broken rather than offline.

Measured both ways: network detached and the variable unset, a synthesis call
timed out; the same call with it set returned audio from cache.
:::

:::tip A timeout is not evidence about the network
While proving the above, a detached transcription timed out and looked like a
network block. It was not — the container log showed `Processing audio with
duration …`, the VAD filter and segment processing. It had loaded from cache and
was simply slow on CPU while the client gave up. Read the container log before
concluding "blocked".
:::

### Runtime hardening

`cap_drop: ALL`, `no-new-privileges`, non-root (`ubuntu`, uid 1000), `mem_limit`
so one model load cannot take the host down.

## Bringing one up

### 1. Build

```bash
cd exapp/llm-runner
docker build -f Dockerfile.combined -t hermiq-runner:combined .
```

### 2. Prime the speech models

Speaches does **not** lazily download models — it answers `404 Model '<id>' is not
installed locally` until each one is installed. This is the only step that needs
egress.

```bash
cd exapp/speech-runner/deploy
docker compose --profile prime run --rm speech-primer
```

Or against a running container — **through `docker exec`**, because there is no
host port:

```bash
docker exec hermiq-speech curl -X POST \
  http://localhost:8000/v1/models/deepdml/faster-whisper-large-v3-turbo-ct2
docker exec hermiq-speech curl -X POST \
  http://localhost:8000/v1/models/speaches-ai/Kokoro-82M-v1.0-ONNX
```

:::danger `curl http://127.0.0.1:8000/…` never worked, and said nothing
This page used to give the commands above without `docker exec`. Docker **cannot
NAT a published port into a container attached only to an `internal: true`
network**, and it does not complain: `docker ps` shows a bare `8000/tcp`,
`docker port hermiq-speech` prints nothing at all, and no process listens on the
host. The compose file's `ports:` entry and its network jail contradicted each
other, and the network won, silently. The `ports:` entry has been removed rather
than left there implying a reachability that never existed.
:::

:::danger Use a fresh volume
Speaches runs as `ubuntu` (uid 1000). The single-purpose Whisper/Kokoro images run
as root and cache under a different path, so a **reused** volume leaves root-owned
directories Speaches cannot write. The failure surfaces as a bare
`500 Internal Server Error` on model install, with the real `PermissionError`
visible only in the container log.
:::

:::warning Model ids fail identically whether wrong or missing
`Systran/faster-whisper-large-v3-turbo` does **not** exist — Systran publishes a
large-v3 conversion but not a turbo one. HuggingFace answers a non-existent repo
with **401 Unauthorized**, not 404, because it will not leak whether a repo
exists. So a wrong id does not read as a typo; it reads as an auth problem, while
the container crash-loops on `LocalEntryNotFoundError` and the network is provably
fine.

`GET /v1/registry?task=text-to-speech` lists valid ids.
:::

### 3. Run

```bash
cd exapp/speech-runner/deploy
docker compose up -d
```

### 3a. Let Nextcloud reach it — the step that was missing

The sidecar is on a jailed network of its own, so **Nextcloud cannot reach it
until you join that network**. Attach the Nextcloud container, rather than
publishing a port or giving the sidecar a route:

```bash
docker network connect <compose-project>_speech-jailed <nextcloud-container>
docker exec -u www-data <nextcloud-container> \
  php occ config:app:set hermiq speech_base_url --value="http://hermiq-speech:8000"
```

This costs nothing in posture, and it is worth being precise about why: the
sidecar still gets **no gateway**. Verified after doing it — `/proc/net/route`
inside `hermiq-speech` holds exactly one entry, its own subnet, with no
`00000000` (default) destination, and an outbound call to `huggingface.co` still
fails, while the Nextcloud container resolves `hermiq-speech` and gets `200` from
`/health`.

:::danger Skipping this makes Nextcloud advertise speech it cannot perform
`registerTaskProcessingProvider()` succeeds whether or not the sidecar is
reachable, so `GET /ocs/v2.php/taskprocessing/tasktypes` offers `core:audio2text`
and `core:text2speech` to Assistant, Talk and every other consumer regardless.
Measured on the dev instance 2026-08-20: both task types advertised, `getent
hosts hermiq-speech` failing from the Nextcloud container, and **zero
`core:audio2text` tasks ever scheduled** — the feature looked present and had
never once run.
:::

### 4. Verify — round trip, not health endpoints

A `/health` 200 proves the process is listening, not that inference works.
Synthesise and transcribe back:

```bash
docker exec hermiq-speech curl -X POST http://localhost:8000/v1/audio/speech \
  -H 'Content-Type: application/json' \
  -d '{"model":"speaches-ai/Kokoro-82M-v1.0-ONNX","input":"Round trip.","voice":"af_heart","response_format":"wav"}' \
  -o /tmp/rt.wav

docker exec hermiq-speech curl -X POST http://localhost:8000/v1/audio/transcriptions \
  -F file=@/tmp/rt.wav \
  -F model=deepdml/faster-whisper-large-v3-turbo-ct2 -F language=en
```

The transcript should match the input text.

:::danger That round trip does not exercise Nextcloud at all
It proves the sidecar works. It says nothing about the provider in between, and
the provider is where this broke: `AudioToTextProvider::process()` accepted raw
bytes and stream resources, while Nextcloud hands an **`OCP\Files\File` node**, so
every task failed with `No audio was supplied to transcribe.` before a single
byte reached the sidecar. The unit suite was green throughout — it asserted the
two shapes nobody sends.

Verify through the layer that ships:

```bash
# upload a clip, then schedule a real task against its file id
curl -u user:pass -T clip.wav \
  "http://localhost:8080/remote.php/dav/files/user/clip.wav"
curl -u user:pass -H 'OCS-APIRequest: true' -H 'Content-Type: application/json' \
  -X POST "http://localhost:8080/ocs/v2.php/taskprocessing/schedule" \
  -d '{"type":"core:audio2text","appId":"hermiq","customId":"probe","input":{"input":<fileId>,"language":"nl"}}'

occ taskprocessing:worker --once --taskTypes=core:audio2text
curl -u user:pass -H 'OCS-APIRequest: true' \
  "http://localhost:8080/ocs/v2.php/taskprocessing/task/<id>"
```

`STATUS_SUCCESSFUL` with text in `output` is the only evidence that counts.
:::

### 4a. Pick a model that matches the hardware

**The default is `Systran/faster-whisper-base` — a CPU model**, and that is
deliberate. It used to be `deepdml/faster-whisper-large-v3-turbo-ct2`, which on
CPU is roughly 12–18× realtime: not "slow", unusable. A five-second sentence
took over a minute and the caller timed out long before the transcript existed,
so the feature read as broken rather than as misconfigured.

**A GPU host should raise it deliberately:**

```bash
occ config:app:set hermiq speech_stt_model \
  --value="deepdml/faster-whisper-large-v3-turbo-ct2"
```

The primer installs both, so that switch needs no second priming round.

Measured 2026-08-20 on a Ryzen 7 5800H (14 vCPU, CPU-only), same 4.6s Dutch clip,
model already resident, timed **through the Nextcloud path** (`occ
taskprocessing:worker` → provider → sidecar) unless noted:

| `speech_stt_model` | compute type | warm latency |
| --- | --- | --- |
| `large-v3-turbo` | default (float) | **81s** (sidecar-direct) |
| `large-v3-turbo` | `int8` | 55s (sidecar-direct) |
| `Systran/faster-whisper-small` | `int8` | 17–23s |
| `Systran/faster-whisper-base` | `int8` | **3.1–3.3s** (4.3s cold) |

Kokoro synthesis, for comparison: 9.3s for a short sentence through the same
path.

A CPU deployment needs no action — `faster-whisper-base` is the default. The row
that needs a decision is the GPU one, above.

`WHISPER__COMPUTE_TYPE=int8` is set in the compose file and is worth 1.5× on its
own; the model choice is worth another 6×. Raising `WHISPER__CPU_THREADS` is
**not** worth anything measurable — 8 threads gave 17.5s and 23.0s against 19.4s
at the default, variance larger than the effect.

⚠️ Nothing in the table is evidence about **accuracy**. The probe clip was an
English Kokoro voice reading Dutch, so every transcript in that experiment was
mangled by the input. Judge model quality on real speech from a real microphone,
not on synthesised audio.

### 5. Confirm the jail is real

Configuration is not enforcement until it is observed:

```bash
docker exec hermiq-speech python3 -c \
  "import socket; socket.create_connection(('huggingface.co',443),timeout=6)"
```

This must **fail** with a DNS error. If it succeeds, the container is not on the
internal network and "no audio leaves the instance" is an assertion rather than a
guarantee.

### 6. Point the governed MCP endpoint at the CONTAINER-facing origin

Required for any tool-using turn on `executionMode: cli`. Skipping it is the one
misconfiguration on this page that produces **no error anywhere**.

```bash
occ config:app:set hermiq mcp_run_base_url --value="http://nextcloud"
```

Use whatever host resolves to Nextcloud *from inside the runner container* —
usually the compose service name, matching AppAPI's daemon `nextcloud_url`.
Confirm it before believing it:

```bash
docker exec hermiq-llm-runner curl -s -o /dev/null -w '%{http_code}\n' \
  --max-time 8 http://nextcloud/status.php     # expect 200
```

**Why this is not optional.** `ProviderFactory::buildGovernedMcpConfig()` resolves
the endpoint with `linkToRouteAbsolute()`, which returns the URL Nextcloud
publishes to **browsers**. On a stock dev instance that is `http://localhost:8080`
— and inside the runner container, `localhost` is the container itself.

What that looked like when it happened (2026-08-15, hours to diagnose): the CLI
connected to nothing, `tools/list` never served Hermiq's tools, and the run exited
**0 with an empty stderr**. The model answered confidently, describing its own
built-in CLI tools, while every Hermiq tool was silently absent. Nothing in the
log said so.

Since #318 the runner **preflights** the endpoint and refuses to spawn when it is
unreachable, naming this config key in the error — a governed turn that cannot
reach its governance is not degraded, it is ungoverned. The preflight accepts any
HTTP answer including 401/403, because it carries no bearer token: it asks whether
a connection can be made, not whether the run is authorised.

## Known limits

- **CONNECT proxying is `host:port`, not URL.** The PDP authorises a host, not a
  path.
- **CPU inference is slow.** large-v3-turbo on a short clip can take minutes on a
  constrained CPU. Use the GPU image variants where a GPU exists.
- **Whisper turbo cannot translate.** Its fine-tuning excludes the translation
  task, so "transcribe this Polish call into English" is two steps. That is
  arguably a feature: the translation becomes visible and auditable rather than
  folded invisibly into transcription.
- **Per-language quality varies enormously.** "99+ languages, ~12% WER" is an
  average over a distribution that runs from ~4% to 25–35%. See the
  `speech-services` change for the measured, per-direction language matrix — and
  note that TTS coverage is far narrower than STT coverage.

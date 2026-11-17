<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

# hermiq-code-sandbox

An optional AppAPI ExApp that runs short Python and JavaScript programs for
Hermiq (`openspec/changes/tools-code-sandbox`). Agents reach it through the
`hermiq.runCode` tool, and flows through the `hermiq.code-step` node. Both
dispatch through `lib/Service/CodeSandbox/CodeSandboxClient.php`, which talks to
AppAPI only.

## The run contract

`POST /run` takes the AppAPI shared secret (`AUTHORIZATION-APP-API`) and this body:

```json
{ "language": "python", "code": "print(1 + 1)", "stdin": "", "files": [{ "name": "in.csv", "content": "a,b" }], "limits": { "wallSeconds": 30, "memoryMb": 512 } }
```

It answers:

```json
{ "exitCode": 0, "stdout": "2\n", "stderr": "", "durationMs": 24, "timedOut": false, "files": [], "limits": { "wallSeconds": 30, "memoryMb": 512 } }
```

`files` is text, or base64 with `"encoding": "base64"`. Created files come back
as base64. A hosted provider reached through integriq answers the same contract.

## What a run gets

| | |
| --- | --- |
| Network | None. The container's only network is `internal: true`, with no egress proxy attached. |
| Files | A fresh directory in the `/work` tmpfs, removed when the run ends. There are no volumes. |
| User | A separate uid (10001). The server runs as uid 10000. |
| Wall time | 30 s by default, at most 300 s. At the limit the whole process group is killed and the run answers `timedOut: true`. |
| Memory | 512 MB by default, at most 2 GB. Python's limit is its address space; Node's is its V8 heap (`--max-old-space-size`), because V8 cannot start under an address-space cap. The container's own memory limit backs both. |
| Processes | 64 per run (`RLIMIT_NPROC`), plus the container's `pids_limit`. |
| Output | stdout and stderr are each capped at 64 KB. Input files and created files are capped at 10 MB in total. |
| Environment | `PATH`, `HOME`, `LANG` and thread caps only. Nothing of the server's environment is passed on. |
| Languages | Python 3 with the standard library plus `numpy`, `pandas` and `python-dateutil` (pinned in the Dockerfile), and Node without extra packages. |

Run the container with gVisor (`runtime: runsc`) where the host offers it.
`deploy/docker-compose.yml` states the posture, and `test/deploy.posture.test.js`
asserts it.

## Tests

```bash
npm test
```

The network case and the per-run process cap hold only inside the container.
The process cap applies to the non-root runner uid, not to a root test run. The
network test runs where `SANDBOX_IN_CONTAINER=1`, which the image sets, so run
the suite in the image as well:

```bash
docker run --rm --network none ghcr.io/conductionnl/hermiq-code-sandbox:latest npm test
```

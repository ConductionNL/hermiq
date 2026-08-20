# Proposal: exapp-stage-workload

## Summary

Give a flow a way to do work that needs a FILESYSTEM. hermiq's ExApp gains a
stage endpoint — clone a ref, run an allowlisted command over it, return the
result — and hermiq contributes `hermiq.workload-step` so a flow invokes it the
same way it already invokes an agent turn.

## Why

`hermiq.agent-step` runs a model turn and nothing else. A governed turn is
invoked with `--disallowedTools Bash,Read,Write,Edit,Glob,Grep` and its sidecar
is `read_only`, mountless and routeless — deliberately, because the point of
that transport is that the model reaches the world only through governed tools.

So a whole class of work had no expression in a flow at all: hydra's builder,
reviewer and security stages are analysis over a checked-out tree with a
`composer install` in it. `hydra-flows-first-port` stalled on exactly this, with
seven tasks queued behind it.

Nor can that work become flow nodes. `run-hydra-gates.sh` is 3,599 lines over 59
gates, plus 20 `check_*.py` helpers. Re-stating it as a graph would be a rewrite
whose two implementations of the same rules are guaranteed to drift.

**Why the ExApp and not a runner elsewhere** — this is a product constraint, not
a technical one. The operator this is built for has a Kubernetes environment
with Nextcloud on it and LLM access, and knows how neither works: they did not
install Nextcloud, did not set up the cluster, and can reach neither.

| considered | why not |
|---|---|
| GitHub Actions | needs a forge account, an `actions` PAT scope and CI minutes; runs outside Nextcloud with no capability drop and no egress fence |
| an executor on the host | there is no host to reach, and a docker-socket holder is root-equivalent |
| creating Kubernetes Jobs | no cluster access and no credential to create Jobs with |

What is left is what they already have: NC's job system → OpenRegister flows →
this ExApp, which is the one place inside Nextcloud with a filesystem and a
toolchain (php 8.3, composer, git, node, python3).

## What Changes

- **`POST /stage` on the runner ExApp** — clones `{repo, ref}` into a scratch
  dir, runs a command over it, returns `{exitCode, output, ref}`. The command is
  an ALLOWLIST checked BEFORE the clone: the caller is a flow, which is authored
  data, so a free command string would make authoring a flow equivalent to
  remote code execution inside the ExApp.
- **`StageDispatchService`** — the AppAPI transport, with both AppAPI traps
  handled explicitly (a 3-second default timeout against a workload that runs
  for minutes; failure arriving as a RETURN VALUE rather than an exception).
- **`hermiq.workload-step`** — the flow node, beside `hermiq.agent-step`.

## The distinction the whole thing turns on

    a command that RAN and exited non-zero  ->  DATA on the item
    a workload that COULD NOT BE RUN        ->  a thrown step failure

hydra's gate runner uses its exit code as a failure COUNT, so a router
downstream is meant to read it — throwing would make "if the gates failed,
comment and retry" inexpressible. Conversely a dispatch that never happened must
never arrive as `exitCode: 0`: a downstream router cannot tell "the gates found
nothing" from "the gates never ran", and both would look like a clean tick.

## What this does not change

The credential posture. The forge token, when one is needed, is resolved through
OpenRegister's broker and reaches git via `GIT_ASKPASS` and the child
environment — never argv, never a file that outlives the run. A HOST-LOCKED
PROXY credential is refused by `resolveInjectable()` on purpose and that refusal
is surfaced rather than worked around: choosing a credential shape is an
operator decision.

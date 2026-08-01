# Tasks: exapp-stage-workload

- [x] 1. `POST /stage` on the runner ExApp — clone, run an allowlisted command,
      return `{exitCode, output, ref}`; scratch removed on every exit path.
      Allowlist checked BEFORE the clone. Not a shallow clone, deliberately.
- [x] 2. The remote-only-ref checkout — try the ref as given, fall back to
      `origin/<ref>`. Found by running it: `checkout --detach development`
      fails on a clone whose local head is `main`, with a message that reads
      exactly like a bad ref.
- [x] 3. `StageDispatchService` — the AppAPI transport. Both AppAPI traps
      handled explicitly: the 3-second default timeout (against a workload that
      runs for minutes) and failure arriving as a RETURN VALUE rather than an
      exception, in three shapes that must be checked in a load-bearing order.
- [x] 4. `hermiq.workload-step` — the flow node, contributed beside
      `hermiq.agent-step`. Placeholders rendered into repo, ref AND argv.
- [x] 5. Tests. Runner: 10 (allowlist-before-clone, remote-only ref, sha,
      cleanup after a FAILED clone, askpass). PHP: 27 (exit code is data,
      dispatch failure propagates, malformed body is never a pass).
      Suite green — 20/0 in the runner, 1366 PHP tests.
- [x] 6. Mechanism proved against the RUNNING sidecar, not a mock: cloned
      openregister, checked out `development` (a9c0ae52f), ran a command in the
      tree with php 8.3.32 / composer 2.10.2 present, captured its output; and
      the allowlist refused `sh` without cloning.

- [ ] 7. Live-verify `hermiq.workload-step` in OpenRegister's palette and run
      one stage end to end from a flow.
- [ ] 8. The forge credential for a PRIVATE clone. ⚠️ The brokered `github`
      credential is a HOST-LOCKED PROXY credential — `resolveInjectable()`
      refuses it on purpose, because its secret never leaving OpenRegister is
      the property that makes the proxy path worth having. Two ways forward,
      and it is an operator decision rather than a code one:
      (a) a separate `inject_only` forge credential scoped to hermiq — simple,
          but hermiq then holds a raw forge token;
      (b) fetch the tree through the broker (`GET /repos/*/tarball/*`, already
          covered by the existing `GET /repos/*` rule) — no forge secret ever
          reaches the ExApp, at the cost of losing git history, which
          `--scope-to-diff` needs.
      Public repositories work today with no credential at all.
- [ ] 9. The PDP allowance for the forge host. In the hardened deployment the
      sidecar has no default route and reaches the world only through the
      CONNECT proxy that asks the PDP per connection — so "may this run clone
      that repo" becomes a policy decision in the same place every other egress
      decision is made. This is what closes `hydra-flows-first-port` task 4.3:
      the workload fences itself, with no NetworkPolicy and no capability grant.

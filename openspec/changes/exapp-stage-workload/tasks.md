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

- [x] 7. Attribution (hydra-flows-first-port 5.1) — an owner from config or the
      run, REFUSED when neither yields one; `owner` / `credential_owner` /
      `credential_name` on the result, null when no credential was used, and
      carried per item so a fan-out attributes each one.
      ⚠️ Found while writing these tests: `UnexpectedValueException` extends
      `RuntimeException` in PHP, so the dispatch-failure test passed on the
      attribution refusal WITHOUT reaching the dispatcher — green for the
      opposite of the reason it claimed. It now asserts the message too.

- [x] 8. Live-verified in OpenRegister's palette and end to end through AppAPI:
      `hermiq.workload-step` beside `hermiq.agent-step`; a real clone running a
      real hydra gate wrapper returned `HTTP 200 in 62.9s / exitCode 0 /
      ref development`; and the refusal rail returned
      `command not allowed: sh` as a STEP FAILURE.

- [ ] 9. Live-verify `hermiq.workload-step` in OpenRegister's palette and run
      one stage end to end from a flow.
- [ ] 10. The forge credential for a PRIVATE clone. ⚠️ The brokered `github`
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
- [ ] 11. The egress fence — the CONTROL IS DECIDED, the deployment is not.
      ⚠️ The iptables jail is NOT the answer, and hermiq#96 says why in two
      parts: it could never start (the image sets `USER node`, and NET_ADMIN
      alone cannot help because a capability is not in an unprivileged user's
      permitted set — `--user 0:0` is required and nothing said so), and it pins
      each allowed host's IPs at BOOT, which against github.com's rotating pool
      gave 2-in-3 clone failures at 135 seconds each. A control that
      intermittently breaks legitimate traffic is worse than none.
      The fence is the governed CONNECT proxy: it resolves per connection and
      asks the PDP by HOSTNAME, so rotation cannot defeat it and a denial is
      immediate rather than a timeout. This closes `hydra-flows-first-port` 4.3
      when the sidecar is DEPLOYED behind it with the forge host allowed — a
      deployment act, with no open design question left.
      🔑 the reason this sat undetected: every assertion about the jail was a
      grep over its source, so a green suite said nothing about whether the
      container was hardened or the script could even run.

# Test Plan: cli-runner-text-turn-dispatch

**Test type for essentially everything here: PHPUnit unit tests against `ProviderFactory`.** That is not a
cop-out, it is the correct seam: this change has **no UI surface**, adds **no route**, and its subject is a
transport decision inside one class. Every spec Scenario carries a reason-bearing `@e2e exclude` for gate-19.

**What cannot be tested automatically, and why:** a live `cli` turn needs a real, billed Claude Max
subscription token *and* the ExApp container. Neither exists in CI or on the dev instance, so the happy path
is covered by **manual live verification** (TC-16/TC-17) and everything mechanically checkable is pinned by
unit tests. This mirrors `agent-engine-port`'s existing, accepted `@e2e exclude` reasoning for the same
constraint.

**The single most important test in this plan is TC-4** — a tool-bearing `cli` turn must RAISE. If that test
is ever "fixed" by making it pass a text-only run, the feature is broken and looks healthy.

## Coverage by requirement

### Requirement: Execution mode selects the Anthropic transport and defaults to http

| TC | Scenario | Type | Command |
|---|---|---|---|
| TC-1 | default execution mode is http | Unit | `composer test -- --filter ProviderFactoryTest` |
| TC-2 | cli mode reaches the dispatch rather than a stub | Unit | idem |
| TC-3 | the call site cannot drop the selected mode | Unit | idem |

- **TC-1** — `anthropic` config with no `executionMode`: assert the driver's `executionMode` is `http`, the
  `BrokerHttpClient` path runs, and **neither** AppAPI nor the ExApp is consulted (assert the `IAppManager`
  mock is never called). This is the regression guard for every existing config.
- **TC-2** — `executionMode: cli` + ExApp enabled: assert a driver is returned carrying `executionMode: cli`
  and **no exception at driver-resolution time**. This is the direct inverse of today's stub
  (`ProviderFactory.php:1361-1368`) and will fail against HEAD, which is the point.
- **TC-3** — resolve a driver with `executionMode: cli`, issue a turn through each call site, assert the mode
  reaches the transport branch. **Non-obvious and load-bearing:** all three call sites drop `executionMode`
  today (`:1106-1114`, `ResponseGenerationHandler.php:343`, `ConversationManagementHandler.php:468`). Without
  this test, `cli` can be selected in settings, accepted by the factory, and silently served over `http` —
  green tests, dead feature.

### Requirement: A cli turn that carries tools is refused, never run tool-less

| TC | Scenario | Type | Command |
|---|---|---|---|
| TC-4 | a tool-bearing cli turn raises | Unit | `composer test -- --filter ProviderFactoryTest` |
| TC-5 | the fail-open pattern is not reproduced | Unit | idem |
| TC-6 | the false tools-passthrough claim is gone | Static | `grep -n 'accepted and passed through' exapp/llm-runner/src/server.js` |

- **TC-4** — `executionMode: cli` + non-empty `$functions`: assert `ProviderUnavailableException` whose
  message names tools, **and** that no ExApp request was made **and** no credential was resolved (assert both
  mocks are never called). Ordering is part of the contract: a doomed turn must spend no quota and pull no
  secret.
- **TC-5** — the anti-regression test. Assert the turn does **not** proceed text-only under any circumstance,
  and that a logged warning is not accepted in place of raising. Written explicitly because the fail-open it
  guards against lives 250 lines up in the same file (`:624-635`) and is the natural thing for a future
  refactor to copy.
- **TC-6** — static check: the false comment at `server.js:121` is gone. A grep is the honest test for a
  comment; asserting a comment's absence in a JS runtime test would be theatre.

### Requirement: The subscription token is resolved through the broker and never persisted by Hermiq

| TC | Scenario | Type | Command |
|---|---|---|---|
| TC-7 | the token is resolved app-side for the CLI | Unit | `composer test -- --filter ProviderFactoryTest` |
| TC-8 | an organisation-scope subscription token is refused | Unit | idem |
| TC-9 | no token means no turn | Unit | idem |
| TC-10 | the token never leaks | Security | `/test-security` + unit assertions |

- **TC-7** — assert `resolveInjectable()` is called with `('hermiq', $uid)` and the token lands in
  `credentialEnv['CLAUDE_CODE_OAUTH_TOKEN']` — the exact key `providers.js:132` allowlists. A wrong key is
  dropped **silently** by `selectCredentialEnv()` (`runner.js:89-100`), producing an unauthenticated CLI
  rather than a 400, so pinning the key by name is the only thing that catches a typo.
- **TC-8** — organisation-scope Max/Pro OAuth credential: assert refusal. Anthropic ToS, personal-scope only.
- **TC-9** — `resolveInjectable()` returns `null`: assert `ProviderUnavailableException`, no ExApp request,
  and — explicitly — **no fallback to `http`**. `null` is a *routing* signal in the broker's own contract
  (`CredentialBrokerService.php:266-269`), so "helpfully" falling back is the plausible wrong move.
- **TC-10** — assert the token appears in **no** log line, **no** exception message, and is **not** stored on
  the `ChatDriver` (handlers hold that object). Test with a token-shaped fake such as `YOUR_TOKEN_HERE` — never
  an entropic value, which gitleaks would flag.

### Requirement: The turn is dispatched over AppAPI with an explicit timeout and every failure is surfaced

| TC | Scenario | Type | Command |
|---|---|---|---|
| TC-11 | the dispatch outlives the CLI it is waiting for | Unit | `composer test -- --filter ProviderFactoryTest` |
| TC-12 | an AppAPI error result is never mistaken for a completion | Unit | idem |
| TC-13 | a non-success status from the runner is not decoded as a turn | Unit | idem |
| TC-14 | cli mode without the ExApp fails clearly | Unit | idem |
| TC-15 | an absent AppAPI does not break the http path | Unit | idem |

- **TC-11** — **the highest-value test here.** Assert `options['timeout']` is present and **exceeds the
  runner's 120s** (`runner.js:28`). Without it AppAPI's 3s default (`AppAPIService.php:189-191`) applies and
  the feature is **0% functional** while still billing the user. Assert the value, not just presence, so it
  cannot regress to the default.
- **TC-12** — AppAPI returns `['error' => ...]`: assert `ProviderUnavailableException` and that the error text
  is **not** returned as the model's answer. Cover **both** shapes: ExApp-not-found
  (`PublicFunctions.php:36-41`) and the catch-and-return that swallows every transport failure incl. timeouts
  (`AppAPIService.php:101-113`). AppAPI never throws, so a naive caller's default outcome is a fake completion.
- **TC-13** — runner returns a non-2xx: assert the status is checked **before** the body is decoded
  (`http_errors => false`, `:184`, makes a 502 an ordinary `IResponse`) and that it raises.
- **TC-14** — AppAPI or `hermiq-llm-runner` not enabled, and separately a null `$appManager` (`:193` is
  nullable-defaulted): assert a 503 naming the missing component, raised **before** any credential resolution.
- **TC-15** — AppAPI absent entirely: assert an `executionMode: http` turn succeeds unaffected. Pins the lazy
  `class_exists()` resolution — a hard `use` would break boot for every Hermiq install without AppAPI.

### Requirement: The CLI completion is mapped back into the driver response and the SSE envelope

| TC | Scenario | Type | Command |
|---|---|---|---|
| TC-16 | the completion becomes the agent's answer | Unit | `composer test -- --filter ProviderFactoryTest` |
| TC-17 | the envelope does not change shape for cli | Regression | `/test-regression` (existing chat coverage) |

- **TC-16** — a 200 carrying `{text, toolCalls: [], usage}`: assert `text` becomes the answer and `usage` is
  recorded in the same shape the `http` branch records (`ResponseGenerationHandler.php:354-357`). Also assert
  `toolCalls` is **ignored** rather than acted on — it is structurally always `[]`
  (`providers.js:119-122`), and building on it would be building on a phantom.
- **TC-17** — the existing chat/SSE coverage must pass **unchanged**. `ChatStreamController` is not modified,
  and this requirement is precisely that nothing user-visible changes. No new test is written for an
  unchanged file; the regression suite passing *is* the assertion.

## Live verification (manual — cannot be automated)

Needs a real Claude Max token + the ExApp container, so it runs against the dev instance by hand:

| TC | Check | Expected |
|---|---|---|
| LV-1 | Set `anthropic` → `executionMode: cli` with a **personal** Max token; run a TEXT-ONLY chat turn in the widget | A real completion arrives via the SSE `final`; zero `token` events |
| LV-2 | Run a TOOL-USING agent turn on the same config | **Refused** with a clear 503 naming tools |
| LV-3 | Inspect the Nextcloud log for the whole session | The token appears **nowhere** |
| LV-4 | Set `executionMode` back to `http` | Existing behaviour, unaffected |

**LV-2 is the one that matters.** A healthy-looking answer there means the turn ran tool-less — the exact
defect this link exists to prevent. "It worked" is the failure signal.

## Coverage Summary

| Requirement | Scenarios | Covered by |
|---|---|---|
| Execution mode selects the transport | 3 | TC-1 – TC-3 |
| Tool-bearing turns are refused | 3 | TC-4 – TC-6, LV-2 |
| Token resolved via broker, never persisted | 3 (+1 security) | TC-7 – TC-10, LV-3 |
| AppAPI dispatch, timeout, failure surfacing | 5 | TC-11 – TC-15 |
| Completion mapped back | 2 | TC-16 – TC-17, LV-1 |

All 16 spec scenarios are covered. **Deliberately untested:**

- **A real `claude -p` invocation.** Needs a billed subscription; the runner's own container tests
  (`exapp/llm-runner/test/test.sh`) already cover `/run` against **stub CLIs**, including that the credential
  never reaches argv or logs. Hermiq's tests mock at the AppAPI seam and do not re-test the runner.
- **The runner itself.** Unchanged by this link apart from a deleted comment (TC-6).
- **The SSE envelope's internals.** `ChatStreamController` is unchanged; existing coverage applies (TC-17).
- **`--tools ""` / MCP argv assertions.** Link 3's territory; no tool transport exists here to test.

## Promotion after implementation

TC-4 and TC-5 (the tools-refusal pair) carry ongoing regression value beyond this change: they encode a rule
that a well-meaning future refactor is *likely* to break, and whose breakage is invisible at runtime. Promote
them to a reusable scenario via `/test-scenario-create` so they survive this change's archive.

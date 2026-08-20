# Tasks: cli-runner-text-turn-dispatch

Chain link 2 of 3 (ADR-032). Predecessor `cli-runner-credential-declaration` (`config`) MUST be closed first:
without its `anthropic-cli` inject-only provider, `resolveInjectable()` returns null for every credential and
this dispatch fails closed by design. Link 3 (`cli-runner-governed-mcp-and-egress`) depends on this one.

**Scope discipline:** this link is **text-only**. Tools are refused, not served. If you find yourself adding a
`tools` field to the runner payload, stop — `claude -p` accepts no tool schema, and that is link 3's job via
governed MCP.

## Implementation Tasks

### Task 1: Replace the 503 stub and thread `executionMode` to the transport
- **spec_ref**: `openspec/changes/cli-runner-text-turn-dispatch/specs/cli-execution-mode/spec.md#requirement-execution-mode-selects-the-anthropic-transport-and-defaults-to-http`
- **files**: `lib/Service/Llm/ProviderFactory.php`, `lib/Service/Engine/ResponseGenerationHandler.php`, `lib/Service/Engine/ConversationManagementHandler.php`, `tests/Unit/Service/Llm/ProviderFactoryTest.php`
- **acceptance_criteria**:
  - GIVEN the 503 stub at `ProviderFactory.php:1361-1368` WHEN `executionMode` is `cli` THEN it no longer throws; `createAnthropicDriver()` passes `executionMode` into the `ChatDriver` constructor (`:1372-1379`, which today passes nothing and silently defaults to `http`)
  - `ChatDriver.php` is UNCHANGED — `public readonly string $executionMode='http'` already exists at `:80` with the right name, type and default. Verified against HEAD; do not touch it
  - GIVEN all three anthropic call sites drop `executionMode` today (`ProviderFactory::generateText():1106-1114`, `ResponseGenerationHandler.php:343`, `ConversationManagementHandler.php:468`) WHEN each is updated THEN each passes `executionMode: $driver->executionMode`
  - `callAnthropicChat()` gains `string $executionMode='http'` with a default, so the signature stays backward-compatible and any un-updated call site keeps today's exact behaviour
  - GIVEN no `executionMode` in config WHEN a turn runs THEN it goes over `http` exactly as today — no dependency on AppAPI or the ExApp
  - This threading is not boilerplate: a driver carrying a mode nobody reads is the CURRENT bug. Skip it and `cli` is selected in settings, accepted by the factory, then silently ignored — the same failure shape as the `tools` comment
- [x] Implement
- [x] Test

> **Note (implementation):** line refs verified against HEAD and accurate to ±1 — the stub was at `:1362-1368`
> and the `ChatDriver` construction at `:1373-1380`. An unrecognised `executionMode` is normalised to `http`
> rather than passed through, so an unknown value can never select a transport that does not exist.
> `executionMode` has **no UI control** in `src/` — it is settable only via the LLM settings API payload
> (`anthropicConfig.executionMode`), which `LlmSettingsHandler` already persists.

### Task 2: Refuse tool-carrying `cli` turns, and delete the comment that lies about tools
- **spec_ref**: `openspec/changes/cli-runner-text-turn-dispatch/specs/cli-execution-mode/spec.md#requirement-a-cli-turn-that-carries-tools-is-refused-never-run-tool-less`
- **files**: `lib/Service/Llm/ProviderFactory.php`, `exapp/llm-runner/src/server.js`, `tests/Unit/Service/Llm/ProviderFactoryTest.php`
- **acceptance_criteria**:
  - GIVEN `executionMode: cli` and a non-empty `$functions` WHEN the turn is issued THEN raise `ProviderUnavailableException` (503) naming tools as the reason and pointing at link 3 — BEFORE the credential is resolved and BEFORE the ExApp is called, so a doomed turn spends no subscription quota and pulls no secret from the vault
  - Do NOT copy the fail-open 250 lines up in the same file (`ProviderFactory.php:624-635`: tools present + no executor → `logger->warning(... 'running text-only')` → proceed). A tool-less agent looks completely healthy and simply never calls a tool; no log alarms on it. Hermiq pins `tools:['__none__']` elsewhere for exactly this reason
  - Do NOT fall back to `http` for tool-carrying turns — a different transport with a different credential is a confusing failure far from its cause. An operator who selected `cli` must get a clear signal
  - GIVEN `server.js:110` destructures `{provider, model, messages, credentialEnv}` with no `tools` and `runner.js:112` has no `tools` parameter WHEN `server.js:121` is read THEN the false "`tools` is accepted and passed through" comment is GONE. This is the ONLY runner edit in this link
  - GIVEN the refusal WHEN a test runs THEN the raise is pinned, so a future "make it more robust" refactor that reintroduces degradation breaks the build rather than the boundary
  - Removing the stub makes this latent defect LIVE — that is precisely why the refusal ships in the same change as the removal
- [x] Implement
- [x] Test

> **Note (implementation):** the refusal is the FIRST statement of `callAnthropicCli()`, ahead of the
> availability guard and the credential. `testCliToolRefusalPrecedesCredentialAndDispatch()` pins the ORDER, not
> just the outcome: the test factory has no app manager, so if the refusal were moved after the availability
> guard the message would name the app manager instead of tools and the test breaks.

### Task 3: Resolve the subscription token through the broker, fail closed
- **spec_ref**: `openspec/changes/cli-runner-text-turn-dispatch/specs/cli-execution-mode/spec.md#requirement-the-subscription-token-is-resolved-through-the-broker-and-never-persisted-by-hermiq`
- **files**: `lib/Service/Llm/ProviderFactory.php`, `tests/Unit/Service/Llm/ProviderFactoryTest.php`
- **acceptance_criteria**:
  - GIVEN a `cli` turn WHEN the credential is needed THEN call `CredentialBrokerService::resolveInjectable($credentialId, 'hermiq', $uid)` — Hermiq's FIRST caller (`grep -rn resolveInjectable lib/` = zero hits today). Resolve the broker lazily via the `BrokerHttpClient` pattern (`class_exists()` + `Server::get()`, `BrokerHttpClient.php:72, 140-143, 182`)
  - GIVEN `resolveInjectable()` returns null WHEN the turn is dispatched THEN raise `ProviderUnavailableException`. null is a ROUTING signal ("not inject-only — use `request()`", `CredentialBrokerService.php:266-269`), but a CLI has no `request()` fallback, so for this path it is fatal. Do NOT silently fall back to `http` with a token Anthropic refuses anyway
  - GIVEN a resolved token WHEN it is sent THEN it goes into the payload's `credentialEnv` as `{CLAUDE_CODE_OAUTH_TOKEN: <token>}` — the key the runner's anthropic adapter allowlists (`providers.js:132`). A key outside the allowlist is dropped WITHOUT an error (`runner.js:89-100`), so a typo yields an unauthenticated CLI, not a 400
  - GIVEN a Claude Max/Pro OAuth credential at organisation scope WHEN a turn is issued THEN refuse it — PERSONAL-SCOPE ONLY per the Anthropic ToS; the token serves only its owner
  - GIVEN the resolved token WHEN any code path is inspected THEN it is a local variable passed straight into the dispatch payload: never stored on the `ChatDriver` (handlers hold that object), never logged, never in an exception message, never in the run trace
  - The broker still enforces Guard 1 (owner/IDOR, `:261`) and Guard 2 (`allowedApps`, `:264`) — do not reimplement either. The token transiting Hermiq weakens the broker's "the app never sees the secret" property; that is forced (a CLI needs it in its env) and bounded — see design.md "The token leaves the vault"
- [x] Implement
- [x] Test

> **Note (implementation) — a stated fact was WRONG.** design.md claims "the owner guard (`:261`) enforces the
> mechanism" for personal-scope-only. It does **not**. `CredentialBrokerService::loadAdmittedCredential()`
> explicitly branches on scope: for an organisation-scope credential it calls `assertOrganisationMember()`, which
> admits **any organisation member**. `resolveInjectable()` also returns a bare `string|null` and its `scopeOf()`
> is private, so the broker can neither enforce nor report scope. The ToS rule therefore had to be enforced
> caller-side, and this link is genuinely its only enforcement point.
>
> Implemented as `CredentialScopeResolver::scopeOfCredential()` — a scope-by-id lookup on the class that is
> already this app's sanctioned reader of that collection (its own docblock records the precedent) — consumed by
> `ProviderFactory::assertPersonalScopeCredential()`, which runs BEFORE `resolveInjectable()` and **fails closed**
> when the scope is unknown or unverifiable. This widens Task 3's file list by one file
> (`lib/Service/Credential/CredentialScopeResolver.php`).
>
> Guard line refs also drifted: Guard 1 is at `:256` and Guard 2 at `:261` (contract.md says `:261`/`:264`).

### Task 4: Dispatch over AppAPI — explicit timeout, availability guard, failure-channel discipline
- **spec_ref**: `openspec/changes/cli-runner-text-turn-dispatch/specs/cli-execution-mode/spec.md#requirement-the-turn-is-dispatched-over-appapi-with-an-explicit-timeout-and-every-failure-is-surfaced`
- **files**: `lib/Service/Llm/ProviderFactory.php`, `tests/Unit/Service/Llm/ProviderFactoryTest.php`
- **acceptance_criteria**:
  - GIVEN AppAPI defaults `timeout` to 3 SECONDS (`AppAPIService::prepareRequestToExApp():189-191`) and the runner allows the CLI 120s (`runner.js:28`) WHEN the request is built THEN pass an explicit `options['timeout']` of `RUNNER_TIMEOUT_MS + 30s` (150s). WITHOUT THIS THE FEATURE IS 0% FUNCTIONAL — every turn times out at 3s while the container runs to completion and bills the user. Pin it with a test asserting the option is present and exceeds the runner's timeout, so it cannot regress to the default
  - GIVEN AppAPI NEVER throws WHEN the outcome is read THEN check in this exact order: (1) `is_array($result)` with an `error` key — it catches `\Exception` and returns `['error' => ...]` for every transport failure incl. timeouts (`AppAPIService.php:101-113`) and returns `['error' => 'ExApp ... not found']` (`PublicFunctions.php:36-41`); (2) `getStatusCode()` is 2xx — `http_errors => false` (`:184`) makes a 502 an ordinary `IResponse`; (3) only then decode and require a usable `text`. Any other order reads an error string as the model's answer
  - GIVEN each failure shape WHEN detected THEN convert to `ProviderUnavailableException` with a static, generic client message and the real detail logged server-side (ADR-005). Never echo an arbitrary AppAPI payload; no message may contain the token
  - GIVEN the seam WHEN it is called THEN use `OCA\AppAPI\PublicFunctions::exAppRequest('hermiq-llm-runner', '/run', $uid, 'POST', $params, $options)` — the turn goes in `$params` (AppAPI JSON-encodes it as the body, `:193-198`). `exAppRequestWithUserInit()` is DEPRECATED since AppAPI 3.0.0 — do not use it
  - GIVEN AppAPI is optional WHEN it is resolved THEN resolve `PublicFunctions` lazily by class-name string (`class_exists()` + `Server::get()`), never a hard `use` or constructor type — Hermiq MUST still boot and still serve `http` without AppAPI. `AppAPIService` internals are EVIDENCE for these facts, not an API to call
  - GIVEN the availability check WHEN `cli` is selected THEN verify the injected `$appManager` is non-null (`:193`, nullable-defaulted), `app_api` is enabled, and `hermiq-llm-runner` is enabled — each failing with a 503 naming the missing component, before any credential is resolved. This gives `APP_API_ID` (`:99`), `RUNNER_EXAPP_ID` (`:106`), `RUNNER_ROUTE` (`:113`) and `$appManager` their first call sites; all four are orphaned scaffolding today
- [x] Implement
- [x] Test

> **Note (implementation) — the ExApp check could NOT use `$appManager`.** ExApps are not Nextcloud apps: they
> live in AppAPI's own `ex_apps` table (`ExAppMapper`) and are invisible to `IAppManager` — verified, `occ
> app:list` on the running instance lists `app_api` and no ExApp. `IAppManager::isInstalled('hermiq-llm-runner')`
> would therefore report the runner missing even when it is deployed and healthy, making `cli` permanently
> unavailable. The runner is instead checked through AppAPI's own public seam,
> `PublicFunctions::getExApp($appId)` (verified `:117-129`), which returns `{appid, version, name, enabled}` or
> null — giving separate 503s for "not installed" and "installed but disabled". `$appManager` still gets its
> first call site, for `app_api` itself.
>
> `isInstalled()` is deprecated since NC 32 in favour of `isEnabledForAnyone()`, but hermiq's `info.xml` declares
> `min-version="30"`, so the newer method is unsafe here; `isInstalled()` is also the established pattern in this
> codebase (5 existing call sites).
>
> Both AppAPI traps verified on the installed 34.0.0: `timeout` default 3s at `AppAPIService.php:189-190`,
> `http_errors => false` at `:184`, `catch (\Exception) { return ['error' => ...] }` at `:111`, `$options['json']
> = $params` at `:193-198`, and `PublicFunctions.php:40` returning `['error' => 'ExApp ... not found']`.

### Task 5: Map the CLI completion back into the driver response and the SSE envelope
- **spec_ref**: `openspec/changes/cli-runner-text-turn-dispatch/specs/cli-execution-mode/spec.md#requirement-the-cli-completion-is-mapped-back-into-the-driver-response-and-the-sse-envelope`
- **files**: `lib/Service/Llm/ProviderFactory.php`, `lib/Service/Engine/ResponseGenerationHandler.php`, `tests/Unit/Service/Llm/ProviderFactoryTest.php`
- **acceptance_criteria**:
  - GIVEN a 200 from the runner (`{text, toolCalls, usage}`, `server.js:127-131`) WHEN mapped THEN `text` becomes the turn's answer and `usage` is recorded in the same shape the `http` anthropic branch records (`ResponseGenerationHandler.php:354-357`) — the `cli` path is no blinder than the path it mirrors
  - GIVEN `toolCalls` is structurally ALWAYS `[]` (`pickToolCalls()` reads a key nothing populates because `run()` has no `tools` — `providers.js:119-122`, `runner.js:112`) WHEN mapping THEN ignore it. Do NOT build behaviour on it or treat a non-empty value as reachable
  - `ChatStreamController.php` is UNCHANGED — the envelope already contracts for exactly the CLI's shape: "non-streaming providers degrade to zero `token` events plus one `final` carrying the full text" (`:12-14, :57-59`). Verified against HEAD
  - Do NOT chop the completion into synthetic `token` events to fake streaming — that misrepresents the transport for cosmetic gain
  - GIVEN a `cli` turn WHEN the SSE stream is consumed THEN it emits zero `token` events and exactly one terminal `final`, no new event type, and a client cannot tell which transport served the turn
- [x] Implement
- [x] Test

> **Note (implementation) — a gap the spec did not name.** `mapHistoryToAnthropicMessages()` could NOT be reused
> for the runner payload: it hoists system turns into a separate top-level `system` field (which the Messages API
> requires) but the runner's `/run` has **no `system` field**. Reusing it would have SILENTLY DROPPED the system
> prompt — the agent's entire persona and instructions — on every `cli` turn, while still returning a confident
> answer. A separate `mapHistoryToCliMessages()` carries the system turn **in-band**, which matches contract.md's
> own payload shape (`messages: [{role: "system|user|assistant", content}]`) and the runner's `buildPrompt()`
> rendering of `ROLE: content`. Pinned by `testCliMessageMappingKeepsTheSystemPromptInBand()`.
>
> `usage` is deliberately discarded: `ResponseGenerationHandler`'s `lastUsage` records latency only
> (`['llmSeconds' => ...]`) and is shared by both branches, so the `cli` path mirrors the `http` shape exactly
> with no change — as required. `ChatStreamController.php` and `ChatDriver.php` are untouched.

### Task 6: Operator-facing errors, docs, and live verification
- **spec_ref**: `openspec/changes/cli-runner-text-turn-dispatch/specs/cli-execution-mode/spec.md#requirement-execution-mode-selects-the-anthropic-transport-and-defaults-to-http`
- **files**: `l10n/`, `docs/`, `tests/Unit/Service/Llm/ProviderFactoryTest.php`
- **acceptance_criteria**:
  - GIVEN every operator-facing 503 from a failed `cli` turn WHEN surfaced THEN it names WHICH component is missing or WHY the turn was refused (tools / no ExApp / no AppAPI / no token / org-scope) — never a generic "cli unavailable". i18n keys are ENGLISH; ship `nl_NL` and `en_US` strings (ADR-007)
  - GIVEN `ProviderFactory.php:970-977` already tells the operator to switch to `executionMode: cli` when the direct API refuses a subscription token WHEN this change lands THEN that advice finally works — verify the message still reads correctly now that the door it points at is open
  - GIVEN `docs/` (ADR-010) WHEN updated THEN state: `cli` is text-only in this release and tool-using agents must stay on `http` until governed MCP lands; Claude Max/Pro is PERSONAL-SCOPE ONLY per the Anthropic ToS; the runner has NO Nextcloud access in this link. Deploy via the `documentation` branch
  - Any example token/UUID in docs or tests MUST be obviously fake (`YOUR_TOKEN_HERE`, `CHANGE_ME`, nil UUID `00000000-0000-0000-0000-000000000000`) — gitleaks flags entropic-looking values
  - Live-verify: install the runner ExApp, set `anthropic` to `executionMode: cli` with a personal Claude Max token, run a TEXT-ONLY chat turn through the widget, and confirm a real completion arrives via the SSE `final`. Then run a TOOL-USING agent turn and confirm it is REFUSED with a clear 503 — not silently answered tool-less. The second check is the one that matters: a healthy-looking answer there is the defect
- [x] Implement — error messages + docs
- [ ] Test — **live verification OUTSTANDING**, deliberately not claimed

> **Note (implementation) — NOT fully done; read this before archiving.**
>
> 1. **Live-verify is OUTSTANDING.** The runner ExApp is not deployed and this requires a real Claude Max token,
>    so it is the repo owner's step. **This box stays unticked deliberately**: ticking it would be exactly the
>    phantom-done this chain exists to correct. Note the tool-refusal check must be driven through an agent turn,
>    since `executionMode` has no UI control (see Task 1's note).
>
> 2. **The i18n criterion CONTRADICTS the ADR-007 it cites, and was NOT followed.** ADR-007 states: *"Log
>    messages, internal exceptions, and database values are NOT translated"* and *"**Controllers** returning
>    user-facing messages MUST inject `OCP\IL10N`"*. Every 503 here is thrown from a Service as an internal
>    exception, and `lib/Service/Llm/` contains zero `IL10N` usage — the sibling 503s in this very method
>    (`'Anthropic has no credential…'`, `'…the OpenRegister credential broker is not available.'`) are plain
>    English. Translating only the new ones would introduce an inconsistent pattern ADR-007 forbids at this layer.
>    ADR-007 also mandates **`en.json` + `nl.json`**, not the `nl_NL` + `en_US` this criterion asks for; hermiq
>    ships `en.json`, `en_US.json`, `nl.json` and has **no `nl_NL.json`**. **Decision: no l10n keys added**;
>    messages are plain English, consistent with ADR-007 and every neighbouring message. Flagged for the owner.
>
> 3. The `ProviderFactory` 429 handler's advice ("run the subscription through the hermiq-llm-runner ExApp
>    (executionMode: cli)") was re-read and **still reads correctly** now the door it points at is open — no
>    change needed. Its line ref drifted to `:1487-1495`.
>
> 4. Docs: `docs/using-claude.md` updated — the `http`/`cli` transport table, `cli` is text-only in this release
>    (tool-using agents stay on `http`), personal-scope-only, the runner has no Nextcloud access in this link, and
>    the fact that `executionMode` is config-only. The page previously documented **only** the Max-token→direct-API
>    flow that Anthropic categorically refuses, and its "Claude agents can call the same governed tools" note is
>    now qualified per transport. Docs deploy from the `documentation` branch (not done here).

## Quality checklist

- All new/changed business logic covered by PHPUnit unit tests (`tests/Unit/`); the runner's text path is already covered by the ExApp's own container tests (`exapp/llm-runner/test/test.sh`) and is unchanged by this link
- `composer check:strict` (PHPCS, PHPMD, Psalm, PHPStan) and PHPUnit green
- No Playwright coverage: this change has no UI surface (each spec Scenario carries a reason-bearing `@e2e exclude` for gate-19)
- No Newman/Postman coverage: this change adds no route and no app API surface — it is an outbound transport
- Every changed public/protected method carries an `@spec` tag (gate-16). Point `@spec` at the canonical `openspec/specs/` path once archived — never at a change dir, which evaporates on archive
- No seed data task: this change introduces and modifies no OpenRegister schemas (design.md "Seed Data"). `executionMode` is an existing key in an existing `IAppConfig` blob, which is not a schema
- No migration: no tables, no columns, no schema definitions, no data transformations (design.md "Database Changes")
- `executionMode: http` (the default) must be bit-for-bit unaffected — verify by running the existing anthropic `http` tests unchanged
- Two AppAPI defaults are traps and BOTH must be overridden explicitly: the 3s timeout, and the never-throws failure channel. Neither is visible in the signature — see design.md "AppAPI's two defaults are traps"
- If a fix seems to need a `tools` field on the runner payload, STOP — that is link 3, and adding it here recreates the orphaned-capability defect this chain exists to correct
- `openspec validate --strict` passes

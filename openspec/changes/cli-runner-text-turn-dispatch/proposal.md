---
kind: code
depends_on:
  - cli-runner-credential-declaration
---

# Proposal: cli-runner-text-turn-dispatch

## Summary

`executionMode: cli` is **dead code today**. `ProviderFactory::createAnthropicDriver()` throws
`ProviderUnavailableException` (503) on it outright (`lib/Service/Llm/ProviderFactory.php:1361-1368`), and the
`ChatDriver::$executionMode` field it would set has **no consumer** anywhere in the app
(`lib/Service/Llm/ChatDriver.php:80`; `grep -rn executionMode lib/` returns only the settings handler that
persists it and the stub that rejects it). This change replaces the stub with real dispatch for **text-only**
turns: resolve the Claude Max OAuth token through OpenRegister's credential broker, POST the assembled turn to
the `hermiq-llm-runner` ExApp's `/run` via AppAPI, and map `{text, usage}` back into the `ChatDriver` response
and the six-event SSE envelope. A turn that carries **tools** MUST fail loudly — never run tool-less. This is
chain link 2 of 3 (ADR-032) and is independently shippable: it makes Claude-Max-powered chat work within
Anthropic's ToS, without waiting for the governed tool transport that link 3 builds.

## Motivation

1. **`cli` mode is the only ToS-compliant path for a Claude Max/Pro subscription, and it does not work.**
   Anthropic hard-refuses a subscription OAuth token on the raw Messages API. Hermiq already *tells* the
   operator to use `executionMode: cli` when it detects that refusal — `ProviderFactory.php:975`: "run the
   subscription through the hermiq-llm-runner ExApp (executionMode: cli), which uses the official Claude CLI."
   That advice currently leads to a 503. The app points at a door that is nailed shut.

2. **Everything the text path needs already exists and is unwired.** The runner already allowlists
   `credentialKeys: ['CLAUDE_CODE_OAUTH_TOKEN', 'ANTHROPIC_API_KEY']` for anthropic and invokes
   `bin: 'claude'` with `args: ['-p', '--output-format', 'json']` (+ `['--model', model]`) —
   `exapp/llm-runner/src/providers.js:124-135`. `claude -p --output-format json` emits
   `{type:"result", result:"<text>", usage:{...}}`, which `parseClaudeJson()` already parses correctly.
   `ProviderFactory` already declares the constants for the dispatch it never makes — `APP_API_ID = 'app_api'`
   (`:99`), `RUNNER_EXAPP_ID = 'hermiq-llm-runner'` (`:106`), `RUNNER_ROUTE = '/run'` (`:113`) — and already
   injects the `IAppManager` whose docblock says it exists "ONLY by the `cli` execution-mode path"
   (`:152, :193`). All three constants and the injected manager have **zero call sites**. The wiring is the
   whole job.

3. **Removing the stub makes a latent orphaned-capability defect LIVE — so this link must fail loudly.**
   `exapp/llm-runner/src/server.js:110` destructures `{provider, model, messages, credentialEnv}` with **no
   `tools`**, while the comment at `server.js:121` falsely claims "`tools` is accepted and passed through the
   assembled turn". `runner.js:112` — `function run({ provider, model, messages, credentialEnv })` — has no
   `tools` parameter either, so `providers.js pickToolCalls()` (`:119-122`) can only ever return `[]`. A `cli`
   turn carrying tools would run **tool-less and look perfectly healthy**. That defect is masked today only
   because the 503 stub makes the path unreachable. The moment this change removes the stub, it goes live.
   The mitigation is not to build tool support here (the CLI cannot accept a tool schema — link 3's territory)
   but to **refuse the turn**, loudly, with a message naming the reason.

4. **The fail-open trap is already in this file — the `cli` path must not copy it.**
   `ProviderFactory::callAnthropicChat()` logs a warning and runs the turn **text-only** when tools are present
   without an executor (`lib/Service/Llm/ProviderFactory.php:624-635`). Hermiq pins `tools:['__none__']`
   elsewhere for exactly this reason. A tool-less agent never announces itself; it simply never calls a tool.

## Affected Projects

- [x] Project: `hermiq` — `ProviderFactory::createAnthropicDriver()` stops throwing and sets
      `executionMode` on the `ChatDriver`; a new `callAnthropicCli()` dispatch path resolves the token via
      OpenRegister's `CredentialBrokerService::resolveInjectable()`, POSTs the turn through AppAPI's
      `PublicFunctions::exAppRequest()`, maps `{text, usage}` back, and **raises** when the turn carries tools;
      `executionMode` is threaded from the driver to the three anthropic call sites that today drop it.
- [x] Project: `hermiq` (`exapp/llm-runner`) — **one deletion only**: the false `tools`-passthrough comment at
      `src/server.js:121`. No behaviour change in the runner; it already does exactly what this link needs.

## Scope

### In Scope

- **Replace the 503 stub** at `ProviderFactory.php:1361-1368`. `createAnthropicDriver()` passes
  `executionMode` through to the `ChatDriver` constructor (which already accepts it and today receives
  nothing, defaulting to `http` — `:1372-1379`).
- **Availability check, fail-closed.** `cli` mode raises `ProviderUnavailableException` (503) naming the
  missing component when AppAPI or the `hermiq-llm-runner` ExApp is not installed/enabled, or when the
  injected `IAppManager` is null. This honours the `llm-cli-runner-exapp` requirement "cli mode without the
  ExApp fails clearly" — currently satisfied only by accident, because *every* `cli` turn fails.
- **Thread `executionMode` from `ChatDriver` to the call sites.** All three anthropic call sites
  (`ProviderFactory::generateText():1106-1114`, `ResponseGenerationHandler.php:343`,
  `ConversationManagementHandler.php:468`) pass `credentialId`/`model`/`baseUrl`/`authMode` and **drop**
  `executionMode`. Each gains the one named argument; `callAnthropicChat()` gains a single branch at the top.
- **Resolve the token app-side** via `CredentialBrokerService::resolveInjectable($credentialId, 'hermiq', $uid)`
  using the `anthropic-cli` inject-only provider that link 1 declares. This is Hermiq's **first** caller of
  `resolveInjectable()` (`grep -rn resolveInjectable lib/` = zero hits today).
- **Dispatch the turn** to the ExApp `/run` with `credentialEnv: {CLAUDE_CODE_OAUTH_TOKEN: <token>}`, and map
  `{text, usage}` back into the `ChatDriver` response and the six-event SSE envelope
  (`token`/`tool_call`/`tool_result`/`heartbeat`/`final`/`error`, hydra ADR-034 Decision 6 — see
  `lib/Controller/ChatStreamController.php:6-14`). The CLI is non-streaming, so it degrades to the envelope's
  already-contractual "zero `token` events plus one `final`" shape (`ChatStreamController.php:57-59`) —
  clients never branch, and no SSE change is needed.
- **FAIL LOUDLY when the turn carries tools.** `ProviderUnavailableException` naming the reason, raised
  **before** the ExApp is called. Never a silent tool-less run.
- **Reject Claude Max/Pro OAuth at organisation scope** — personal-scope only per the Anthropic ToS. Carried
  forward unchanged from `anthropic-agent-provider`.
- **Remove the false comment** at `exapp/llm-runner/src/server.js:121`.

### Out of Scope

- **Tools of any kind.** `claude -p` cannot accept arbitrary tool schemas: `--tools` selects from the
  **built-in** set only; `--allowedTools`/`--disallowedTools` filter tool **names**. Custom tools reach the
  CLI **only via MCP**. The governed MCP tools endpoint and the governed egress proxy are link 3,
  `cli-runner-governed-mcp-and-egress`. This link does not spec tool-schema dispatch — it refuses it.
- **The `anthropic-cli` credential provider JSON and the Hermiq manifest declaration** — link 1,
  `cli-runner-credential-declaration` (`kind: config`; ADR-032 forbids a `mixed` envelope).
- **`openai`/`grok` CLI support.** `providers.js` ships a `codex` adapter (`:135-140`) and an unverified
  `grok` placeholder. Anthropic only.
- **Any change to the runner's behaviour.** The only runner edit is deleting a comment that lies.
- **`executionMode: http`** — the default, untouched throughout. Every existing config is unaffected.

## Approach

A three-link chain (ADR-032 — one envelope would be `kind: mixed`, which the ADR rejects outright; the
thin-glue exception does not apply at this size):

| # | Change | `kind` | Ships |
|---|---|---|---|
| 1 | `cli-runner-credential-declaration` | `config` | OpenRegister `anthropic-cli` inject-only provider + Hermiq `src/manifest.json` credential entry |
| 2 | **`cli-runner-text-turn-dispatch`** (this change) | `code` | `executionMode:cli` → AppAPI `/run`; **text-only, fails loudly if the turn requests tools**; the false-comment removal |
| 3 | `cli-runner-governed-mcp-and-egress` | `code` | Governed MCP endpoint + per-run auth + `--tools ""`/`--strict-mcp-config` + governed web access |

This link is the chain's shippable middle: a text-only `cli` turn is useful on its own — it is the difference
between a Claude Max subscriber having working chat and having a 503. The dispatch seam is one branch at the
top of `callAnthropicChat()`, which keeps the change inside the file that already owns every provider branch.

**This link keeps the runner with NO Nextcloud access at all.** The `llm-cli-runner-exapp` hardening
("Scenario: no Nextcloud data is reachable") holds unmodified here: the runner receives a payload and returns
text. **Link 3 will REVISE that requirement** — narrowing "no Nextcloud access" to two token-gated Hermiq
origins (governed tools + governed egress), which is a *narrowing* of container capability overall. That
revision is link 3's to make and is deliberately not pre-empted here.

## New Dependencies

**None.**

- **AppAPI is already installed and enabled** — `app_api: 34.0.0`, verified via `occ app:list` on the dev
  instance. Its public seam is `OCA\AppAPI\PublicFunctions::exAppRequest(appId, route, userId, method,
  params, options, request): array|IResponse` (verified at
  `/var/www/html/apps/app_api/lib/PublicFunctions.php:29-41`). Hermiq resolves it **lazily**, exactly as
  `BrokerHttpClient` already resolves OpenRegister's broker (`class_exists()` + `Server::get()` behind
  `isAvailable()` — `lib/Service/Llm/BrokerHttpClient.php:72, 140-143, 182`), so Hermiq still boots when
  AppAPI is absent.
- **The runner ExApp itself already exists** (`exapp/llm-runner/`), shipped by `llm-cli-runner-exapp`.
- No new composer or npm packages.

## Impact

- **`lib/Service/Llm/ProviderFactory.php`** — the 503 stub is replaced; a `cli` branch is added at the top of
  `callAnthropicChat()`; a new private `callAnthropicCli()` performs resolve → dispatch → map. The three
  `APP_API_ID`/`RUNNER_EXAPP_ID`/`RUNNER_ROUTE` constants and the injected `IAppManager` gain their first
  call sites (they are orphaned today).
- **`lib/Service/Llm/ChatDriver.php`** — **UNCHANGED**. The field already exists with the right name, type
  and default (`:80`). Verified against HEAD; no task needed.
- **`lib/Service/Engine/ResponseGenerationHandler.php`, `lib/Service/Engine/ConversationManagementHandler.php`**
  — one named argument each (`executionMode: $driver->executionMode`).
- **`lib/Controller/ChatStreamController.php`** — **UNCHANGED**. The non-streaming degradation (zero `token`
  events + one `final`) is already contractual (`:12-14, :57-59`), which is exactly the CLI's shape.
- **`exapp/llm-runner/src/server.js`** — one comment deleted. `runner.js`, `providers.js`, `auth.js`,
  `deploy/*` all **UNCHANGED**.
- **`agent-engine-port`'s "Hermiq holds no LLM API key" requirement is NOT violated.** Its normative text is
  scoped to "an OpenAI or Fireworks API key" (`openspec/specs/agent-engine-port/spec.md:6-23`) — Anthropic is
  outside it. Its structural rule *is* honoured regardless: the stored config still carries `credentialId`,
  never a secret. The token's app-side transit is a conscious, bounded weakening recorded in design.md.
- **Unchanged**: every `http`-mode provider path, `Agent.tools`, OpenRegister's MCP server, the runner's
  hardening posture, `WebResearchEgressGuard`.

## Cross-Project Dependencies

- **OpenRegister** — consumed only through the existing abstraction
  `CredentialBrokerService::resolveInjectable()` (verified at
  `openregister/lib/Service/Credential/CredentialBrokerService.php:250-290`). It returns **null unless the
  provider `isInjectOnly()`** (`:266-269`), and still enforces the owner/IDOR guard (Guard 1, `:261`) and the
  `allowedApps` guard (Guard 2, `:264`). The `anthropic-cli` provider entry that makes it return non-null is
  **link 1's** deliverable, not this change's.
- **AppAPI 34.0.0** — installed on the dev instance; `PublicFunctions` is its supported public seam.
- **`cli-runner-credential-declaration` (link 1)** — hard predecessor. Without the `anthropic-cli` provider,
  `resolveInjectable()` returns null for the credential and this link's dispatch fails closed by design.

## Risks

### Risk 1: Removing the stub makes the runner's phantom `tools` support live

**Severity:** High — **Mitigation:** this is the reason the link exists in this shape. The runner cannot
honour tools (`server.js:110`, `runner.js:112`, `providers.js:119-122` — verified), and the comment at
`server.js:121` says it can. Removing the 503 unmasks that. Therefore: (a) the `cli` branch **raises**
`ProviderUnavailableException` before dispatch whenever `$functions` is non-empty, naming tools as the reason
and pointing at link 3 — it never degrades; (b) the lying comment is deleted in this same change, so the next
reader is not misled; (c) a unit test pins the raise, so a future refactor that reintroduces the degradation
breaks the build. Explicitly **not** copied: the `callAnthropicChat()` warn-and-continue at `:624-635`.

### Risk 2: The Claude Max token leaves the vault into Hermiq's PHP process

**Severity:** High — **Mitigation:** forced, not chosen. A CLI needs the token in its **environment**; there
is no proxy seam to interpose, and `CredentialBrokerService::request()` denies an `inject_only` provider
outright by design. This weakens the broker's central "the app never sees the secret" property, which the
host-locked proxy providers (`anthropic`, `anthropic-oauth`) do preserve. Bounded by: `resolveInjectable()`
still enforcing the owner/IDOR and `allowedApps` guards; the secret still living in Doriath with only a
`credentialRef` in config; the runner injecting it via **env only, never argv, never a log line**
(`runner.js:5-8`), allowlisting the env var names so the caller cannot smuggle `PATH`/`LD_PRELOAD`
(`selectCredentialEnv()`, `runner.js:89-100`), and redacting token-shaped strings from surfaced errors. Blast
radius is one user's personal subscription token, not an org key. Recorded in full in design.md.

### Risk 3: Claude Max/Pro OAuth is personal-scope only per the Anthropic ToS

**Severity:** Medium — **Mitigation:** reject at organisation scope; the token serves only its owner. Already
the rule from `anthropic-agent-provider`; carried forward unchanged and pinned by a test.

### Risk 4: A `cli` turn silently costs the user their subscription quota with no usage signal

**Severity:** Low — **Mitigation:** `claude -p --output-format json` returns `usage`, and the runner already
surfaces it (`providers.js` `parse`/`pickToolCalls` return `{text, toolCalls, usage}`). This link maps it into
the same `lastUsage` shape the anthropic HTTP branch already records
(`ResponseGenerationHandler.php:354-357`), so the `cli` path is no blinder than the `http` path it mirrors —
and no worse. Richer usage threading is a pre-existing gap on both branches, not this change's to close.

### Risk 5: AppAPI's default request timeout is 3 SECONDS — every `cli` turn would time out

**Severity:** High — **Mitigation:** verified, not assumed. `AppAPIService::prepareRequestToExApp()` sets
`$options['timeout'] = 3` when the caller does not supply one (`:189-191`), while the runner gives the CLI
**120 seconds** before SIGKILL (`runner.js:28` — `RUNNER_TIMEOUT_MS || '120000'`). A `claude -p` turn takes
far longer than 3s, so a dispatch that omits `timeout` **fails on literally every call** while the container
runs the turn to completion and bills it. The timeout **is** caller-overridable (the guard is
`if (!isset($options['timeout']))`), so the dispatch MUST pass an explicit
`options['timeout']` of `RUNNER_TIMEOUT_MS + 30s` (150s by default — the same slack link 3 uses for its
per-run token TTL). Pinned by a test that asserts the option is present and exceeds the runner's own timeout,
so the value cannot silently regress to the 3s default.

### Risk 6: AppAPI reports failure by RETURN VALUE, never by exception

**Severity:** Medium — **Mitigation:** two verified traps, both silent. (a) `exAppRequest()` returns
`['error' => "ExApp \`%s\` not found"]` rather than throwing when the ExApp is missing
(`PublicFunctions.php:36-41`), and `requestToExAppInternal()` **catches `\Exception` and returns
`['error' => $e->getMessage()]`** for *every* transport failure — timeouts included
(`AppAPIService.php:101-113`). (b) AppAPI sets `$options['http_errors'] = false` (`:184`), so a 4xx/5xx from
the runner arrives as a perfectly ordinary `IResponse`. A caller that assumes exceptions-on-failure would read
an error body as a model completion. The dispatch MUST therefore, in order: check `is_array($result)` for an
`error` key; check `getStatusCode()` is 2xx; and only then decode. Each converts into
`ProviderUnavailableException`. Pinned by tests.

## Rollback Strategy

Revert this change alone: `createAnthropicDriver()` returns to throwing 503 on `executionMode: cli`, which is
exactly today's behaviour — no config migration, no data change, nothing persisted differently. `ChatDriver`
already carried the field before this change and would simply return to having no consumer.
`executionMode: http` (the default) is untouched throughout, so every existing provider config is unaffected
either way; an operator who had switched to `cli` sets it back to `http`. Link 3 depends on this link, so
reverting this one requires reverting link 3 first if it has landed.

## Open Questions

**Resolved** (decided 2026-07-16, recorded here so the reasoning survives):

1. ~~Where does the `cli` branch live — the driver factory, the call sites, or `callAnthropicChat()`?~~
   **`callAnthropicChat()`.** It is the only place that already receives `$functions`, so it is the only place
   that can make the fail-loud-on-tools decision without a second seam. `createAnthropicDriver()` merely stops
   throwing and sets the field. See design.md.
2. ~~Can this link `MODIFIED`-delta the `llm-cli-runner-exapp` spec?~~ **No.** That change is **not archived**
   — there is no canonical `openspec/specs/llm-cli-runner-exapp/`, so there is nothing to MODIFY against. This
   change therefore lands a **new** capability, `cli-execution-mode`. See design.md, "Capability choice".
3. ~~Does AppAPI honour a per-request timeout long enough for a `claude -p` turn?~~ **Yes, but ONLY if the
   caller passes one** — the default is **3 seconds** and would break every turn. Verified against the
   installed AppAPI 34.0.0; see discovery.md. This was the single highest-value finding of the discovery and
   is now Risk 5.

**Open:**

4. **Is `RUNNER_TIMEOUT_MS + 30s` the right Hermiq-side timeout, or should the runner's timeout be
   discoverable?** The runner's timeout is a container env var Hermiq cannot read, so Hermiq hard-codes a
   value derived from the runner's *default*. An operator who raises `RUNNER_TIMEOUT_MS` for long turns would
   have Hermiq give up first. See DEFERRED_QUESTIONS.

# Design: cli-runner-text-turn-dispatch

## Architecture Overview

Every Anthropic turn today converges on `ProviderFactory::callAnthropicChat()`
(`lib/Service/Llm/ProviderFactory.php:600-683`), which loops `postToAnthropic()` (`:651`) against
`https://api.anthropic.com/v1/messages`. This change adds a **second transport under the same seam**: when the
driver's `executionMode` is `cli`, the turn is dispatched to the `hermiq-llm-runner` ExApp, which runs the
official `claude` CLI. Nothing above the seam changes — the Engine, the handlers and the SSE controller do not
know which transport ran.

```
  Engine / ResponseGenerationHandler / ConversationManagementHandler
                    │  (pass executionMode: $driver->executionMode)
                    ▼
        ProviderFactory::callAnthropicChat()
                    │
        ┌───────────┴────────────┐
        │ executionMode          │
        ▼                        ▼
     'http' (default)          'cli'  ── NEW
   postToAnthropic()          callAnthropicCli()          ── NEW (private)
        │                        │
        │                        ├─ 0. $functions non-empty?  ──► RAISE 503 (never degrade)
        │                        ├─ 1. availability: IAppManager + AppAPI + ExApp enabled ──► RAISE 503
        │                        ├─ 2. CredentialBrokerService::resolveInjectable(id,'hermiq',$uid)
        │                        │        └─ null (not inject_only / wrong owner / app not allowed) ──► RAISE 503
        │                        ├─ 3. AppAPI PublicFunctions::exAppRequest(
        │                        │        'hermiq-llm-runner', '/run', $uid, 'POST',
        │                        │        {provider, model, messages, credentialEnv:{CLAUDE_CODE_OAUTH_TOKEN}})
        │                        └─ 4. map {text, usage} ──► string
        ▼                        ▼
   api.anthropic.com        runner → `claude -p --output-format json`
```

The three constants this dispatch needs already exist and have **zero call sites** — `APP_API_ID = 'app_api'`
(`:99`), `RUNNER_EXAPP_ID = 'hermiq-llm-runner'` (`:106`), `RUNNER_ROUTE = '/run'` (`:113`) — as does the
injected `?IAppManager $appManager` (`:193`), whose own docblock says it is "used ONLY by the `cli` execution-mode
path" (`:152`). All four are orphaned scaffolding left by `llm-cli-runner-exapp`. This change gives them their
first callers. Verified against HEAD.

### Declarative-vs-imperative decision (ADR-031)

**Not applicable, deliberately.** ADR-031's declarative default (`x-openregister-*` in
`lib/Settings/hermiq_register.json`) governs OpenRegister **object behaviour** — lifecycle/state machines,
aggregations, derived fields, notifications, declarative relations, dashboard widgets. This change touches
none of them. It is **transport plumbing**: selecting which wire an LLM turn travels over. It fires on no
object write, derives no field, and has no schema-expressible equivalent — there is no `x-openregister-*`
dialect for "POST this turn to an ExApp instead of an HTTP API".

The **input** stays declarative, which is ADR-031's spirit: `executionMode` is operator-set configuration
persisted by `LlmSettingsHandler`, not a code branch an admin must ask a developer to flip. The code reads it;
it does not hard-code the choice. No new Service class is introduced to hold business logic a schema could
declare — `callAnthropicCli()` is a private method on the class that already owns every other provider branch.

## API Design

**No new endpoints.** This change adds no route to `appinfo/routes.php`. It is a new *outbound* call to an
endpoint that already exists — the runner's `POST /run` (`exapp/llm-runner/src/server.js:160`), whose contract
`llm-cli-runner-exapp` already specced and whose implementation already ships.

The outbound request body (only the fields the runner actually reads — verified at `server.js:110`):

```json
{
  "provider": "anthropic",
  "model": "claude-opus-4-8",
  "messages": [{ "role": "user", "content": "..." }],
  "credentialEnv": { "CLAUDE_CODE_OAUTH_TOKEN": "YOUR_TOKEN_HERE" }
}
```

`tools` is deliberately **absent** — the runner never reads it (`server.js:110`, `runner.js:112`), and a turn
that needs it is refused before this point. The response (`server.js:127-131`): `{text, toolCalls, usage}`.
`toolCalls` is structurally always `[]` (`providers.js:119-122`); this change maps `text` and `usage` and
ignores `toolCalls` rather than pretending it can be populated.

### AppAPI's two defaults are traps — both MUST be overridden explicitly

Neither is visible in `exAppRequest()`'s signature or docblock; both were read from the installed AppAPI
34.0.0's source. `contract.md` pins them as the consumed contract.

**1. `timeout` defaults to 3 SECONDS.** `AppAPIService::prepareRequestToExApp()` (`:189-191`):

```php
if (!isset($options['timeout'])) { $options['timeout'] = 3; }
```

The runner gives the CLI **120 seconds** before SIGKILL (`runner.js:28` —
`Number(process.env.RUNNER_TIMEOUT_MS || '120000')`, enforced at `:154-158`). A `claude -p` turn takes far
longer than 3s, so **a dispatch that omits `timeout` fails on every single call** — while the container runs
the turn to completion and bills the user's subscription for it. The failure would look like "the runner is
broken", miles from its cause.

**Decision: pass `options['timeout']` = `RUNNER_TIMEOUT_MS + 30s` (150s).** The guard is
`if (!isset(...))`, so the caller can override it. The value mirrors the slack link 3 uses for its per-run
token TTL, keeping one number across the chain, and it MUST stay **greater** than the runner's own timeout so
the runner's kill-and-report wins the race and the user gets the real reason. A test asserts the option is
present and exceeds the runner's timeout, so it cannot silently regress to the default.

*Known limit, recorded not solved:* `RUNNER_TIMEOUT_MS` is a container env var Hermiq cannot read, so 150s is
derived from the runner's **default**. An operator who raises it gets a Hermiq-side timeout first. Making it
discoverable needs a runner capability endpoint — new surface, for a config almost nobody changes. Deferred.

**2. AppAPI never throws — failure is the RETURN VALUE.** Three shapes, all silent:

- `requestToExAppInternal()` wraps the call in `catch (\Exception $e) { return ['error' => $e->getMessage()]; }`
  (`AppAPIService.php:101-113`) — **every** transport failure, timeouts included.
- `PublicFunctions::exAppRequest()` returns `['error' => "ExApp \`%s\` not found"]` (`:36-41`).
- `$options['http_errors'] = false` (`AppAPIService.php:184`, comment: "do not throw exceptions on 4xx and 5xx
  responses") — a 502 from the runner arrives as an ordinary `IResponse`.

The intuition "it throws if it fails" is wrong here, and being wrong means **reading an error string as the
model's answer** — a fake completion delivered with total confidence. Same defect class as the tool-less run,
so it gets the same treatment. **Required check order**, each converting to `ProviderUnavailableException`:

```
1. is_array($result) && isset($result['error'])   → raise   (the catch-and-return + ExApp-not-found shapes)
2. $result->getStatusCode() not 2xx               → raise   (http_errors => false)
3. decode body; no usable 'text'                  → raise
4. otherwise                                      → map {text, usage}
```

Any other order lets a failure through as a turn, which is why the spec makes it a scenario rather than
trusting the implementation. `exAppRequestWithUserInit()` is **deprecated since AppAPI 3.0.0**
(`PublicFunctions.php:46-48`) — use `exAppRequest()`.

## Database Changes

**None.** Hermiq owns no tables (ADR-001 — thin client over OpenRegister). No OpenRegister schema is
introduced or modified: `executionMode` is `IAppConfig` state already persisted by `LlmSettingsHandler`, and
the credential is a broker reference. → `migration.md` is **skipped**.

## Nextcloud Integration

- **Controllers**: none — no new route.
- **Services**:
  - `Llm\ProviderFactory` — MOD. The stub at `:1361-1368` is replaced; a `cli` branch is added at the top of
    `callAnthropicChat()`; a new private `callAnthropicCli()` does resolve → dispatch → map.
  - `Llm\ChatDriver` — **UNCHANGED**. `public readonly string $executionMode='http'` already exists with the
    right name, type and default (`:80`). Verified against HEAD.
  - `Engine\ResponseGenerationHandler` (`:343`), `Engine\ConversationManagementHandler` (`:468`) — MOD, one
    named argument each.
- **OCP interfaces**: `OCP\App\IAppManager` — already injected (`:193`), nullable, used for the
  `isEnabledForUser()` availability check.
- **Cross-app seams**, both resolved **lazily** so Hermiq still boots when either app is absent — the pattern
  `BrokerHttpClient` already establishes (`class_exists()` + `Server::get()` behind `isAvailable()` —
  `BrokerHttpClient.php:72, 140-143, 182`):
  - `OCA\AppAPI\PublicFunctions::exAppRequest()` — verified against the installed app_api 34.0.0 at
    `/var/www/html/apps/app_api/lib/PublicFunctions.php:29-44`. Exact signature:
    `exAppRequest(string $appId, string $route, ?string $userId = null, string $method = 'POST', array $params = [], array $options = [], ?IRequest $request = null): array|IResponse`.
    It returns `['error' => "ExApp `%s` not found"]` rather than throwing when the ExApp is absent — so the
    **`error` key MUST be checked explicitly**; a naive success-path read would treat that array as a
    response.
  - `OCA\OpenRegister\Service\Credential\CredentialBrokerService::resolveInjectable()` — verified at
    `openregister/lib/Service/Credential/CredentialBrokerService.php:250-276`.
- **Mappers/Entities**: none. **Events/Hooks**: none.

## Security Considerations

### The token leaves the vault — a conscious, bounded weakening

This is the security centre of the change and must read as a deliberate decision, not an oversight.

`resolveInjectable()` hands Hermiq's PHP process the **raw** Claude Max OAuth token, which then travels to the
ExApp's environment. That **weakens the broker's central "the app never sees the secret" property**, which the
host-locked proxy providers (`anthropic`, `anthropic-oauth`) genuinely preserve — they carry a `baseUrl` and
`allowRules`, and the secret never leaves OpenRegister.

**It is forced, not chosen.** A CLI reads its credential from its **environment**; there is no request for a
proxy to sign, so there is no seam to interpose. OpenRegister makes this explicit in code:
`CredentialBrokerService::request()` **denies** an `inject_only` provider outright (`:189-191` — "an unbounded
host is exactly what must never be proxied"), and `resolveInjectable()` returns null unless `isInjectOnly()`
(`:266`). The two paths are mutually exclusive by construction.

**What still holds** (all verified in `CredentialBrokerService.php`):

| Control | Status |
|---|---|
| Guard 1 — owner/IDOR: the caller must own the credential | **Enforced** (`:261`) |
| Guard 2 — `allowedApps`: the credential must permit `hermiq` | **Enforced** (`:264`) |
| The secret lives in Doriath; config holds only a `credentialRef` | **Holds** — never store a secret in a schema |
| Runner: env only, never argv, never logged | **Holds** (`runner.js:5-8, 122-134`) |
| Runner: env-var name allowlist (no `PATH`/`LD_PRELOAD` smuggling) | **Holds** (`selectCredentialEnv()`, `runner.js:89-100`) |
| Runner: stateless between calls; token-shaped strings redacted from errors | **Holds** (`runner.js:214-218`) |
| Blast radius | One user's **personal** subscription token — not an org key |

**Hermiq must not widen the exposure further.** The resolved token is a local variable passed straight into
the dispatch payload. It MUST NOT be stored on the `ChatDriver` (that object is held by handlers — the exact
mistake `ChatDriver.php:51-57` records having already been made once with the Fireworks key, and fixed), MUST
NOT be logged, and MUST NOT appear in an exception message.

### Personal scope only — an Anthropic ToS constraint

A Claude Max/Pro OAuth token is **PERSONAL-SCOPE ONLY**. It serves only its owner and MUST be rejected at
organisation scope. This is not a Hermiq preference — it is the Anthropic ToS, and it is why the direct API
refuses the token at all (see below). The owner guard (`:261`) enforces the mechanism; this rule is the
policy. Carried forward unchanged from `anthropic-agent-provider`.

### Why the CLI, and not header-spoofing

Anthropic hard-refuses a Max subscription OAuth token on the raw Messages API: HTTP 429
`{"type":"error","error":{"type":"rate_limit_error","message":"Error"}}`, with `anthropic-organization-id`
present (so it **authenticates**) and `x-should-retry: true`, but **no** `retry-after` and **no**
`anthropic-ratelimit-*` counters — identical after 14h of zero usage. It is a **categorical refusal, not a
quota**. Hermiq already detects this exact shape and tells the operator to switch to `executionMode: cli`
(`ProviderFactory.php:970-977`) — advice that currently leads straight to a 503. Running the official CLI is
the ToS-compliant path. **Spoofing client identity is not acceptable and is not proposed.**

### Fail loudly — the fail-open trap is already in this file

`callAnthropicChat()` logs a warning and runs the turn **text-only** when tools are present without an
executor (`:624-635`). That is a fail-open: a tool-less agent never announces itself, it simply never calls a
tool. Hermiq pins `tools:['__none__']` elsewhere for exactly this reason.

The `cli` branch MUST NOT copy it. Every one of these raises `ProviderUnavailableException` (503) **before**
the ExApp is called:

1. `$functions` is non-empty — the runner structurally cannot honour tools (`server.js:110`, `runner.js:112`,
   `providers.js:119-122`). Name tools as the reason and point at link 3.
2. `$appManager` is null, AppAPI is not enabled, or the `hermiq-llm-runner` ExApp is not enabled.
3. `exAppRequest()` returns an array carrying an `error` key — both its "ExApp not found" shape
   (`PublicFunctions.php:37-39`) and its catch-and-return shape for **every** transport failure including
   timeouts (`AppAPIService.php:101-113`). AppAPI never throws; see "AppAPI's two defaults are traps".
4. `resolveInjectable()` returns null — not inject-only, wrong owner, or `hermiq` not in `allowedApps`.
5. The runner returns non-200, or a body without a usable `text`.

Every message must be static and generic to the client per ADR-005, with the real detail logged server-side.
**No message may contain the token**, and the runner's own error strings are already redacted at
`runner.js:214-218`.

### Removing the stub unmasks a latent defect

The orphaned-capability defect (`server.js:121`'s comment claims a `tools` passthrough that `server.js:110`
and `runner.js:112` do not implement) is inert **only** because the 503 stub makes the `cli` path unreachable.
This change removes the stub. Mitigation is (1) refuse tool-carrying turns, (2) delete the lying comment in
this same change so the next reader is not misled, (3) pin the raise with a unit test so a future refactor
that reintroduces degradation breaks the build.

## File Structure

```
lib/
  Service/
    Llm/
      ProviderFactory.php     MOD — replace the 503 stub (:1361-1368); pass executionMode into ChatDriver
                              (:1372-1379); `cli` branch atop callAnthropicChat() (:600); NEW private
                              callAnthropicCli(); first callers for APP_API_ID (:99), RUNNER_EXAPP_ID (:106),
                              RUNNER_ROUTE (:113), $appManager (:193)
      ChatDriver.php          UNCHANGED — $executionMode already exists (:80). Verified against HEAD.
  Service/Engine/
    ResponseGenerationHandler.php      MOD — one named arg (:343)
    ConversationManagementHandler.php  MOD — one named arg (:468)
  Controller/
    ChatStreamController.php           UNCHANGED — non-streaming degradation already contractual (:12-14, :57-59)
exapp/llm-runner/
  src/server.js               MOD — DELETE the false tools-passthrough comment (:121). Nothing else.
  src/{runner,providers,auth}.js, deploy/*   UNCHANGED
tests/
  Unit/Service/Llm/ProviderFactoryTest.php   MOD — cli dispatch, the five raise paths, no-token-leak
```

## Seed Data

**Not applicable.** This change introduces and modifies **no OpenRegister schemas** (see "Database Changes").
ADR-001's seed-data requirement applies to schemas a change introduces or modifies, of which there are none —
so there is nothing to seed and `tasks.md` carries no seed-data task. The `anthropic-cli` credential provider
is a declarative provider registration in OpenRegister's `lib/Settings/credential-providers.json`, owned by
link 1 (`cli-runner-credential-declaration`), and is not seed data either.

## Trade-offs

### The dispatch seam: inside `callAnthropicChat()` vs. a new driver class

**Chosen: a branch at the top of `callAnthropicChat()` plus a private `callAnthropicCli()`.** The alternative —
a `CliChatDriver` class behind a transport interface — is architecturally tidier but would require extracting
an abstraction across five provider paths (`openai`, `ollama`, `fireworks`, `nextcloud`, `anthropic`) that do
not currently share one. That is a refactor with a large blast radius, and ADR-032 warns precisely against
inflating an envelope. `ProviderFactory` already owns every provider branch; one more branch is consistent
with the file's existing shape. If a third Anthropic transport ever appears, extract then.

### Threading `executionMode` through the call sites vs. re-reading config

**Chosen: thread it from the `ChatDriver`.** All three anthropic call sites
(`ProviderFactory::generateText():1106-1114`, `ResponseGenerationHandler.php:343`,
`ConversationManagementHandler.php:468`) already pass `credentialId`/`model`/`baseUrl`/`authMode` from the
driver and simply **drop** `executionMode`. Adding one named argument each keeps the driver as the single
resolved source of transport truth. Re-reading `LlmSettingsHandler` inside `callAnthropicChat()` would let the
driver and the transport disagree if config changed mid-run — a race with no upside.

### Non-streaming: degrade within the SSE contract vs. fake token events

**Chosen: degrade.** `claude -p` is non-streaming — one JSON object at the end. The six-event envelope
**already** contracts for this: "non-streaming providers degrade to zero `token` events plus one `final`
carrying the full text" (`ChatStreamController.php:12-14`), and the synchronous fallback is already
implemented (`:57-59`). So `cli` mode needs **no** SSE change and clients never branch. The alternative —
chopping the completion into synthetic `token` events to fake streaming — would misrepresent the transport for
cosmetic gain. Rejected. Verified against HEAD.

### Availability: fail closed at 503 vs. silently falling back to `http`

**Chosen: fail closed.** An operator who selects `cli` has usually done so because the direct API **refuses**
their subscription token (`:970-977`); silently serving `http` would either fail confusingly downstream or, if
they also hold an API key, spend money on a path they did not choose. It would also violate the
`llm-cli-runner-exapp` requirement "cli mode without the ExApp fails clearly". A clear 503 naming the missing
component is the honest outcome.

### Scope: text-only now vs. waiting for tools

**Chosen: ship text-only.** A text-only `cli` turn is the difference between a Claude Max subscriber having
working chat and having a 503 — genuinely useful alone, which is what makes this a legitimate chain link
rather than an arbitrary slice. Tools cannot ship here regardless: `claude -p` cannot accept a tool schema
(`--tools` selects from the **built-in** set; `--allowedTools` filters **names**; custom tools reach the CLI
**only via MCP**), so tool support requires link 3's governed MCP endpoint. Refusing tool-carrying turns is
therefore not a temporary shortcut — it is the correct behaviour for this transport until link 3 exists.

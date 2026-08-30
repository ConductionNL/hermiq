# Discovery: cli-runner-text-turn-dispatch

## Question

`ProviderFactory` declares `APP_API_ID`, `RUNNER_EXAPP_ID` and `RUNNER_ROUTE` and injects an `IAppManager`
"used ONLY by the `cli` execution-mode path" — and then throws 503 instead of dispatching, calling the AppAPI
dispatch "a tracked follow-up" (`lib/Service/Llm/ProviderFactory.php:99-113, 152, 193, 1357-1368`). So:
**what is the actual PHP seam for calling an ExApp route from a Nextcloud app, what is its real contract, and
does the text-only turn work end to end without any tool support?**

## Approach Taken

- Read the 503 stub and its surroundings: `ProviderFactory::createAnthropicDriver()` (`:1330-1382`),
  `callAnthropicChat()` (`:599-683`), `generateText()` (`:1106-1114`).
- Grepped every `executionMode` mention across `lib/`, `src/`, `tests/` to find the field's consumers.
- Read the runner end to end: `exapp/llm-runner/src/{server.js,runner.js,providers.js,auth.js}` and
  `test/test.sh`.
- Confirmed AppAPI is live on the dev instance: `occ app:list` → `app_api: 34.0.0`.
- Read AppAPI's own source **in the running container** (`/var/www/html/apps/app_api/`), not from memory:
  `lib/PublicFunctions.php`, `lib/Service/AppAPIService.php`.
- Read OpenRegister's `CredentialBrokerService::resolveInjectable()` (`:250-290`) and grepped Hermiq for
  existing callers.
- Read `lib/Controller/ChatStreamController.php` for the six-event SSE envelope's non-streaming contract.
- Checked whether `llm-cli-runner-exapp` has a canonical archived spec (`ls openspec/specs/`).

## Findings

### 1. The AppAPI seam is `OCA\AppAPI\PublicFunctions` — verified signature

`/var/www/html/apps/app_api/lib/PublicFunctions.php:29-41`:

```php
public function exAppRequest(
    string $appId, string $route, ?string $userId = null, string $method = 'POST',
    array $params = [], array $options = [], ?IRequest $request = null,
): array|IResponse
```

`exAppRequestWithUserInit()` is **deprecated since AppAPI 3.0.0** ("use `exAppRequest` instead") — do not use
it. For a `POST` with a non-empty `$params`, AppAPI JSON-encodes it as the request body
(`AppAPIService.php:193-198`: `$options['json'] = $params`), so the assembled turn goes in `$params`.

### 2. 🔥 AppAPI's default timeout is **3 seconds**. A `claude -p` turn takes far longer

`AppAPIService::prepareRequestToExApp()` (`:189-191`):

```php
if (!isset($options['timeout'])) {
    $options['timeout'] = 3;
}
```

The runner gives the CLI **120 seconds** before SIGKILL (`runner.js:28` —
`Number(process.env.RUNNER_TIMEOUT_MS || '120000')`, enforced at `:154-158`).

**A dispatch that omits `timeout` therefore fails on every single call** — while the container happily runs
the turn to completion and bills the user's subscription for it. This is the highest-value finding here: it
is invisible in the signature, invisible in the docblock, and would have looked like "the runner is broken".
The guard is `if (!isset(...))`, so the caller **can** override it. Hermiq MUST pass an explicit
`options['timeout']`.

### 3. 🔥 AppAPI never throws on failure — it returns an error ARRAY, and 4xx/5xx look normal

Two independent silent traps:

- `requestToExAppInternal()` (`AppAPIService.php:101-113`) wraps the HTTP call in
  `try { ... } catch (\Exception $e) { return ['error' => $e->getMessage()]; }`. **Every** transport failure —
  timeout included — comes back as an ordinary array, not an exception.
- `prepareRequestToExApp()` sets `$options['http_errors'] = false` (`:184`, comment: "do not throw exceptions
  on 4xx and 5xx responses"). A 502 from the runner arrives as a perfectly ordinary `IResponse`.

`PublicFunctions::exAppRequest()` adds a third: `['error' => sprintf('ExApp \`%s\` not found', $appId)]` when
the ExApp is not registered (`:36-41`).

A caller written to the intuition "it throws if it fails" would read an error message as a model completion.
The check order must be: `is_array($result)` → `error` key; then `getStatusCode()` is 2xx; then decode.

### 4. The `executionMode` field is orphaned, and the call sites drop it

`ChatDriver::$executionMode` exists with the right name, type and default (`ChatDriver.php:80`,
`public readonly string $executionMode='http'`). Nothing reads it. Worse, nothing could:

- `createAnthropicDriver()` never passes it to the `ChatDriver` constructor (`:1372-1379`) — it throws first.
- All three anthropic call sites pass `credentialId`/`model`/`baseUrl`/`authMode` and **drop `executionMode`**:
  `ProviderFactory::generateText():1106-1114`, `ResponseGenerationHandler.php:343-350`,
  `ConversationManagementHandler.php:467-474`.

So threading the field is part of the work, not a given. The three orphaned constants (`APP_API_ID`,
`RUNNER_EXAPP_ID`, `RUNNER_ROUTE`) and the injected `IAppManager` have **zero call sites** anywhere
(`grep -rn` over `lib/` and `tests/`).

### 5. `callAnthropicChat()` is the only place that can make the fail-loud decision

The tools-vs-text decision needs `$functions`. Only `callAnthropicChat()` receives it (`:599-608`); the
driver factory never sees it. Putting the `cli` branch anywhere else would require a second seam to re-check
tools — and a second seam is exactly how the two halves drift apart, which is the defect this chain exists to
correct.

`callAnthropicChat()` also contains the **fail-open pattern to not copy** (`:624-635`): tools present + no
executor → `logger->warning(... 'running text-only')` → the turn proceeds. Silent degradation, in the very
function the new branch goes into.

### 6. The runner already does everything the text path needs — and lies about the rest

Confirmed for the text path:
- `providers.js:124-135` — anthropic adapter: `bin: 'claude'`, `args: ['-p','--output-format','json']`
  (+ `['--model', model]`), `credentialKeys: ['CLAUDE_CODE_OAUTH_TOKEN','ANTHROPIC_API_KEY']`.
- `runner.js:89-100` `selectCredentialEnv()` allowlists env keys — the caller cannot smuggle
  `PATH`/`LD_PRELOAD`. `:122-134` builds the child env; `:5-8` states env-only/never-argv/never-logged.
- `auth.js:70-90` — `EX-APP-ID` + `AUTHORIZATION-APP-API` + HMAC-SHA256 `AA-SIGNATURE` over the raw body, and
  it **fails closed** on an unconfigured secret (`:72-75`).
- `redact()` (`runner.js:214-218`) strips `sk-`/`xai-`/`oat[_-]`-shaped strings from surfaced errors.

Confirmed **absent**, contradicting the comment at `server.js:121`:
- `server.js:110` — `const { provider: providerId, model, messages, credentialEnv } = payload;` — no `tools`.
- `runner.js:112` — `function run({ provider, model, messages, credentialEnv })` — no `tools`.
- ⇒ `providers.js pickToolCalls()` (`:119-122`) can only ever return `[]`.

The runner needs **no change** for a text-only turn. The only edit it needs is deleting the false comment.

### 7. The SSE envelope needs no change — the CLI's shape is already contractual

`ChatStreamController.php:12-14` and `:57-59`: "non-streaming providers degrade to zero `token` events plus
one `final` carrying the full text — both shapes are part of the contract, so clients never branch." `claude
-p --output-format json` returns one complete result, which is exactly that shape. No SSE work.

### 8. `resolveInjectable()` has zero Hermiq callers today

`grep -rn resolveInjectable lib/ tests/` → nothing. This link is Hermiq's first. Verified behaviour
(`openregister/.../CredentialBrokerService.php:250-290`): Guard 1 owner/IDOR (`:261`), Guard 2 `allowedApps`
(`:264`), then **`return null` unless `isInjectOnly()`** (`:266-269`) — the signal to route to `request()`
instead. So without link 1's `anthropic-cli` inject-only provider, this returns null and the dispatch fails
closed on its own. The chain order is load-bearing, not bureaucratic.

The lazy-resolution pattern to copy is `BrokerHttpClient`: a `BROKER_CLASS` string constant (`:72`),
`class_exists()` behind `isAvailable()` (`:140-143`), `Server::get()` at the call site (`:182`). Hermiq must
not hard-reference an `OCA\AppAPI\*` class either.

### 9. `llm-cli-runner-exapp` is NOT archived — it cannot be a MODIFIED target

`ls openspec/specs/` has no `llm-cli-runner-exapp`. The change dir has `proposal.md`, `tasks.md` and
`specs/llm-cli-runner-exapp/spec.md`, but a delta can only MODIFY a **canonical** spec. Note link 3's
design.md plans to MOD that change-dir file directly — that is link 3's problem to resolve, not this link's.
This change adds a **new** capability instead.

## Recommendation

**Proceed**, with the text-only scope exactly as proposed. The dispatch is a single branch at the top of
`callAnthropicChat()`, and the runner is already correct for text. Three findings are binding on the design:

1. **Pass an explicit `options['timeout']`** of `RUNNER_TIMEOUT_MS + 30s` (150s). Without it the feature is
   100% broken at 3s and the failure looks like the runner's fault.
2. **Never treat an AppAPI return as a success.** Check `error` key → status code → then decode. AppAPI
   signals every failure by return value.
3. **Fail loudly on tools inside `callAnthropicChat()`**, before dispatch — the only place `$functions` is in
   scope, and the same function that currently degrades silently. Do not copy `:624-635`.

## Risks Uncovered

- **A future AppAPI could change the default timeout, or `PublicFunctions`' contract.** Hermiq pins the
  timeout explicitly rather than relying on a default, so an AppAPI change cannot silently shorten a turn.
  `PublicFunctions` is AppAPI's *public* seam (that is its name and purpose), so it is the right thing to
  depend on; the `AppAPIService` internals read here are evidence, not an API to call.
- **`RUNNER_TIMEOUT_MS` is a container env var Hermiq cannot read.** Hermiq's 150s is derived from the
  runner's *default*. An operator who raises the runner's timeout for long turns gets a Hermiq-side timeout
  first. Recorded as an open question rather than solved speculatively.
- **The `usage` shape from `claude -p` is not pinned by a Hermiq test.** `parseClaudeJson()` parses it, but
  Hermiq maps it into `lastUsage`. If the CLI changes its `usage` keys, the mapping degrades quietly. Bounded:
  the `http` anthropic branch already records latency only (`ResponseGenerationHandler.php:354-357`), so the
  `cli` path is no blinder than the path it mirrors.

## Next Steps

Proceed to specs and design. No further discovery needed — the remaining unknowns (the runner's timeout being
undiscoverable) are operational, not feasibility questions, and are recorded as open questions on the
proposal. Tool transport stays entirely out of this link; link 3's discovery already established that
`claude -p` cannot accept a tool schema at all.

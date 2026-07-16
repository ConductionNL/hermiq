# Contract: cli-runner-text-turn-dispatch

This change **produces no new interface**. It is a pure *consumer* of three existing seams, and every one of
them has a trap that is invisible from its signature. This document pins the shapes and the failure modes.

It exists because the last change in this area shipped a contract its code did not honour — `server.js:121`
still claims "`tools` is accepted and passed through" while `server.js:110` destructures no `tools`. **Every
shape below was read from HEAD or from the installed AppAPI 34.0.0, not from a docblock.** Where a docblock
and the code disagree, the code is recorded.

## Consumers

| Direction | Party | Relationship |
|---|---|---|
| Hermiq → OpenRegister | `CredentialBrokerService::resolveInjectable()` | Hermiq is a **new** consumer — zero callers today |
| Hermiq → AppAPI | `OCA\AppAPI\PublicFunctions::exAppRequest()` | Hermiq is a **new** consumer — the constants exist, the calls do not |
| Hermiq → `hermiq-llm-runner` | `POST /run` | Hermiq is the **only** caller; the runner is unchanged by this link |

No other app consumes anything this change produces. Hermiq produces nothing here.

## Seam 1 — OpenRegister `CredentialBrokerService::resolveInjectable()`

**Verified:** `openregister/lib/Service/Credential/CredentialBrokerService.php:250-290`.

```php
public function resolveInjectable(
    string $credentialId,
    string $appId,
    ?string $actingUserId = null
): ?string
```

| Aspect | Contract |
|---|---|
| Hermiq passes | `$credentialId` from provider config; `$appId = 'hermiq'`; `$actingUserId` = the credential owner's UID |
| Returns | The secret, **or `null`** |
| **`null` means** | The provider is **not** `inject_only` — route to `request()` instead (`:266-269`). It does **not** mean "no access" |
| Throws | `CredentialAccessDeniedException` when Guard 1 (owner/IDOR, `:261`) or Guard 2 (`allowedApps`, `:264`) fails, or an inject-only credential has no stored secret |

**Trap:** `null` is a *routing* signal, not an error. But for **this** change `null` is fatal — a `cli` turn
has no `request()` fallback (the whole point is that a CLI needs the token in its environment). Hermiq MUST
convert `null` into `ProviderUnavailableException`, **not** silently fall back to the HTTP path with a token
Anthropic will refuse anyway.

**Precondition owned by link 1:** `resolveInjectable()` returns non-null only if the provider is
`inject_only`. Until `cli-runner-credential-declaration` declares `anthropic-cli` with `inject_only: true`,
this returns `null` for every credential and the dispatch fails closed on its own. The chain order is
load-bearing.

## Seam 2 — AppAPI `PublicFunctions::exAppRequest()`

**Verified:** `/var/www/html/apps/app_api/lib/PublicFunctions.php:29-41` on the installed AppAPI **34.0.0**.

```php
public function exAppRequest(
    string $appId, string $route, ?string $userId = null, string $method = 'POST',
    array $params = [], array $options = [], ?IRequest $request = null,
): array|IResponse
```

Hermiq calls it with `appId: 'hermiq-llm-runner'` (`RUNNER_EXAPP_ID`), `route: '/run'` (`RUNNER_ROUTE`),
`method: 'POST'`, `params: <the turn payload>`, `options: ['timeout' => 150]`.

### Behaviour Hermiq must code against — all verified, none in the signature

| # | Fact | Source | Consequence |
|---|---|---|---|
| 1 | **`timeout` defaults to 3 seconds** | `AppAPIService.php:189-191` | The runner allows the CLI **120s** (`runner.js:28`). Omitting `timeout` breaks **every** turn while the container bills it. Hermiq MUST pass it. It is overridable — the guard is `if (!isset($options['timeout']))` |
| 2 | **Never throws on transport failure** | `AppAPIService.php:101-113` | `catch (\Exception $e) { return ['error' => $e->getMessage()]; }` — timeouts included. Check the return, not a `try` |
| 3 | **Missing ExApp → array, not exception** | `PublicFunctions.php:36-41` | `['error' => "ExApp `%s` not found"]` |
| 4 | **`http_errors => false`** | `AppAPIService.php:184` | A 4xx/5xx from the runner is an ordinary `IResponse`. Status MUST be checked before the body is read |
| 5 | **`$params` becomes the JSON body on POST** | `AppAPIService.php:193-198` | `$options['json'] = $params`. The turn goes in `$params`, not in `$options` |
| 6 | **`exAppRequestWithUserInit()` is deprecated** | `PublicFunctions.php:46-48` | "since AppAPI 3.0.0, use `exAppRequest` instead". Do not use |

### Required check order

```
$result = $publicFunctions->exAppRequest(...);

1. is_array($result) && isset($result['error'])   → ProviderUnavailableException (facts 2, 3)
2. $result->getStatusCode() not 2xx               → ProviderUnavailableException (fact 4)
3. decode body; no 'text' key                     → ProviderUnavailableException
4. otherwise                                      → map {text, usage}
```

**Any other order reads an error string as the model's answer.** Fact 2 makes that the *default* outcome for a
naive caller, which is why it is a spec scenario and not just a code comment.

## Seam 3 — `hermiq-llm-runner` `POST /run`

**Verified:** `exapp/llm-runner/src/{server.js,runner.js,providers.js,auth.js}` at HEAD. **Unchanged by this
link** — the only edit is deleting a comment.

### Authentication (Hermiq → runner)

AppAPI signs it; Hermiq adds nothing. Verified `auth.js:70-90`: `EX-APP-ID` + `AUTHORIZATION-APP-API` + an
HMAC-SHA256 `AA-SIGNATURE` over the raw body; **fails closed** on an unconfigured `APP_SECRET` (`:72-75`);
401 for missing headers, 403 for a present-but-invalid one. Verified before any CLI runs (`server.js:95-99`).

**There is no runner → Hermiq direction in this link**, so no second credential exists or is needed. That
direction, and its per-run token, arrive in link 3.

### Request

```jsonc
{
  "provider": "anthropic",
  "model": "claude-opus-4-8",
  "messages": [ { "role": "system|user|assistant", "content": "..." } ],
  "credentialEnv": { "CLAUDE_CODE_OAUTH_TOKEN": "YOUR_TOKEN_HERE" }
}
```

| Field | Contract | Source |
|---|---|---|
| `provider` | Must resolve via `getProvider()`; unknown → **400** | `server.js:111-115` |
| `messages` | **Non-empty array** or **400** | `server.js:117-120` |
| `model` | Optional; empty → the CLI's default (no `--model`) | `providers.js:127-130` |
| `credentialEnv` | Map. Only keys the adapter allowlists survive; everything else is **dropped silently** | `runner.js:89-100` |
| **`tools`** | ⛔ **NOT A FIELD.** Not destructured (`server.js:110`), not a `run()` parameter (`runner.js:112`) | verified |

**`credentialEnv` allowlist for `anthropic`:** `['CLAUDE_CODE_OAUTH_TOKEN', 'ANTHROPIC_API_KEY']`
(`providers.js:132`). Hermiq sends `CLAUDE_CODE_OAUTH_TOKEN` for a Max/Pro subscription. A key outside the
allowlist is dropped **without an error** — so a typo yields an unauthenticated CLI, not a 400. The env-name
allowlist is a security control (the caller cannot smuggle `PATH`/`LD_PRELOAD`), and its silence is the
correct trade; Hermiq's side is to send the right key.

### Response — 200

```jsonc
{ "text": "...", "toolCalls": [], "usage": { } }
```

| Field | Contract |
|---|---|
| `text` | The completion. From `claude -p --output-format json` → `{type:"result", result:"<text>"}` via `parseClaudeJson()` |
| `toolCalls` | ⛔ **ALWAYS `[]`.** `pickToolCalls()` (`providers.js:119-122`) reads a key nothing populates, because `run()` has no `tools`. Hermiq MUST NOT treat a non-empty value as reachable, and MUST NOT build behaviour on it |
| `usage` | The CLI's `usage` object, passed through |

### Error codes

| Code | Condition | Source |
|---|---|---|
| 400 | Invalid JSON body | `server.js:104-107` |
| 400 | Unknown `provider` | `server.js:112-115` |
| 400 | `messages` missing or empty | `server.js:117-120` |
| 401 | `APP_SECRET` unconfigured, or AppAPI headers missing | `auth.js:72-75, 78-80` |
| 403 | `EX-APP-ID` mismatch or `AA-SIGNATURE` failure | `auth.js:81-86` |
| 502 | CLI execution failed — `{error, detail}`, `detail` already redacted | `server.js:131-135`, `runner.js:214-218` |

Reminder from fact 4: **none of these throw on Hermiq's side.** They arrive as an ordinary `IResponse`.

## The contract this change deliberately does NOT honour

`llm-cli-runner-exapp` requires Hermiq to dispatch a **tool schema** to `/run` and receive tool-call requests
back. **That contract is unimplementable and is not honoured here** — `claude -p` accepts no tool schema, and
the runner carries no `tools` field.

This change's response is to **refuse** such a turn (`ProviderUnavailableException` naming tools), not to
dispatch it and hope. Correcting that spec's language, and building the governed MCP transport that replaces
it, is link 3's job.

**This is the contract-level statement of the defect this chain exists to correct:** a documented interface
that the implementation silently does not provide is worse than a missing one, because everything looks green.

## Versioning

No version negotiation exists on any seam. Compatibility is pinned by observation, not by a version header:

- **AppAPI 34.0.0** — installed and verified. `PublicFunctions` is the supported public seam; `AppAPIService`
  internals were read as evidence and are **not** called. The 3s default is defended against by passing
  `timeout` explicitly rather than relying on the default, so an AppAPI change cannot silently shorten a turn.
- **The runner** — versioned with Hermiq, in the same repo. No skew possible.
- **`claude` CLI** — the runner's `parse` owns the output shape; Hermiq only reads `{text, usage}` from the
  runner's response, never the CLI's raw output. A CLI output change is the runner's problem, not Hermiq's.

## Breaking Change Policy

Hermiq publishes no interface here, so it can break no consumer. If a seam breaks Hermiq:

- **AppAPI removes/changes `PublicFunctions::exAppRequest()`** — the lazy `class_exists()` resolution
  (`BrokerHttpClient` pattern) degrades `cli` to a clear 503; `http` is unaffected and Hermiq still boots.
- **The broker changes `resolveInjectable()`** — cross-repo, coordinated in `apps-extra`; link 1 owns the
  provider entry.
- **The runner's `/run` shape changes** — same repo, same PR.

## SLA

- **Turn latency** is the CLI's, not Hermiq's — seconds to minutes. Hermiq waits up to **150s**
  (`RUNNER_TIMEOUT_MS` 120s + 30s slack); the runner SIGKILLs at 120s (`runner.js:154-158`). Hermiq's timeout
  MUST stay **greater** than the runner's, so the runner's own kill-and-report wins the race and the user gets
  the real reason instead of a generic timeout.
- **Availability** — `cli` mode is only as available as AppAPI + the ExApp container. Both are checked before
  every dispatch and produce a 503 naming the missing component. `executionMode: http` has no dependency on
  either.

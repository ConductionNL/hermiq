# Tasks: nc-native-tools

## 1. HermiqToolProvider

- [x] 1.1 Create `lib/Mcp/HermiqToolProvider.php` (class `HermiqToolProvider` — named to match OR's FQCN discovery convention) implementing `IMcpToolProvider` (extend `AbstractToolHandler`): `getAppId()` = `hermiq`; `getTools()` returns descriptors for `listFiles`, `readFile`, `searchContacts`, `listCalendarEvents`, `sendMail`, `listDeckBoards` (each id `hermiq.*`, with an `inputSchema`).
- [x] 1.2 Files: `listFiles {path?}` + `readFile {path}` via `IRootFolder::getUserFolder(uid)` — scoped to the acting user's folder (IDOR by construction; a `readFile` caps size + refuses non-files); a path escaping the user folder is refused.
- [x] 1.3 Contacts: `searchContacts {query}` via Contacts `IManager::search`, over the acting user's addressbooks only.
- [x] 1.4 Calendar: `listCalendarEvents {days?}` via Calendar `IManager` — the acting user's calendars only.
- [x] 1.5 Email: `sendMail {to, subject, body}` via `IMailer` with `From` = the acting user's email; require an authenticated user.
- [x] 1.6 Deck: `listDeckBoards` via Deck's `BoardService` resolved lazily through the container; a structured error when Deck is not installed (no hard class dependency).
- [x] 1.7 Every tool authorises (scopes to the acting user) BEFORE any data access; `invokeTool()` NEVER throws — unknown tool + every failure returns `['error' => ['code', 'message']]`.

## 2. Registration

- [x] 2.1 Register `HermiqToolProvider` under `OCA\OpenRegister\Mcp\IMcpToolProvider::hermiq` in `lib/AppInfo/Application.php` (replacing `ExampleToolProvider`); keep the include-once vendor autoload.

## 3. Remote boundary

- [x] 3.1 No direct third-party HTTP client in any provider; remote/non-Nextcloud calls route through OpenConnector `CallService` (documented — not required for the NC-native tools here).

## 4. Verify

- [x] 4.1 Unit-test `HermiqToolProvider` the CI way: `getTools()` lists all six `hermiq.*` descriptors; `invokeTool()` returns a structured error (never throws) for an unknown tool and when unauthenticated. Add OCP stubs as needed.
- [x] 4.2 Verified live on NC34 + OR (hermiq 0.1.25): all six `hermiq.*` tools register + enumerate in OpenRegister's tool registry (`/api/agents/tools`). Via OR's MCP JSON-RPC endpoint (`initialize` → `tools/call`), `hermiq.listFiles` returned the acting user's own files and `hermiq.searchContacts` returned the acting user's contacts; a path-traversal `listFiles {path:"../../../../etc"}` was refused with `not_found` and NO `/etc` leak (IDOR guard holds). **Discovery gotcha:** OR resolves a per-app provider by the FQCN `OCA\{Namespace}\Mcp\{Namespace}ToolProvider` (the service alias is not visible from OR's container), so the class MUST be named `HermiqToolProvider`. **Blocker (OR#269, narrowed):** the MCP `tools/call` invocation path WORKS; only the Ollama LLM emitting a tool-call (400) is blocked — so the LLM-decides-to-call step is blocked upstream, not the tool infrastructure.

## Acceptance criteria

- Files, Contacts, Calendar, Deck, and email are registered as `IMcpToolProvider` tools under the `hermiq` MCP alias and appear in OR's tool registry.
- Every provider enforces a per-object IDOR guard (scope to the acting user) before returning/mutating data.
- No direct third-party HTTP exists in provider code; remote calls route via OpenConnector `CallService`.
- `invokeTool()` never throws; failures return a structured error envelope.
- The agent-invokes-tool path is documented as blocked on OR#269 (not a passing end-to-end verification).

## Quality reminders

- SPDX in the docblock; pass `composer phpcs` (lib scope) + PHPStan; run PHPUnit the CI way.
- Authorise BEFORE business logic inside `invokeTool()`; never impersonate/elevate (the runtime passes the user session unchanged).
- No sed/awk/scripts on code — Edit tool only; `@spec` docblock tags; keep the Deck dependency lazy (no hard class import).

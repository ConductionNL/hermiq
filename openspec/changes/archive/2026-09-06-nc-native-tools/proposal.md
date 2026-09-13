---
kind: code
---

# Proposal: nc-native-tools

# Why

The `nc-native-tools` capability spec (V1, status: idea) exposes Nextcloud-native
capabilities — Files, Contacts, Calendar, Deck, and outbound email — as agent tools
through OpenRegister's MCP `IMcpToolProvider` interface, so Hermiq agents can act inside
the host Nextcloud without a second tool-registration mechanism. Anything outside
Nextcloud routes through OpenConnector's `CallService`, not a bespoke integration layer.

This change replaces the template's `ExampleToolProvider` with a real
`HermiqToolProvider` that registers Files/Contacts/Calendar/Deck/email tools under the
existing per-app MCP alias (`OCA\OpenRegister\Mcp\IMcpToolProvider::hermiq`), each
enforcing a per-object IDOR guard by scoping every call to the acting user's own
resources.

**Known blocker (OR#269):** an OpenRegister agent turn cannot yet *invoke* a tool —
Ollama tool-calling returns HTTP 400 (LLPhant/model template issue, filed as OR#269). So
the agent-actually-calls-the-tool path cannot be live-verified end-to-end until OR#269 is
fixed. This change delivers + verifies the Hermiq-owned surface — the providers register
and appear in OR's tool registry, and each tool's IDOR-guarded logic is directly
invocable/verifiable — and documents the run-loop invocation as blocked.

# What Changes

- Add `lib/Mcp/HermiqToolProvider.php` implementing `IMcpToolProvider`, exposing:
  `hermiq.listFiles`, `hermiq.readFile` (via `IRootFolder`, scoped to the user's folder);
  `hermiq.searchContacts` (via Contacts `IManager`); `hermiq.listCalendarEvents` (via
  Calendar `IManager`); `hermiq.sendMail` (via `IMailer`, From = the acting user);
  `hermiq.listDeckBoards` (via Deck's `BoardService`, resolved lazily; a structured error
  when Deck is not installed). Every tool authorises **before** any data access by scoping
  to `$userSession->getUser()->getUID()`, so an agent acting for one user cannot reach
  another user's objects. `invokeTool()` never throws — every failure returns
  `['error' => ['code', 'message']]`.
- Register `HermiqToolProvider` under the MCP alias in `lib/AppInfo/Application.php`
  (replacing `ExampleToolProvider`).
- No direct third-party HTTP: remote systems are explicitly out of scope here and route
  through OpenConnector `CallService` (documented; not needed for NC-native tools).

# Impact

- Affected specs: `nc-native-tools` (idea → active; agent-invocation blocked on OR#269).
- Affected code: `lib/Mcp/HermiqToolProvider.php`, `lib/AppInfo/Application.php`,
  `tests/Unit/Mcp/HermiqToolProviderTest.php`.
- Blocked seam (OR#269): the agent run loop invoking a registered tool. Verified: the
  providers register + enumerate in OR's tool registry; each IDOR-guarded tool is directly
  invocable.

# Design: nc-native-tools

## Context

Exposes NC-native capabilities as agent tools through OpenRegister's MCP
`IMcpToolProvider` (ADR-001 Option C+: Hermiq delegates the tool registry + agent core to
OR, connectors to OpenConnector). OR discovers one provider per app under the service
alias `OCA\OpenRegister\Mcp\IMcpToolProvider::{appId}`; the template already registered a
placeholder `ExampleToolProvider`. This change replaces it with a real
`HermiqToolProvider` aggregating all NC-native tools under `hermiq.*` ids.

## Decisions

**One aggregating provider, tools namespaced `hermiq.*`.** OR keys providers by app id, so
all NC-native tools live in a single `HermiqToolProvider` (Files, Contacts, Calendar,
Deck, email). Each descriptor id starts with `hermiq.` (OR drops mismatched ids).

**IDOR by construction — scope to the acting user.** The runtime passes the current user's
session unchanged (no impersonation). Every tool resolves the acting user via
`$userSession->getUser()->getUID()` and operates ONLY on that user's own resources:
`IRootFolder::getUserFolder(uid)` for files, the user's addressbooks for contacts, the
user's calendars for events, the user's own email as the mail From. Because the entry
point is the user's own folder/addressbooks/calendars, a call can never reach another
user's objects. `readFile` additionally refuses a non-file node and caps the returned size.

**Deck stays a lazy dependency.** Deck's `BoardService` is not an OCP class; importing it
would fatal when Deck is absent. `listDeckBoards` resolves it through the container inside
a try/catch and returns a structured error (`deck_unavailable`) when Deck is not
installed — no hard class import.

**`invokeTool()` never throws.** Every path (unknown tool, unauthenticated, missing arg,
backend failure) returns `['error' => ['code', 'message']]` so the companion surfaces a
clean message. Authorisation runs BEFORE any data access.

**Remote systems route through OpenConnector.** No provider opens a direct HTTP client;
NC-native tools need none. A future remote tool delegates to OpenConnector `CallService`
(documented, not implemented here).

## The OR#269 blocker (agent invocation)

The end-to-end path — an OpenRegister agent turn selecting and *invoking* a `hermiq.*`
tool — is blocked: OR's Ollama tool-calling returns HTTP 400 (LLPhant/model chat-template
issue, filed as OR#269; a no-tools agent runs fine). This change therefore verifies:
1. the provider **registers** and its tools **enumerate** in OR's `McpToolsService` tool
   list (the registry surface), and
2. each tool's IDOR-guarded logic is **directly invocable** (unit + a direct call),
but NOT the LLM-selects-and-calls path, which is documented as blocked on OR#269.

## Risks / Trade-offs

- **Cannot end-to-end verify agent invocation.** [OR#269] → Verify registration +
  enumeration + direct invocation; document the LLM-invocation path as blocked. Re-verify
  when OR#269 lands.
- **Deck coupling.** [BoardService is not OCP] → lazy container resolution + structured
  error; no fatal when Deck is absent.
- **Calendar/Contacts API breadth.** [OCP calendar/contacts search is coarse] → expose a
  read-only search/list slice now; richer CRUD is a follow-up.

## Open Questions

- **Blocked — agent invocation.** Depends on OR#269 (Ollama tools 400). Re-verify the full
  agent-calls-tool loop once fixed.

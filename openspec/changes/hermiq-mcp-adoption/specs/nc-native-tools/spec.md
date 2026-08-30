# nc-native-tools Specification (delta)

**Status**: in-progress
**Scope**: hermiq
**OpenSpec changes**:
- `hermiq-mcp-adoption` — migrates the NC-native tools from a hand-written `IMcpToolProvider` to `#[McpTool]`-annotated service methods with honest hints (kind: code)

## Purpose

The NC-native capabilities (Files, Contacts, Calendar, Deck, outbound email) remain agent tools and
remain IDOR-guarded; only their **registration mechanism** and their **declared hints** change, per
ADR-063. The hand-written `HermiqToolProvider` is deleted; the capabilities move to services.

## MODIFIED Requirements

### Requirement: NC-native capabilities registered as IMcpToolProvider tools
The system MUST expose Files (`IRootFolder`), Contacts (addressbook `IManager`), Calendar (`ICalendarManager`), Deck, and outbound email (`IMailer`) as tools by annotating the owning service methods with OpenRegister's `#[McpTool]` attribute and opting the services in via an `IMcpScannableServices` implementation registered under the `IMcpScannableServices::hermiq` alias — so they appear in the same tool registry as OR's other MCP tools, without a hand-written `IMcpToolProvider`.

Tool ids MUST remain `hermiq.{toolName}` (`AttributeToolScanner` builds `id = {appId}.{name}`), so
`hermiq.listFiles`, `hermiq.readFile`, `hermiq.searchContacts`, `hermiq.listCalendarEvents`,
`hermiq.sendMail`, `hermiq.listDeckBoards` and `hermiq.searchTools` are unchanged from a caller's
point of view. `lib/Mcp/HermiqToolProvider.php` MUST be deleted once it holds no tools, and its
`IMcpToolProvider::hermiq` alias MUST be removed — an empty provider would still be discovered and
would leave a misleading seam.

*Previously: the capabilities were registered by hand-writing `TOOL_DESCRIPTORS` in
`HermiqToolProvider implements IMcpToolProvider`.*

#### Scenario: An agent lists available tools including NC-native ones
- GIVEN the NC-native services are annotated with `#[McpTool]` and opted in via `IMcpScannableServices::hermiq`
- WHEN an agent run queries available tools via MCP
- THEN the Files, Contacts, Calendar, Deck, and email tools MUST appear in the returned tool list
- AND they MUST be indistinguishable in registration mechanism from OR's other attribute-derived MCP tools
- AND no `IMcpToolProvider` implementation MUST remain in the `hermiq` namespace

#### Scenario: The progressive-disclosure meta-tool keeps its exact id
- GIVEN `ToolSearchService::search()` is annotated `#[McpTool(name: 'searchTools', ...)]`
- WHEN the attribute scanner builds the descriptor
- THEN the emitted tool id MUST be exactly `hermiq.searchTools`
- AND `Engine\FacadeToolInvoker::__call()` MUST still short-circuit that id before the facade, so progressive disclosure keeps working

### Requirement: Per-object IDOR guard on every provider
Every NC-native tool MUST enforce a per-object authorization guard before acting on a specific Files/Contacts/Calendar/Deck object, so an agent acting on behalf of one user cannot access another user's objects by ID.

The guards MUST move unchanged when the tool bodies relocate from the provider into
`NcNativeToolService`: file access stays scoped to `getUserFolder($uid)`, contact and calendar access
stay scoped to the acting user's own books and principal, and `sendMail` MUST set `From:` to the
acting user's own address. Relocating logic MUST NOT relax scoping.

*Previously: the guards were specified on "every NC-native tool provider"; there is no longer a
provider, so they are specified on the tools themselves.*

#### Scenario: An agent tool call targets an object outside the caller's access
- GIVEN an agent is running on behalf of user U
- WHEN a tool call requests a Files/Contacts/Calendar/Deck object ID that U does not have access to
- THEN the system MUST deny the tool call
- AND the system MUST NOT return any content from the inaccessible object

#### Scenario: Scoping survives the move into the service
- GIVEN `listFiles` and `readFile` have been relocated into `NcNativeToolService`
- WHEN either is invoked for user U
- THEN they MUST resolve paths against `getUserFolder(U)` only
- AND they MUST NOT accept an absolute path escaping U's user folder

## ADDED Requirements

### Requirement: Every curated tool declares honest hints and scope
Each `#[McpTool]`-annotated Hermiq method MUST declare `readOnlyHint`, `destructiveHint`, `idempotentHint` and `scope`, and those values MUST reflect what the method body actually does rather than what is convenient.

Hermiq's own `Engine\ToolGrantResolver` classifies a hint-less, non-3-segment tool id as
write/destructive and **fails closed** (`hermiq#57`). Every 2-segment `hermiq.*` tool today declares
no hints at all, so read-only tools such as `listFiles` are currently mis-classified as destructive
and stripped by default-deny. Declaring hints is what restores them — and what keeps the genuinely
dangerous ones gated on purpose rather than by accident.

Specifically: the five read-only NC-native tools MUST declare `readOnlyHint: true` / `scope: 'read'`.
`sendMail` MUST declare `destructiveHint: true`, `idempotentHint: false`, `scope: 'create'` — sending
mail is an irreversible external side effect and the app's primary exfiltration channel.
`recommendCourses` MUST declare `readOnlyHint: false`, `scope: 'update'`, because
`CourseRecommendationEngine::getOrRegenerate()` persists a regenerated recommendation when the cached
one is stale.

#### Scenario: A read-only NC-native tool is no longer default-denied
- GIVEN `hermiq.listFiles` declares `readOnlyHint: true` and `scope: 'read'`
- WHEN `ToolGrantResolver` classifies it
- THEN it MUST classify from the declared hints rather than the fail-closed fallback
- AND it MUST NOT be stripped from a read-only agent's tool list

#### Scenario: The outbound-mail tool stays gated by declaration
- GIVEN `hermiq.sendMail` declares `destructiveHint: true` and `scope: 'create'`
- WHEN `ToolGrantResolver` classifies it
- THEN it MUST classify as write/destructive
- AND it MUST require an explicit grant, and MUST be subject to the human-approval gate where configured

## Acceptance Criteria

- [ ] `lib/Mcp/HermiqToolProvider.php` no longer exists and no `IMcpToolProvider::hermiq` alias is registered
- [ ] All seven tool ids are byte-identical to their pre-migration values
- [ ] Each `#[McpTool]` declares all three hints plus `scope`
- [ ] The IDOR guards and error envelopes are unchanged by the relocation

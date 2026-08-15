# nc-native-tools

Delta: the NC-native surface gains read access to Nextcloud Mail, and with it the
rule that a capability moving personal correspondence into model context needs
authorisation beyond a tool grant. The existing IDOR requirement and the
route-remote-calls-through-OpenConnector requirement are unchanged and apply to
every tool added here.

## ADDED Requirements

### Requirement: Mail reading is exposed read-only and scoped to the acting user
The system MUST expose tools to list the acting user's mail accounts, page the
envelopes of one mailbox, and read one message. Every call MUST authorise by
scoping to the acting user before any data access. The system MUST NOT expose any
verb that deletes, moves, flags, marks read, drafts or sends from this capability.

#### Scenario: An agent reads a message in its user's own mailbox
- **GIVEN** an agent is running on behalf of user U
- **WHEN** the agent reads a message in a mailbox belonging to U
- **THEN** the message headers and text body MUST be returned

@e2e exclude The happy path needs an agent run that selects and calls the tool, plus a seeded IMAP message; the read path is covered by MailReadServiceTest against the gate and the lazy Mail resolution.

#### Scenario: An agent targets another user's account or mailbox
- **WHEN** a mail tool targets an account, mailbox or message the acting user does not own
- **THEN** the system MUST refuse
- **AND** no mail content or metadata from it MUST be returned

@e2e exclude The guard is Mail's own AccountService::find()/MailManager::getMailbox() being scoped to the acting uid, so another user's id resolves to not-found rather than to their data. No browser surface exists for a refused mailbox lookup.

#### Scenario: The mail surface is audited for write verbs
- **WHEN** the mail tools are enumerated
- **THEN** no tool MUST offer delete, move, flag, mark-read, draft or send
- **AND** reading a message MUST NOT change its read state

@e2e exclude A structural absence, asserted structurally by MailReadServiceTest::testServiceExposesNoWriteVerb. A browser can observe the tools that exist, never prove the absence of one.

### Requirement: Mail responses exclude attachment bytes, credentials and unbounded listings
Mail tool responses MUST NOT contain attachment bytes, account credentials or
server configuration. A mailbox listing MUST be bounded by a server-side maximum
page size that no tool argument can raise, and no form of any tool may return an
entire mailbox.

#### Scenario: A message with attachments is read
- **WHEN** a message carrying attachments is read
- **THEN** attachment name, size and MIME type MUST be returned
- **AND** attachment bytes MUST NOT be returned

@e2e exclude Requires a seeded message with attachments on a live IMAP account. The no-bytes rule is enforced by attachmentMetadata() returning name/size/mime only, asserted against the response shape.

#### Scenario: An agent requests a large page
- **WHEN** a listing request asks for more than the server-side maximum
- **THEN** the system MUST clamp to the maximum
- **AND** the maximum MUST NOT be raisable by any tool argument

@e2e exclude The clamp is a server-side constant applied before any query; covered by MailReadServiceTest, which pins MAX_PAGE_SIZE and asserts no argument raises it.

#### Scenario: Mail accounts are listed
- **WHEN** the acting user's mail accounts are listed
- **THEN** identity fields MUST be returned
- **AND** passwords and server settings MUST NOT be returned

@e2e exclude Needs a configured IMAP account in CI. The credentials-never-returned rule is enforced by the response shape (id, email, name only) and asserted directly.

### Requirement: The HTML body is returned only on request, and never presented as sanitised
Message reading MUST return the text body by default. It MUST return the HTML body
only when explicitly requested, MUST mark that body as unsanitised in the response,
and MUST NOT render it.

#### Scenario: A message is read without requesting HTML
- **WHEN** a message is read with no explicit HTML request
- **THEN** the text body MUST be returned
- **AND** no HTML body MUST be returned

@e2e exclude Body selection is internal to readMessage() and never rendered by Hermiq; asserted against the returned shape.

#### Scenario: HTML is explicitly requested
- **WHEN** a message is read with HTML explicitly requested
- **THEN** the HTML body MUST be returned
- **AND** the response MUST mark it as unsanitised
- **AND** the system MUST NOT render it or resolve any remote reference within it

@e2e exclude Same — and asserting it live would require a seeded HTML message. The unsanitised flag is part of the response contract and asserted there.

#### Scenario: Message content attempts to direct the agent
- **GIVEN** a message body containing instructions addressed to an agent, whether
  visible or hidden by markup
- **WHEN** the agent processes that message
- **THEN** the content MUST NOT authorise any action
- **AND** any write or send resulting from the run MUST still pass its own grant and
  approval checks

@e2e exclude Prompt injection is mitigated by mail content never authorising anything — enforced by the approval gate and write default-deny, which are covered by FacadeToolInvokerTest and the nc-native-write-tools suite, not by reading a crafted message in a browser.

### Requirement: Reading correspondence into model context requires AI-feature authorisation
A tool grant MUST NOT by itself authorise mail reading. The mail tools MUST be
gated on an explicitly enabled, registered AI feature, so that the decision to let
an agent read a user's correspondence is a recorded capability decision rather
than an implicit consequence of granting a tool.

#### Scenario: The tool is granted but the AI feature is disabled
- **GIVEN** an agent has been granted a mail read tool
- **AND** the mail-reading AI feature is not enabled
- **WHEN** the agent invokes the tool
- **THEN** the system MUST refuse
- **AND** no mail content MUST be read or returned

@e2e exclude Covered by MailReadServiceTest as a control PAIR — disabled refuses, and enabled passes the gate and fails on Mail instead. A single-sided test would pass against a service that refused everything.

#### Scenario: The AI feature is enabled
- **GIVEN** the mail-reading AI feature is enabled
- **AND** the agent has been granted the tool
- **WHEN** the agent invokes the tool
- **THEN** the read MUST proceed

@e2e exclude The positive half of that same control pair, asserted in MailReadServiceTest::testEnabledFeaturePassesTheGateAndFailsOnMailInstead.

#### Scenario: A wildcard grant is resolved
- **WHEN** a grant expressed as a wildcard is expanded
- **THEN** it MUST NOT expand to reach any mail tool
- **AND** each mail tool MUST require an explicit exact-id grant

@e2e exclude Wildcard expansion is ToolGrantResolver behaviour, covered by its own suite; these tools are hand-written 2-segment ids that no schema wildcard can match.

### Requirement: A mail read records its engine and quotes none of the mail
Every mail-read invocation record MUST identify the engine the run used, because
the answer to "who processed this correspondence" differs between a local engine
and a hosted provider. The record MUST NOT contain any subject, body, address or
attachment name.

#### Scenario: An operator reviews an agent's mail activity
- **WHEN** the per-agent tool invocation record is reviewed
- **THEN** each mail read MUST show account id, mailbox, message id, counts, timing,
  outcome and the engine identity
- **AND** the record MUST NOT contain the message's subject, body, addresses or
  attachment names

@e2e exclude Populating the oversight table needs a completed agent run against a live model. The record shape is covered by FacadeToolInvokerTest.

#### Scenario: A run uses a hosted engine
- **WHEN** a mail read runs on an engine outside the instance
- **THEN** the record MUST make that engine identifiable

@e2e exclude Requires a hosted provider key and a billed call; the engine field is recorded by the run trace, not by any page.

### Requirement: Mail reading produces no artefact to mark, and any future mail write must be markable
Because this capability is read-only, it creates and modifies nothing, so ADR-088's
marking rule has no artefact to apply to. Any later capability that writes a mail
object — a draft, a flag, a move, a sent reply — MUST mark that object as
agent-authored, and MUST NOT be exposed if the object cannot be marked.

#### Scenario: An agent reads a message
- **WHEN** a message is read
- **THEN** the message MUST be unchanged, including its read state
- **AND** no mail object MUST be created or modified

@e2e exclude Asserting a message is UNCHANGED after a read needs a live IMAP round trip. Read-only-ness is structural: no write verb exists to change it.

#### Scenario: A mail write capability is proposed
- **WHEN** a capability that writes a mail object is proposed
- **THEN** it MUST mark the written object as agent-authored
- **AND** it MUST NOT be exposed if no marking mechanism exists for that object

@e2e exclude A design constraint on future changes rather than runtime behaviour; nothing to execute. Enforced at review time and recorded in ADR-088 section 6.

### Requirement: Absence or drift of Mail's internal API degrades softly
Because Nextcloud Mail publishes no stable public contract, the system MUST resolve
its service lazily, guard for absence, and probe the shape before use. Absence and
shape mismatch MUST both return a structured error and MUST NOT throw or fail a run.

#### Scenario: Mail is not installed
- **WHEN** an agent invokes a mail tool on an instance without Mail
- **THEN** the tool MUST return a structured error identifying the missing app
- **AND** the agent run MUST continue

@e2e exclude This is the path every unit run already takes, since CI installs no Mail app; covered by MailReadServiceTest::testMailAbsentIsAStructuredErrorForEveryTool.

#### Scenario: Mail's internal API has changed shape
- **WHEN** the resolved service does not match the expected shape
- **THEN** the tool MUST return a structured error
- **AND** the run MUST continue

@e2e exclude Simulating drift means substituting a wrong-shaped service, which is a unit-test operation; the guard returns a structured error rather than throwing.

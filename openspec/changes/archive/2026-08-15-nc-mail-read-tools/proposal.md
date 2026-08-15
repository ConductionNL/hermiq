---
kind: code
---

# Proposal: nc-mail-read-tools

# Why

`hermiq.sendMail` has existed since `nc-native-tools`. Nothing reads. An agent can
compose and send mail on a user's behalf but cannot see the message it is
answering, cannot check whether a reply already arrived, and cannot tell a thread
from a cold email. Every mail-shaped task an assistant is actually asked for —
"heeft de gemeente al gereageerd?", "vat deze thread samen", "beantwoord de mail
van Jansen" — needs the read half.

Nextcloud Mail publishes no `OCP` contract, so this reaches its **internal API**
(`OCA\Mail\Service\*`), resolved lazily behind a `class_exists()` guard. That is a
deliberate decision, taken knowing the cost: an internal API carries no
deprecation contract and can change shape across Mail releases. The mitigation is
that both absence and shape-mismatch fail **soft** into the structured error
envelope, exactly as `listDeckBoards` already does for Deck's `BoardService`.

**The consequence that needs stating plainly, because it is the real one:**
reading mail puts a user's correspondence into a model's context window. Hermiq
already redacts on the way *out* — `DeliveryService` runs `RedactionService` before
anything crosses the instance boundary by email or webhook. That protects the
output path and it already works. What is new here is the **inference path**: the
message body reaches whatever engine the agent runs on, and when that engine is a
hosted provider, a third party now processes the user's inbox. No existing control
covers that, because until now nothing read personal correspondence into context.

That is a processing activity in its own right, not a tool detail, and this change
treats it as one.

# What Changes

- **`hermiq.listMailAccounts`** — the acting user's own mail accounts: id, address,
  display name. No credentials, no server settings, no passwords.
- **`hermiq.listMailMessages`** — a bounded, paginated envelope listing for one
  mailbox the acting user owns: id, subject, from, to, date, flags, has-attachments.
  No bodies. A hard maximum page size; no "list everything" form exists.
- **`hermiq.readMailMessage`** — one message by id: headers and body text, plus
  attachment **metadata** (name, size, MIME). Never attachment bytes. The **HTML
  body is available on explicit request** — layout carries meaning in an invoice
  table or a formatted decision letter — returned unsanitised and marked as such,
  never rendered by Hermiq, so no consumer can mistake it for safe markup.
- **Read-only, entirely.** No delete, move, flag, mark-read, draft or send verb is
  added. `sendMail` keeps its existing shape and classification; this change gives
  it no new powers and does not chain to it.
- **Gated as an AI feature, not just as a tool grant.** Reading a user's inbox into
  a model MUST be an explicitly enabled, registered capability under Hermiq's
  existing AI-feature governance, so that "this agent reads mail, on this engine" is
  a recorded decision rather than an implicit consequence of a tool grant. A grant
  alone is not sufficient authorisation.
- **The engine matters and is recorded.** The oversight record for a mail read MUST
  identify the engine the run used, because "who saw this inbox" has a different
  answer on a local engine than on a hosted provider.
- **Content never enters the audit log.** Invocation records carry account id,
  mailbox, message id and counts — never subjects, bodies or addresses. An
  art.12 record that quotes the mail it was recording defeats itself.
- **Honest hints**: all three tools are `scope: 'read'`, `readOnlyHint: true`. They
  are therefore *not* default-denied by write classification — which is precisely
  why the AI-feature gate above carries the weight instead of the grant default.
  They appear in the agent detail page's Tool governance grant editor like every
  other tool, and each can be granted or withheld individually.

# Capabilities

**Modified Capabilities**
- `nc-native-tools` — gains mail reading, and the rule that a capability which
  moves personal correspondence into model context requires AI-feature
  authorisation beyond a tool grant.

# Impact

- `lib/Mcp/HermiqToolProvider.php` gains three descriptors and three branches, each
  behind the lazy Mail-service resolution.
- Depends on Hermiq's existing AI-feature governance surface for the gate; no new
  OpenRegister schema, so no seed data.
- The read-plus-send combination is now grantable to one agent. That combination is
  an exfiltration primitive and is called out explicitly in design.md §Refusals —
  it is not forbidden, because a reply assistant is the obvious legitimate use, but
  it is not something an operator should be able to assemble without seeing it.

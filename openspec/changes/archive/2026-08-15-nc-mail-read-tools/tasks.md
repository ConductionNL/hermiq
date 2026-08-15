# Tasks — nc-mail-read-tools

## 1. Reach Mail's internal API safely

- [ ] 1.1 Resolve Nextcloud Mail's service lazily from the server container behind a `class_exists()` guard, mirroring the existing Deck `BoardService` resolution in `HermiqToolProvider`.
- [ ] 1.2 Probe the resolved service's shape before use; return a structured error on mismatch rather than a fatal. Assert `invokeTool()` still never throws.

## 2. The three read tools

- [ ] 2.1 Add `hermiq.listMailAccounts` (`scope: 'read'`, `readOnlyHint: true`) returning account id, address and display name only — no credentials, no server settings.
- [ ] 2.2 Add `hermiq.listMailMessages` (`scope: 'read'`) returning a paginated envelope list for one mailbox: id, subject, from, to, date, flags, has-attachments. No bodies.
- [ ] 2.3 Enforce a hard server-side maximum page size that no argument can raise, and provide no unbounded/list-all form of the tool.
- [ ] 2.4 Add `hermiq.readMailMessage` (`scope: 'read'`) returning headers, text body, and attachment metadata (name, size, MIME) — never attachment bytes. Return the HTML body only on explicit request, flagged unsanitised in the response shape; Hermiq itself never renders it.
- [ ] 2.5 Scope every call to `$userSession->getUser()->getUID()` before any data access; refuse another user's account, mailbox or message.

## 3. Governance beyond the tool grant

- [ ] 3.1 Gate all three tools on an explicitly enabled AI feature in Hermiq's existing AI-feature governance register — a tool grant alone MUST NOT authorise mail reading.
- [ ] 3.2 Record the engine identity on every mail-read invocation record, so "who saw this inbox" is answerable.
- [ ] 3.3 Assert no subject, body, address or attachment name reaches the invocation record — account id, mailbox, message id and counts only.
- [ ] 3.4 Confirm no write verb is added and `sendMail` is untouched: `grep -n "sendMail" lib/Mcp/HermiqToolProvider.php` shows its descriptor and branch unchanged by this change.

## 4. Verify

- [ ] 4.1 Negative control on the gate: with the AI feature DISABLED, all three tools refuse even when explicitly granted. Positive control: they succeed once it is enabled — run both, so the gate is shown to be load-bearing rather than merely present.
- [ ] 4.2 Unit-test the Mail-absent and shape-mismatch paths both return structured errors and the run completes.
- [ ] 4.3 Unit-test refusal for another user's account, mailbox and message — each touching no data.
- [ ] 4.4 Assert attachment bytes and HTML bodies are absent from every response shape, and that the page-size bound cannot be raised by argument.
- [ ] 4.5 Assert all three ids appear in `ToolOversightController::toolCatalog()` and are individually grantable; assert no wildcard grant form expands to reach them.
- [ ] 4.6 Scoped `phpcs` clean; zero new PHPUnit failures vs a self-measured baseline; CHANGELOG entry noting the inference-path exposure and the AI-feature gate.

## Acceptance criteria

- An agent can list its user's mail accounts, page a mailbox, and read one message, all scoped to the acting user.
- No attachment bytes, no HTML bodies, no credentials, and no unbounded mailbox listing exist in any response.
- Mail reading is refused unless the AI feature is enabled, proven by a disabled-then-enabled control pair.
- Every invocation record names the engine and quotes none of the mail.
- Mail is absent or its internal API has moved: both degrade to a structured error and the run completes.

# Tasks: delivery-public-web-chat

Kind: code. Size L. Row `hermiq:dl-embed-web`.

## Implementation tasks

### Task 1: Schemas and the AI feature
- **spec_ref**: `openspec/changes/delivery-public-web-chat/specs/public-chat-channel/spec.md#requirement-an-organisation-admin-declares-a-public-chat-channel-req-pubchat-001`
- **files**: `lib/Settings/hermiq_register.json` (PublicChannel, PublicConversation), `lib/Repair/SeedAiFeatures.php`
- **acceptance_criteria**:
  - GIVEN the re-import WHEN it runs THEN both schemas exist and `public-chat` is seeded disabled
- [x] Implement (register 0.53.0: `agentpublicchannel`, `agentpublicconversation`; `public-chat` seeded `limited`, disabled)
- [x] Test (npm run check:register; `SeedAiFeaturesTest`, `PublicChatSchemasTest`)

### Task 2: Scoped anonymous retrieval
- **spec_ref**: `openspec/changes/delivery-public-web-chat/specs/public-chat-channel/spec.md#requirement-a-visitor-chats-without-signing-in-and-gets-answers-only-from-public-content-req-pubchat-002`
- **files**: `lib/Service/PublicChat/PublicRetrievalService.php`
- **acceptance_criteria**:
  - GIVEN a scope and a non-anonymous object in it WHEN retrieved THEN the object is never returned
- [x] Implement (every search runs inside OpenRegister's anonymous scope, acting for the channel's organisation when it has one; no anonymous scope means no sources)
- [x] Test (PHPUnit done: `PublicRetrievalServiceTest`; the live check reading a private object through a channel needs a running instance)
  - 25 Nov, live (sandbox Nextcloud 34, PostgreSQL 16, OpenRegister main + #11225, a local model): a channel of an organisation scoped to a whole register holding a page with `read: [public]`, a note with `read: [authenticated]` and a schema with no authorization block. Asked "Wat staat er over de afvalkalender?" and "Wat is de sleutelcode van het personeel voor de afvalkalender?": the restricted note is never a source and its text never reaches the answer; signed in, the same note is found. Two reds found and fixed: OpenRegister's `_search` asks for every word, so a visitor's sentence matched nothing (now searched by its subject words, `QuestionTerms`, an object found by more words first); a whole-register scope searched without a schema and found nothing (now names the register's schemas, `ScopeSchemas`). Note for channel admins: a schema without an authorization block is open in OpenRegister's model, so its objects are readable through the channel like any anonymous read.

### Task 3: The entry point and the turn
- **spec_ref**: `openspec/changes/delivery-public-web-chat/specs/public-chat-channel/spec.md#requirement-sibling-apps-reach-the-same-channel-in-process-without-an-identity-req-pubchat-004`
- **files**: `lib/PublicChat/PublicChatEntryPoint.php`, `lib/Service/PublicChat/PublicChatService.php`
- **acceptance_criteria**:
  - GIVEN an app scope wider than the channel's WHEN asked THEN the intersection is used; GIVEN a stopped agent THEN "not available"
- [x] Implement (`PublicChatEntryPoint::ask()`, `PublicChatService` for the turn, `PublicChatGate` for channel, feature, kill switch, agent and daily limit, `PublicChannelStore` for system reads and the token-keyed conversation)
- [x] Test (PHPUnit including guardrail block, kill switch, feature off: `PublicChatServiceTest`, `PublicChannelStoreTest`, `PublicChatEntryPointTest`)

### Task 4: The public endpoint, rate limits and CORS
- **spec_ref**: `openspec/changes/delivery-public-web-chat/specs/public-chat-channel/spec.md#requirement-the-public-endpoint-is-limited-and-origin-bound-req-pubchat-003`
- **files**: `lib/Controller/PublicChatController.php`, `appinfo/routes.php`
- **acceptance_criteria**:
  - GIVEN a disallowed origin WHEN it posts THEN no CORS headers; GIVEN the daily limit WHEN exceeded THEN 429
- [x] Implement (`POST` and `OPTIONS /api/public/{channelToken}/messages`; `#[AnonRateLimit]` and `#[UserRateLimit]` 20 per minute, the channel's daily limit in `PublicChatGate`; CORS only for the channel's exact origins)
- [x] Test (PHPUnit done: `PublicChatControllerTest`; the Newman run for origin, limit and feature off needs a running instance)
  - 2026-11-24: Newman folder "Public chat origin, limit and feature off" in `tests/integration/hermiq.postman_collection.json` written, runs in nightly (UNBLOCK rule 3): another origin gets 403 and no CORS headers on OPTIONS and POST, the listed origin gets `Access-Control-Allow-Origin`, the second question on a limit of one answers 429, and with `public-chat` off the chat answers 503 not available; the feature is put back as found. Not verified against a live instance here.

### Task 5: The chat window and the embed script
- **spec_ref**: `openspec/changes/delivery-public-web-chat/specs/public-chat-channel/spec.md#requirement-a-visitor-chats-without-signing-in-and-gets-answers-only-from-public-content-req-pubchat-002`
- **files**: `templates/public-chat.php`, `src/public-chat/`, `webpack.config.js` entry, `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN the iframe page WHEN opened THEN the disclosure shows before the input and answers show sources as links
- [x] Implement (`GET /public/{channelToken}` renders `templates/public-chat.php`, framed only by the channel's origins; `src/public-chat/window.js` shows the disclosure before the input and sources as links; `js/public-chat.js` is the embed script; both self-contained webpack entries; en/nl strings)
- [x] Test (vitest done: `tests/vitest/public-chat.spec.js`, and `PublicChatControllerTest` for the page; Playwright logged out and axe need a running instance)
  - 2026-11-25, sandbox instance (Nextcloud 34, PostgreSQL 16, OpenRegister main, hermiq development): Playwright `public-web-chat.spec.ts` logged out. It found that the window's Send button sat below the frame: the base layout's content area starts under the header and clips, and the window asked for `min-height: 100vh`. `window.css` now pins the window to the viewport (the website's iframe), and only the log scrolls. After the fix: 4/4. axe-core 4.10.2 (WCAG 2.0/2.1 A and AA) on the logged-out window at the iframe size 380x560: 0 violations, 20 rules passed, and Send is inside the frame.
  - 2026-11-22: `tests/e2e/spec-coverage/public-web-chat.spec.ts` written from the scenarios (seeded objects and `page.route` stubs where a model, a flow or a sent mail would be needed); not run here, it needs a running Nextcloud.

### Task 6: The Public chat settings page
- **spec_ref**: `openspec/changes/delivery-public-web-chat/specs/public-chat-channel/spec.md#requirement-an-organisation-admin-declares-a-public-chat-channel-req-pubchat-001`
- **files**: `src/manifest.json`, `src/views/PublicChatSettings.vue`, `src/modals/PublicChannelFormModal.vue`
- **acceptance_criteria**:
  - GIVEN an admin WHEN they create a channel THEN both snippets show; an agent with tools is refused
- [x] Implement (the `PublicChat` settings page is manifest configuration, an index page over `agentpublicchannel` with `slots.form-dialog` -> `PublicChannelFormModal`, rather than a hand-built `PublicChatSettings.vue`; `PublicChannelGuardListener` refuses an agent with tools with "A public assistant cannot use tools. Pick an agent without tools.", refuses an origin with a path, and gives a new channel its token; the form shows the script and iframe snippets once a website channel is saved)
- [x] Test (PHPUnit `PublicChannelGuardListenerTest` and vitest `public-channel-settings.spec.js` done; Playwright needs a running instance. Entry point for the portaliq lane: `OCA\Hermiq\PublicChat\PublicChatEntryPoint::ask(string $channelKey, string $question, string $locale, array $sourceScope, ?string $conversationToken): PublicAnswer`, channel key `app:portaliq:<portal-slug>`)
  - 2026-11-24: Playwright "a communications advisor puts the assistant on the municipal website" and "an agent with tools cannot be public" in `tests/e2e/spec-coverage/public-web-chat.spec.ts` written, run in nightly (UNBLOCK rule 3); not verified against a live instance here.

## Verification
- [x] `openspec validate delivery-public-web-chat --type change --strict` passes
  - 2026-11-21: valid under `@fission-ai/openspec@1.2.0`, the version hydra's apply loop pins.
- [x] PHPUnit, Newman and Playwright run, exit codes read
  - 2026-11-25, sandbox instance: PHPUnit PublicRetrievalServiceTest, PublicChatControllerTest, PublicChannelGuardListenerTest, SeedAiFeaturesTest, PublicChatSchemasTest and PublicChatPageControllerTest: 28 tests, exit 0. Vitest public-chat + public-channel-settings: 18/18. Newman folder "Public chat origin, limit and feature off": 15 requests, 15 assertions, 0 failed. The daily limit needs a distributed cache (APCu, with `apc.enable_cli=1` under `php -S`); without one the 429 check is the only red. Playwright `public-web-chat.spec.ts`: 4/4. This also fixed the Public chat list: a channel saved in the dialog now appears without a reload, because the form modals call the slot's `refresh` binding.

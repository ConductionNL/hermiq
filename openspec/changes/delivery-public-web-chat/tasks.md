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
- [ ] Test (PHPUnit done: `PublicRetrievalServiceTest`; the live check reading a private object through a channel needs a running instance)

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
- [ ] Test (PHPUnit done: `PublicChatControllerTest`; the Newman run for origin, limit and feature off needs a running instance)

### Task 5: The chat window and the embed script
- **spec_ref**: `openspec/changes/delivery-public-web-chat/specs/public-chat-channel/spec.md#requirement-a-visitor-chats-without-signing-in-and-gets-answers-only-from-public-content-req-pubchat-002`
- **files**: `templates/public-chat.php`, `src/public-chat/`, `webpack.config.js` entry, `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN the iframe page WHEN opened THEN the disclosure shows before the input and answers show sources as links
- [x] Implement (`GET /public/{channelToken}` renders `templates/public-chat.php`, framed only by the channel's origins; `src/public-chat/window.js` shows the disclosure before the input and sources as links; `js/public-chat.js` is the embed script; both self-contained webpack entries; en/nl strings)
- [ ] Test (vitest done: `tests/vitest/public-chat.spec.js`, and `PublicChatControllerTest` for the page; Playwright logged out and axe need a running instance)

### Task 6: The Public chat settings page
- **spec_ref**: `openspec/changes/delivery-public-web-chat/specs/public-chat-channel/spec.md#requirement-an-organisation-admin-declares-a-public-chat-channel-req-pubchat-001`
- **files**: `src/manifest.json`, `src/views/PublicChatSettings.vue`, `src/modals/PublicChannelFormModal.vue`
- **acceptance_criteria**:
  - GIVEN an admin WHEN they create a channel THEN both snippets show; an agent with tools is refused
- [x] Implement (the `PublicChat` settings page is manifest configuration, an index page over `agentpublicchannel` with `slots.form-dialog` -> `PublicChannelFormModal`, rather than a hand-built `PublicChatSettings.vue`; `PublicChannelGuardListener` refuses an agent with tools with "A public assistant cannot use tools. Pick an agent without tools.", refuses an origin with a path, and gives a new channel its token; the form shows the script and iframe snippets once a website channel is saved)
- [ ] Test (PHPUnit `PublicChannelGuardListenerTest` and vitest `public-channel-settings.spec.js` done; Playwright needs a running instance. Entry point for the portaliq lane: `OCA\Hermiq\PublicChat\PublicChatEntryPoint::ask(string $channelKey, string $question, string $locale, array $sourceScope, ?string $conversationToken): PublicAnswer`, channel key `app:portaliq:<portal-slug>`)

## Verification
- [ ] `openspec validate delivery-public-web-chat --type change --strict` passes
- [ ] PHPUnit, Newman and Playwright run, exit codes read

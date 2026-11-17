# Tasks: delivery-public-web-chat

Kind: code. Size L. Row `hermiq:dl-embed-web`.

## Implementation tasks

### Task 1: Schemas and the AI feature
- **spec_ref**: `openspec/changes/delivery-public-web-chat/specs/public-chat-channel/spec.md#requirement-an-organisation-admin-declares-a-public-chat-channel-req-pubchat-001`
- **files**: `lib/Settings/hermiq_register.json` (PublicChannel, PublicConversation), `lib/Repair/SeedAiFeatures.php`
- **acceptance_criteria**:
  - GIVEN the re-import WHEN it runs THEN both schemas exist and `public-chat` is seeded disabled
- [ ] Implement
- [ ] Test (npm run check:register; PHPUnit on the seed)

### Task 2: Scoped anonymous retrieval
- **spec_ref**: `openspec/changes/delivery-public-web-chat/specs/public-chat-channel/spec.md#requirement-a-visitor-chats-without-signing-in-and-gets-answers-only-from-public-content-req-pubchat-002`
- **files**: `lib/Service/PublicChat/PublicRetrievalService.php`
- **acceptance_criteria**:
  - GIVEN a scope and a non-anonymous object in it WHEN retrieved THEN the object is never returned
- [ ] Implement
- [ ] Test (PHPUnit; a live check reading a private object through a channel, output in the PR)

### Task 3: The entry point and the turn
- **spec_ref**: `openspec/changes/delivery-public-web-chat/specs/public-chat-channel/spec.md#requirement-sibling-apps-reach-the-same-channel-in-process-without-an-identity-req-pubchat-004`
- **files**: `lib/PublicChat/PublicChatEntryPoint.php`, `lib/Service/PublicChat/PublicChatService.php`
- **acceptance_criteria**:
  - GIVEN an app scope wider than the channel's WHEN asked THEN the intersection is used; GIVEN a stopped agent THEN "not available"
- [ ] Implement
- [ ] Test (PHPUnit including guardrail block, kill switch, feature off)

### Task 4: The public endpoint, rate limits and CORS
- **spec_ref**: `openspec/changes/delivery-public-web-chat/specs/public-chat-channel/spec.md#requirement-the-public-endpoint-is-limited-and-origin-bound-req-pubchat-003`
- **files**: `lib/Controller/PublicChatController.php`, `appinfo/routes.php`
- **acceptance_criteria**:
  - GIVEN a disallowed origin WHEN it posts THEN no CORS headers; GIVEN the daily limit WHEN exceeded THEN 429
- [ ] Implement
- [ ] Test (PHPUnit; Newman for origin, limit and feature off)

### Task 5: The chat window and the embed script
- **spec_ref**: `openspec/changes/delivery-public-web-chat/specs/public-chat-channel/spec.md#requirement-a-visitor-chats-without-signing-in-and-gets-answers-only-from-public-content-req-pubchat-002`
- **files**: `templates/public-chat.php`, `src/public-chat/`, `webpack.config.js` entry, `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN the iframe page WHEN opened THEN the disclosure shows before the input and answers show sources as links
- [ ] Implement
- [ ] Test (Playwright on the standalone page, logged out; axe on the page)

### Task 6: The Public chat settings page
- **spec_ref**: `openspec/changes/delivery-public-web-chat/specs/public-chat-channel/spec.md#requirement-an-organisation-admin-declares-a-public-chat-channel-req-pubchat-001`
- **files**: `src/manifest.json`, `src/views/PublicChatSettings.vue`, `src/modals/PublicChannelFormModal.vue`
- **acceptance_criteria**:
  - GIVEN an admin WHEN they create a channel THEN both snippets show; an agent with tools is refused
- [ ] Implement
- [ ] Test (Playwright; tell the portaliq lane the entry point name and signature)

## Verification
- [ ] `openspec validate delivery-public-web-chat --type change --strict` passes
- [ ] PHPUnit, Newman and Playwright run, exit codes read

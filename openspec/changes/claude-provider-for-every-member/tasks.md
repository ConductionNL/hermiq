# Tasks: claude-provider-for-every-member

## 1. Act for the task's user
- [x] 1.1 `ProviderFactory::actingFor(userId, work)`: sessionless `currentUid()` answers the named user for the duration of `work`, restored afterwards even on an exception; a session always wins
- [x] 1.2 `ProviderFactory::generateText()` runs inside `actingFor($userId)`
- [x] 1.3 `ContextAgentInteractionService::interact()` runs the engine turn inside `actingFor($userId)`

## 2. Credential scope for Anthropic
- [x] 2.1 `createAnthropicDriver()` takes a personal then organisation override from `CredentialScopeResolver` for API key over http; OAuth and CLI keep the configured credential

## 3. Settings dialog
- [x] 3.1 The AI provider dialog loads personal and active-organisation credentials and marks the latter "(organisation)" (en + nl)

## 4. Tests
- [x] 4.1 A sessionless Anthropic call from `generateText($prompt, 'bob')` reaches the broker with `actingUserId` bob (fails on the old code)
- [x] 4.2 A session caller is never replaced by the named user; the acting user is cleared after the work, also after an exception
- [x] 4.3 The ContextAgent engine turn sees the task's user (fails on the old code)
- [x] 4.4 Anthropic API key over http uses the resolver's override; OAuth keeps the configured credential
- [x] 4.5 The dialog's loader merges both lists, marks organisation credentials and survives either list failing (fails on the old code: no organisation list)
- [x] 4.6 Live on :8099: a second user's session chat and an Assistant task from cron reach api.anthropic.com through the broker; the only failure left is Anthropic refusing the dummy key

# Tasks: claude-provider-for-every-member

## 1. Act for the task's user
- [x] 1.1 `ProviderFactory::actingFor(userId, work)`: sessionless `currentUid()` answers the named user for the duration of `work`, restored afterwards even on an exception; a session always wins
- [x] 1.2 `ProviderFactory::generateText()` runs inside `actingFor($userId)`
- [x] 1.3 `ContextAgentInteractionService::interact()` runs the engine turn inside `actingFor($userId)`
- [x] 1.4 Without a session, `interact()` runs as the task's user through OpenRegister's `ObjectService::runAs()`; an unknown or disabled task user is refused

- [x] 1.5 Work entered without a session stays background inside a `runAs()` switch; its broker calls go through `requestForBackgroundUser()`; no user or no such entry is refused

## 2. Credential scope for Anthropic
- [x] 2.1 `createAnthropicDriver()` takes a personal then organisation override from `CredentialScopeResolver` for API key over http; OAuth and CLI keep the configured credential
- [x] 2.2 An empty configured model falls back to the default model

## 3. Settings dialog
- [x] 3.1 The AI provider dialog loads personal and active-organisation credentials and marks the latter "(organisation)" (en + nl)

- [x] 3.2 The organisation credential section shows its organisation and lets the admin choose one they may manage (default: active); list and create carry it (en + nl)
- [x] 3.3 The AI provider dialog lists every manageable organisation's credentials, labelled with the organisation's name

## 4. Tests
- [x] 4.1 A sessionless Anthropic call from `generateText($prompt, 'bob')` reaches the broker with `actingUserId` bob (fails on the old code)
- [x] 4.2 A session caller is never replaced by the named user; the acting user is cleared after the work, also after an exception
- [x] 4.3 The ContextAgent engine turn sees the task's user, through `runAs` and the broker (fails on the old code); a disabled task user is refused before anything is saved (fails on the old code)
- [x] 4.3a An empty model falls back to the default (fails on the old code)
- [x] 4.4 Anthropic API key over http uses the resolver's override; OAuth keeps the configured credential
- [x] 4.5 The dialog's loader merges both lists, marks organisation credentials and survives either list failing (fails on the old code: no organisation list)
- [x] 4.5a A user switch inside background work stays a background call; BrokerHttpClient uses the background entry and fails closed without it or without a user (fail on the previous version)
- [x] 4.5b The picker defaults to the active organisation; the form carries the chosen organisation; the dialog names each organisation (fail on the previous version)
- [x] 4.6 Live on :8099: a second user's session chat and an Assistant task from cron reach api.anthropic.com through the broker; the only failure left is Anthropic refusing the dummy key

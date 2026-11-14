# agent-credentials Specification

**Status**: proposed
**Scope**: hermiq
**OpenSpec changes**:
- models-provider-connections

## Purpose

A user signs in with a model subscription through the vendor's own sign-in instead of pasting a token, and the token lands in the credential broker as a personal credential. Row `hermiq:dm-subscription-login`.

## ADDED Requirements

### Requirement: A user can sign in with a Claude subscription (REQ-SIGNIN-001)

Hermiq MUST let a signed-in user start a Claude subscription sign-in from their own settings. The sign-in MUST run the vendor's CLI sign-in inside the llm-runner ExApp, and MUST NOT use an OAuth client of hermiq's own.

#### Scenario: A user connects their Claude subscription
- GIVEN a user in their personal settings, Hermiq section, with the llm-runner ExApp enabled
- WHEN they choose "Sign in with your Claude subscription", open the sign-in page, sign in and paste the code the page shows
- THEN the dialog says "Signed in. Your Claude subscription is saved as a personal credential." and the credentials list shows a personal `anthropic-cli` credential
- @e2e exclude needs a real Claude subscription and the vendor's sign-in page; covered by PHPUnit with a stubbed runner and a manual walk-through

#### Scenario: No runner, no button
- GIVEN an instance where the llm-runner ExApp is not enabled
- WHEN a user opens their personal settings
- THEN the sign-in button is replaced by "Subscription sign-in needs the Hermiq LLM runner app."

### Requirement: A signed-in token is kept only in the broker (REQ-SIGNIN-002)

Hermiq MUST write the token returned by a sign-in into the credential broker as the session user's personal credential, in the same request. Hermiq MUST NOT store it in an object, a configuration value or a log, and MUST NOT return it to the browser. The runner MUST remove the sign-in's working directory when the sign-in ends.

#### Scenario: The token never reaches the browser
- GIVEN a completed sign-in
- WHEN the browser receives hermiq's response
- THEN the response holds the credential's id and label only, and the server log holds no part of the token
- @e2e exclude token handling inside one server request; covered by PHPUnit asserting on the response and on a captured logger

### Requirement: A subscription credential stays personal (REQ-SIGNIN-003)

A credential created by a sign-in MUST have personal scope, and MUST serve only its owner's runs. The admin provider settings MUST NOT offer a subscription sign-in.

#### Scenario: An admin cannot sign in for the organisation
- GIVEN an admin in Admin settings, LLM provider, Anthropic
- WHEN they look for a way to sign in with a subscription
- THEN there is none, and the warning says a subscription may only be a personal credential

### Requirement: A ChatGPT subscription can sign in where the Codex CLI allows it (REQ-SIGNIN-004)

Where the pinned Codex CLI offers a headless sign-in, hermiq MUST offer "Sign in with your ChatGPT subscription" with the same rules, stored as a personal `openai-cli` credential. Where it does not, hermiq MUST NOT show the button.

#### Scenario: A user connects their ChatGPT subscription
- GIVEN a runner whose pinned Codex CLI supports device sign-in
- WHEN a user chooses "Sign in with your ChatGPT subscription" and enters the shown code on OpenAI's page
- THEN a personal `openai-cli` credential appears, and a `cli` turn on the OpenAI provider runs on it
- @e2e exclude needs a real ChatGPT subscription; covered by PHPUnit with a stubbed runner

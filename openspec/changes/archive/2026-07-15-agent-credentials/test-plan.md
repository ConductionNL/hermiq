# Test Plan: agent-credentials

## Test Cases

### TC-1: Manifest declares hermiq's credential needs
- **spec_ref**: `openspec/changes/agent-credentials/specs/agent-credentials/spec.md#requirement-manifest-declared-credential-requirements`
- **type**: functional
- **persona**: Priya (ZZP Developer / Integrator) — cares about knowing exactly what an app touches
- **preconditions**: hermiq installed with the updated manifest
- **steps**: Open hermiq → Settings → Agent credentials
- **expected result**: The personal-scope section shows "What Hermiq uses": OpenAI, Fireworks AI, GitHub, each with its declared reason; no secret is ever shown
- **test command**: `/test-functional`

### TC-2: A user adds a personal credential for hermiq
- **spec_ref**: `openspec/changes/agent-credentials/specs/agent-credentials/spec.md#requirement-personal-and-organisation-credential-management-surfaces`
- **type**: functional
- **persona**: Priya (ZZP Developer / Integrator)
- **preconditions**: Signed-in user, no personal OpenAI broker credential yet
- **steps**: Settings → Agent credentials → Your credentials → Add credential → pick OpenAI → paste key → submit
- **expected result**: A new personal credential appears, `hermiq` implicitly allowed; the secret field is never populated on reload; a `GET /apps/openregister/api/credentials?scope=personal` call (verified via network inspection) never returns a secret field
- **test command**: `/test-functional`

### TC-3: An organisation admin adds an organisation credential
- **spec_ref**: `openspec/changes/agent-credentials/specs/agent-credentials/spec.md#requirement-personal-and-organisation-credential-management-surfaces`
- **type**: functional
- **persona**: Noor Yilmaz (Municipal CISO / Functional Admin) — organisation-level governance concern
- **preconditions**: User is an administrator of their active organisation
- **steps**: Settings → Agent credentials → Organisation credentials → Add credential → pick Fireworks AI → submit
- **expected result**: An organisation-scope credential is created, visible to other org members' read view
- **test command**: `/test-persona-noor`

### TC-4: A non-admin organisation member cannot write organisation credentials
- **spec_ref**: `openspec/changes/agent-credentials/specs/agent-credentials/spec.md#requirement-personal-and-organisation-credential-management-surfaces`
- **type**: security
- **preconditions**: User is a member but not an administrator of their active organisation
- **steps**: Attempt to add/delete an organisation-scope credential from hermiq's Settings page
- **expected result**: The write is rejected (403 from `CredentialController`); the credential list is unchanged after reload
- **test command**: `/test-security`

### TC-5: Personal credential overrides the instance default
- **spec_ref**: `openspec/changes/agent-credentials/specs/agent-credentials/spec.md#requirement-run-time-credential-resolution-precedence`
- **type**: functional
- **preconditions**: User has a personal OpenAI credential allowing `hermiq`; instance-wide OpenAI credential is also configured
- **steps**: Run an OpenAI-provider agent as that user (Run now)
- **expected result**: The run succeeds using the user's own credential (verified via PHPUnit unit test asserting `ChatDriver`/broker call args, since the actual outbound HTTP call cannot be observed end-to-end without a live OpenAI account)
- **test command**: `/test-functional` (unit-test-backed; see Coverage Summary)

### TC-6: Organisation credential used when no personal credential exists
- **spec_ref**: `openspec/changes/agent-credentials/specs/agent-credentials/spec.md#requirement-run-time-credential-resolution-precedence`
- **type**: functional
- **preconditions**: User has no personal OpenAI credential; their organisation has one allowing `hermiq`
- **steps**: Run an OpenAI-provider agent belonging to that organisation
- **expected result**: The organisation's credential is used (unit-test-backed)
- **test command**: `/test-functional`

### TC-7: Instance default used when neither personal nor organisation credential exists (regression)
- **spec_ref**: `openspec/changes/agent-credentials/specs/agent-credentials/spec.md#requirement-run-time-credential-resolution-precedence`
- **type**: regression
- **preconditions**: Fresh instance, no personal/organisation credentials configured for the running user/org
- **steps**: Run any existing OpenAI/Fireworks-provider agent exactly as before this change
- **expected result**: Behaviour is byte-for-byte identical to pre-change — the instance-wide credential is used; every pre-existing `ProviderFactoryTest` case still passes unmodified
- **test command**: `/test-regression`

### TC-8: Wrong-provider / disallowed credentials are never selected
- **spec_ref**: `openspec/changes/agent-credentials/specs/agent-credentials/spec.md#requirement-run-time-credential-resolution-precedence`
- **type**: functional
- **preconditions**: User has a personal GitHub credential and a personal Fireworks credential not allowing `hermiq`
- **steps**: Run an OpenAI-provider agent as that user
- **expected result**: Neither irrelevant credential is selected; resolution falls through to organisation, then instance, default (unit-test-backed)
- **test command**: `/test-functional`

### TC-9: Broker guards still apply after resolver selection
- **spec_ref**: `openspec/changes/agent-credentials/specs/agent-credentials/spec.md#requirement-resolver-selections-never-bypass-the-brokers-own-guards`
- **type**: security
- **preconditions**: A resolved personal credential is deleted between resolution and the actual broker call
- **steps**: Trigger the run path such that the credential no longer exists when `BrokerHttpClient::sendRequest()` executes
- **expected result**: The broker call fails closed with a clear error; no silent substitution of a different credential occurs
- **test command**: `/test-security`

## Coverage Summary
- Manifest-declared credential requirements — covered (TC-1)
- Personal and organisation credential management surfaces — covered (TC-2, TC-3, TC-4)
- Run-time credential resolution precedence — covered (TC-5, TC-6, TC-7, TC-8)
- Resolver selections never bypass the broker's own guards — covered (TC-9)

## Out of Scope
- End-to-end verification against a real OpenAI/Fireworks account (TC-5, TC-6,
  TC-8) is unit-test-backed only (asserting the resolved `credentialId` reaches
  `ChatDriver`/the broker call) — no live third-party account is exercised in
  CI, consistent with how the existing `ProviderFactoryTest` suite already
  tests provider selection without live network calls.
- The web-research search-backend credential path (`WebSearchClient`) is
  explicitly out of scope for this change (see proposal.md) and has no test
  cases here.

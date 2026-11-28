# Design: tools-browser-and-computer-use

Kind: code. Size L. A browser service in the code sandbox app, four browser tools, a `ComputerTarget` schema reached through integriq, four computer tools, an AI feature, and the approval handling for computer actions.

## Context at development db6b74dc

- `lib/Mcp/HermiqToolProvider.php:465-512` the `webSearch` and `webFetch` descriptors (reach `external`, read-only), `:675` `invokeTool()`.
- `lib/Service/WebResearch/WebFetchService.php:72` the untrusted-content delimiter, `:112` `fetch()`; `lib/Service/WebResearch/WebResearchEgressGuard.php:112` `assertSafe()`, the one egress policy.
- `lib/Controller/EgressAuthorizeController.php` the per-connection policy decision point for the llm-runner's egress proxy, token-gated by `RunTokenService`; route `appinfo/routes.php:725`.
- `lib/Service/GuardrailPolicyService.php:299` `filterInput()`, `:346` `classifyTool()`.
- `lib/Service/NcNative/MailReadService.php:79`, `:363-395` the AI feature gate.
- `lib/Service/SkillMarketplaceService.php:458-470` integriq's `CallService` by canonical name.
- `openspec/changes/hermiq-runner-git-capability/specs/agent-workspace-git-tools/spec.md:325-337` the run-scoped pre-authorisation, a real approval record that expires with the run token.
- `openspec/changes/tools-code-sandbox/design.md` D1 and D2, the sandbox app this change adds a service to.

## D1. The browser lives in the sandbox app, behind the existing egress policy

The code sandbox app gains a `browser` service: headless Chromium driven over the Chrome DevTools Protocol by a small controller in the same container. It has no volumes and no user data. Its only way out is an egress proxy, the same design as the llm-runner's, that asks hermiq's policy decision point about every connection with the run's token, so the browser can reach exactly what `hermiq.webFetch` may reach and nothing else, private and metadata addresses included. It shares nothing with the code runner's network.

One browser context per conversation, closed after 10 idle minutes or when the conversation ends. Downloads are off. The context starts with no cookies and no stored credentials.

Rejected: a browser inside the llm-runner. That container holds a vendor credential. Rejected: a hosted browser vendor. The row asks for a browser the agent can drive, and the sandbox app already carries the hardening.

## D2. Four browser tools

- `hermiq.browserOpen` `{url}`: navigates and returns the snapshot. Reach `external`, `readOnlyHint: true`.
- `hermiq.browserRead` `{}`: returns the current snapshot. Reach `external`, `readOnlyHint: true`.
- `hermiq.browserClick` `{ref}` and `hermiq.browserFill` `{ref, text}`: reach `external`, `scope: update`, `destructiveHint: true`, because a click can submit a form on someone else's site and cannot be taken back.

A snapshot is the page's accessibility tree as text, each actionable element tagged with a short reference (`[e12] button "Aanvraag indienen"`), cut to 20 KB. It is delimited with the `webFetch` untrusted markers and passed through `filterInput()` with the organisation's policy, so the prompt-injection action applies to what a page says.

`hermiq.browserFill` refuses a password field or any field with `autocomplete` set to a credential kind, with "The agent does not type passwords."

## D3. Computer use on a machine the organisation runs

A `ComputerTarget` object: `name`, `description`, `source` (an integriq source that reaches the remote-control service on the machine, through the organisation's own network path), `os` (`windows`), `applications` (a text the reviewer sees, for example "Suite4Sociaal Domein, versie 12"), `enabled`. Admin-only routes, `#[AuthorizedAdminSetting]`.

The remote-control service on the machine answers a small contract, carried by integriq's `CallService`:

- `POST /screenshot` returns a PNG and the screen size.
- `POST /action` with `{type: click | double_click | right_click | type | key | scroll, x, y, text, keys}` performs one action and returns a new screenshot.

Hermiq never opens a connection to the machine itself.

## D4. Computer tools and who may use them

`hermiq.computerScreenshot` (`readOnlyHint: true`), `hermiq.computerClick`, `hermiq.computerType` and `hermiq.computerKey` (`destructiveHint: true`), all with a `target` argument naming a `ComputerTarget`, all reach `external` because the screen's content goes to the model provider.

Every call first requires the AI feature `computer-use` to be enabled. It is seeded `riskCategory: high` and `lifecycle: disabled`, so the DPO acknowledgement of `ai-feature-governance` must be recorded before an admin can enable it. A tool grant alone never unlocks it.

Screenshots return as an image content block. Only the Anthropic `http` path and the OpenAI Responses path (from `models-provider-connections`) carry images in a tool result today, so the computer tools are left out of the catalogue for any other provider, and the agent form says "Computer use needs a model that can read images."

## D5. Every action passes the approval gate

The action tools are classified `confirm` regardless of the organisation's guardrail policy: `classifyTool()` cannot lower them. The approval request shows the target machine, the action and the latest screenshot with the click point marked. A reviewer can approve this one action, or approve actions on this machine for this run for at most 15 minutes, which is the run-scoped pre-authorisation `hermiq-runner-git-capability` specifies: a real approval record with a named decision maker that expires with the run and never covers another run or another machine. A screenshot needs no approval.

Rejected: a switch that turns the gate off for trusted agents. `hermiq-runner-git-capability` forbids any bypass for write-shaped tools, and the same reasoning holds for a desktop that runs a case system.

## D6. What the run records

Every browser and computer action is a tool step with its target (URL host, or machine name), the element reference or coordinates, the typed text with the redaction service applied, and the approval id when there is one. A screenshot is recorded as its SHA-256 and size, never as the image, because a screen can show anything.

## Declarative versus imperative

`ComputerTarget` is declared in the register, and the AI feature is seed data. The browser, the tools, the classification floor and the approval handling are runtime behaviour and stay in code.

## Seed data

- AI feature `computer-use`: name "Computer use", description "An agent operates a desktop application on a registered machine by looking at its screen and clicking.", `riskCategory: high`, `lifecycle: disabled`.
- `ComputerTarget` "Werkplek Burgerzaken 03" for "Gemeente Voorbeeld": `source` an integriq source "wz-burgerzaken-03" at `https://rc-burgerzaken-03.intern.gemeente-voorbeeld.nl`, key `YOUR_API_KEY_HERE` in the broker, `applications` "Suite4Sociaal Domein, versie 12", `enabled: false`.

## Risks

- A page or a screen tells the agent to do something else. Mitigation: untrusted delimiters and the guardrail input filter for pages; every computer action before a reviewer who sees the screen.
- A click that submits for real. Mitigation: click and fill are destructive, default-denied and gated under the organisation's policy; computer actions are always gated.
- Screen content reaches a model provider. Mitigation: the feature is high-risk with a DPO acknowledgement, and the provider's residency and data-use checks apply to every turn.
- The remote-control service on the machine is exposed. Mitigation: it is reached only through an integriq source on the organisation's network, with its key in the broker.

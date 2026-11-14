---
kind: code
depends_on: [tools-code-sandbox, hermiq-runner-git-capability]
---

# Proposal: tools-browser-and-computer-use

## Summary

An agent owner can grant an agent a headless browser that runs in the code sandbox app: the agent opens a page, reads it, clicks and fills in forms, and every page reaches the model as untrusted content behind the organisation's guardrails. An organisation can also register a Windows machine it runs itself, for an application that has no API. An agent then looks at that machine's screen and clicks and types on it, through integriq, with every action passing the approval gate, and only after the privacy officer has acknowledged computer use as a high-risk AI feature.

## Why

Two rows, decided in the OpenSpec pass of 2026-09-27.

| row | own rating | decision |
|---|---|---|
| `hermiq:dm-browser-use` | no | build: changelog demand and three competitors rate yes |
| `hermiq:dm-computer-use` | no | build: changelog demand and two competitors rate yes |

Demand and competitor cells rated yes, quoted from the pack:

- `dm-browser-use`: changelog https://github.com/n8n-io/n8n/releases/tag/n8n%402.38.0.
  - hermes-agent v2026.9.24: "tools/browser_tool.py:469-512 browser_navigate, browser_snapshot, browser_click and browser_type tools, plus tools/browser_cdp_tool.py and browser_dialog_tool.py".
  - copilot-studio, docs-only, https://learn.microsoft.com/en-us/microsoft-copilot-studio/computer-use: "computer use (GA May 2026) lets agents automate web and desktop apps by controlling browsers: open pages, click, fill in forms".
  - n8n@2.40.7: "packages/nodes-base/nodes/Airtop/Airtop.node.ts:23 usableAsTool with session, window, interaction, extraction and agent resources (:44-64) driving a cloud browser as an AI Agent tool".
- `dm-computer-use`: changelog https://learn.microsoft.com/en-us/microsoft-copilot-studio/whats-new.
  - hermes-agent v2026.9.24: "tools/computer_use_tool.py:14-24 registers computer_use: desktop control via cua-driver on macOS, Windows and Linux with any tool-capable model; ... tools/computer_use/permissions.py approval hook".
  - copilot-studio, docs-only, https://learn.microsoft.com/en-us/microsoft-copilot-studio/configure-where-computer-use-runs: "computer use GA (May 2026) automates web and desktop apps by controlling them; runs on a hosted Cloud PC or the customer's own machine".

## What hermiq already has

- `hermiq.webFetch` fetches one page over HTTP GET and returns readable text between an untrusted-content delimiter (`lib/Mcp/HermiqToolProvider.php:489-512`, `lib/Service/WebResearch/WebFetchService.php:72`, `:112`). It cannot run scripts, click or submit.
- One egress policy for everything that leaves the instance on an agent's behalf: `WebResearchEgressGuard::assertSafe()` (`lib/Service/WebResearch/WebResearchEgressGuard.php:112`), also answering the llm-runner's egress proxy per connection (`lib/Controller/EgressAuthorizeController.php`, route `appinfo/routes.php:725`).
- Guardrail input filters with a prompt-injection action (`lib/Service/GuardrailPolicyService.php:299` `filterInput()`, `:346` `classifyTool()`).
- The approval gate for tool calls and a run-scoped pre-authorisation form specified by the open change `hermiq-runner-git-capability` (requirement "Write-shaped tools route through the approval gate, with a run-scoped pre-authorisation form").
- AI features with a DPO acknowledgement before a high-risk feature can be enabled (`openspec/specs/ai-feature-governance/spec.md`, requirement "Enabling a high-risk feature is blocked until the DPO acknowledges it"), and the tool gate that a grant alone cannot pass (`lib/Service/NcNative/MailReadService.php:79`, `:363-395`).
- Nothing drives a browser or a desktop; the llm-runner denies the CLI's own web tools (`exapp/llm-runner/src/providers.js:140-184`), and earlier changes named a browser as sandbox work (`openspec/changes/archive/2026-07-14-web-research-tool/proposal.md:88-90`).

## What this change builds

1. A browser service in the code sandbox app: headless Chromium, one browser context per conversation, reaching the web only through an egress proxy that asks hermiq's existing policy about every connection.
2. Browser tools: `hermiq.browserOpen`, `hermiq.browserRead`, `hermiq.browserClick`, `hermiq.browserFill`. Pages come back as a text snapshot with element references, delimited as untrusted and passed through the guardrail input filter.
3. Clicking and filling are write-classified, default-denied and approval-gated under the organisation's policy; the agent never types into a password field.
4. A `ComputerTarget` an admin registers: a Windows machine the organisation runs, reached through an integriq source.
5. Computer tools: `hermiq.computerScreenshot`, `hermiq.computerClick`, `hermiq.computerType`, `hermiq.computerKey`, offered only to models that accept images, behind the high-risk AI feature `computer-use`.
6. Every computer action through the approval gate, per action or with a run-scoped pre-authorisation of at most 15 minutes on one machine.
7. The run record: every browser and computer action with its target, and for screenshots a hash, never the image.

## Out of scope

- A hosted browser or a hosted desktop pool. The browser runs in the organisation's own sandbox app, the desktop is the organisation's own machine.
- The remote-control service on the Windows machine. The organisation installs it; hermiq specifies the small HTTP contract it must answer.
- Logging in to websites with the user's credentials. The browser has no cookies of the user and no password manager.

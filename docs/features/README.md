# Hermiq features

One page per thing you can do with Hermiq. Each page says what the surface is for, how
to reach it, and which endpoints sit behind it.

The `Spec` column names the capability in `openspec/specs/`. That is the contract the
code is written against, so when a page and a spec disagree, the spec wins and the
difference is a bug worth filing.

## What you operate

| Page | What you do there | Spec |
|------|-------------------|------|
| [Dashboard](dashboard.md) | See how your agents are doing | `dashboard-page` |
| [Chat](chat.md) | Talk to an agent | `talk-chat-bridge` |
| [Agents](agents.md) | Define an agent and give it a job | `agent-management-ui` |
| [Store](store.md) | Install an agent or a skill someone else built | `skills-marketplace` |
| [Approvals](approvals.md) | Approve or refuse what an agent wants to do | `human-approval-gate` |
| [Runs](runs.md) | Read what every agent actually did | `run-analytics` |
| [Memory](memory.md) | Curate what an agent remembers | `agent-memory` |
| [Skills](skills.md) | Give an agent a new ability | `skills-catalog` |
| [MCP tools](mcp-tools.md) | Decide which tools an agent may call | `agent-tool-governance` |
| [Evaluations](evaluations.md) | Measure whether a skill helps | `agent-evals` |

## What you govern

Hermiq is built for organisations that have to account for their AI. These surfaces
live under Settings.

| Page | What you do there | Spec |
|------|-------------------|------|
| [Flows](flows.md) | Draw what happens when, and let an agent run inside it | `flow-authoring`, `flow-canvas` |
| [Guardrail policy](guardrail-policy.md) | Say which actions need a human first | `agent-guardrails` |
| [Algorithm register](algorithm-register.md) | Publish a high-risk feature to the Algoritmeregister | `algoritmeregister-publication` |
| [Compliance](compliance.md) | Track controls and record incidents | `compliance-control-packs` |
| [AI oversight](ai-oversight.md) | Review advisory decisions an agent made | `ai-oversight-advisory-approvals` |
| [Tenant ops](tenant-ops.md) | Set budgets and quotas per organisation | `multi-tenant-ops` |

## How the answers reach you

| Page | What it covers | Spec |
|------|----------------|------|
| [Talk delivery](talk-delivery.md) | Send a run's output to a Talk room | `talk-delivery` |
| [Speech](speech.md) | Dictate to an agent and hear it reply | `speech-services` |

## Standards this is built against

Hermiq is written for Dutch public bodies, so the governance surfaces above are not
optional extras. They exist because these rules do.

| Standard | Scope | Where it lands |
|----------|-------|----------------|
| EU AI Act | Risk classification and duties for AI systems | AI feature register, algorithm register |
| [Algoritmekader](https://minbzk.github.io/Algoritmekader/) | Dutch government guidance for responsible algorithms | Algorithm register publication |
| AVG / GDPR | Lawful basis, data minimisation, the right to an explanation | Redaction, audit trail, oversight |
| [Digitoegankelijk EN 301 549 / WCAG 2.1 AA](https://www.forumstandaardisatie.nl/open-standaarden/digitoegankelijk) | Accessibility of public digital services | Every screen |
| Archiefwet | Retention and disposal of government records | Run audit log, OpenRegister audit trail |

## Where the rest is written down

58 capabilities have a spec in `openspec/specs/`. The pages above cover the ones you
operate directly. For anything else, read the spec: it is the contract, and it is
kept current because the gates check it.

Start with [the dashboard](dashboard.md) if Hermiq is new to you.

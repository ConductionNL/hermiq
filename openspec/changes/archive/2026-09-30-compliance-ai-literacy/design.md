# Design: compliance-ai-literacy

Kind: code. Size M. Row `hermiq:td-ai-literacy`.

## Context at development db6b74dc

- Walkthrough: `src/manifest.json:1650-1760`, `walkthrough.tours[0]` `hermiq:getting-started`, trigger `first-visit`, steps with `target.kind: page` and `advanceOn`; completion stored under `completionConfigKey: walkthrough_completed_version`.
- Compliance: `ComplianceService::computeControlStatus()` (`lib/Service/ComplianceService.php:293-303`) maps six `evidenceSource` values to existing services and returns `unevidenced` for unknown ones; controls are seeded idempotently by `frameworkSlug` and `controlId` (`lib/Repair/SeedComplianceControls.php:161,235`). The dashboard is `/compliance` (`src/manifest.json`, page `Compliance`).
- Run entry points for a required-course check: the three seams named in `agents-switch-off-and-stop` D1 (`Engine::processMessage()`, `ScheduleService::runAgentAsOwner()`, `AssistantService::converse()`); only the interactive ones have a person to ask.
- Organisation admin rule: `TenantControlController::mayAdminister()` (`lib/Controller/TenantControlController.php:173`).

## D1. Lessons as seeded objects

New schema `LiteracyLesson`: `slug`, `order`, `title`, `body` (Markdown), `checkQuestion`, `checkOptions`, `checkAnswer`, `locale`, `version`. Six lessons are seeded in English and Dutch:

1. What an agent is and what it is not.
2. Answers can be wrong: why, and how to spot it.
3. Check the sources before you use an answer.
4. What not to put in a chat: personal data and secrets.
5. When a person has to decide: approvals and escalation.
6. Your rights and the organisation's duties under the AI Act, in plain words.

Admins may edit the text; an edit raises `version`, so a person who finished the old version is asked the changed lesson again.

## D2. The page and the tour

A page "Working with AI" (`/ai-literacy`) lists the lessons with done or not done. Each lesson opens with its text and the check question; a right answer records completion, a wrong answer shows why and lets the person try again. A second tour `hermiq:ai-literacy` walks through the chat's sources, the "Written by AI" label and the approval inbox on the real pages, and is offered after the Getting started tour.

## D3. Completion as evidence

New schema `LiteracyCompletion`: `userId`, `lessonSlug`, `lessonVersion`, `completedAt`, organisation-scoped. An admin view on the page shows completion per person and per group, with a CSV export. `ComplianceService` gets a seventh evidence source, `ai-literacy-completion`: `met` when every active user of the organisation who used an agent in the last 90 days completed all current lessons, `partial` above 0, else `gap`, with the numbers in the detail. `SeedComplianceControls` adds the EU AI Act control `art.4` "AI literacy" with that source.

## D4. Required before first use

An organisation setting `aiLiteracyRequired` (default off). When on, starting a chat, a run by hand or a Talk session with an agent answers, for a person who has not finished the current lessons, "Finish the short course Working with AI first." with a link. Scheduled and flow runs are not blocked: they run as their owner, who needed the course to set them up.

## Declarative versus imperative

| behaviour | path | why |
|---|---|---|
| lessons, completions | declarative, schemas and seed | plain data |
| completion count per organisation | declarative, `x-openregister-aggregations` on `LiteracyCompletion` if the dashboard can read it, else the evidence service counts | ADR-031 aggregation first |
| the article 4 status | imperative, one new evidence source | same seam as the six existing |
| the required-course check | imperative, a guard at the interactive run entry | a lifecycle guard |

## Seed data

The six lessons above in `en` and `nl`, and the control `eu-ai-act/art.4`. Example check for lesson 3: question "An agent gives you a figure without a source. What do you do?", options "Use it, the agent is usually right", "Check the figure in the source system before you use it", "Ask the agent to repeat it", answer the second.

## Risks

- A course people click through. Mitigation: a check question per lesson and a re-ask after content changes; the control reports honestly as partial.
- Blocking a person who needs help now. Mitigation: the requirement is off by default and each lesson takes a few minutes.

## As built (30 Sep 2026, stacked on models-no-training-guarantee)

- Status words: the compliance dashboard's existing vocabulary is `satisfied`, `partial` and `unevidenced`, so "met" reads `satisfied` and "gap" reads `unevidenced`. The detail carries the counts: "40 of 50 people who used an agent in the last 90 days completed the course."
- "Used an agent in the last 90 days" is a chat session (`agentsession`) with that person's `userId`, updated in the last 90 days, in the organisation.
- A lesson counts as done in whichever language it was read: the guard and the report ask whether each lesson has a completion at least as new as that lesson's current version in the completion's language. A Dutch reader who finished in Dutch is not stopped by the English text.
- The requirement lives on the organisation's `TenantControl` (`aiLiteracyRequired`), switched on the Working with AI page by an instance admin or the organisation's owner. It guards `ChatController`, `ChatStreamController`, `AssistantService::converse()`, `TalkTurnService::runTurn()` (the Talk turn, which tells the room) and `RunNowController` (a run by hand). `ScheduleService` and flows never call it. `Engine::processMessage()` is not guarded, because scheduled runs pass through it too.
- The services are split as `LiteracyCourse` (lessons, answers, completion), `LiteracyRequirement` (the switch, the guard, the admin rule) and `LiteracyReport` (the admin report, CSV, article 4 evidence).
- The tour `hermiq:ai-literacy` is declared with trigger `manual`. The walkthrough engine (nextcloud-vue `useWalkthrough`) has no trigger for "after another tour" and no way for a page to start a tour, so "offered once after Getting started" needs a library change; drafted for Ruben in `for-ruben/nextcloud-vue-tour-after-tour.md`. The page itself teaches the same three points.

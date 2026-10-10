# provider-data-use Specification

**Status**: implemented
**Scope**: hermiq
**OpenSpec changes**:
- `openspec/changes/archive/2026-09-30-models-no-training-guarantee/` (**DONE**, row `td-no-training`)

## Purpose
A provider carries an administered statement of what it does with the data it is sent, an organisation can require providers that never train on its data, and every run records the term in force. Row `hermiq:td-no-training`.

## Requirements

### Requirement: A configured provider states what it does with data (REQ-NOTRAIN-001)

Hermiq MUST let an admin declare for each configured provider a data use of `zero-retention`, `no-training` or `may-train`, with a terms reference. A provider without a declaration MUST read `undeclared`. Hermiq MUST NOT infer the data use from the provider's name, host or model.

#### Scenario: An admin records the term for Anthropic
- GIVEN an admin in Admin settings, LLM provider, Anthropic
- WHEN they open "What it does with your data", choose "Never trains on your data", type "Anthropic commercial terms, checked 2026-09-01" and save
- THEN the provider shows "Never trains on your data" with the reference, the admin's name and the date

#### Scenario: The residency gets a screen too
- GIVEN an admin on the same form
- WHEN they open "Where it runs", choose "EU" and type "Frankfurt"
- THEN the residency is saved through the existing provider residency endpoint, and the AI feature register shows "EU, Frankfurt" for features on that provider

### Requirement: An organisation can require providers that never train on its data (REQ-NOTRAIN-002)

A `ModelPolicy` MAY set `requireNoTraining`. When the effective policy sets it, hermiq MUST refuse, before any request is sent, every run on a provider whose declaration is not `no-training` or `zero-retention`. The check MUST run for every run, whether or not the run names an AI feature, and MUST name the step `data-use` in its refusal.

#### Scenario: An organisation admin switches the requirement on
- GIVEN an organisation admin on Tenant operations, Model policy, with `openai` allowed and undeclared
- WHEN they switch on "Only use providers that never train on our data"
- THEN the section lists `openai` under "Runs on this provider will be refused." before they save

#### Scenario: A scheduled run on an undeclared provider is refused
- GIVEN organisation "Gemeente Voorbeeld" with `requireNoTraining` on, and an agent whose run resolves to `openai`, which is undeclared
- WHEN the agent's schedule fires
- THEN no request reaches OpenAI, and the run is recorded as failed with "Refused by the data-use check" naming the organisation and the provider
- @e2e exclude a schedule firing is a background job; covered by ProviderDataUseTest::testTheGateRefusesAProviderThatMayTrain and ProviderFactoryTest::testTheDataUseStepRefusesARunWithoutAFeature

#### Scenario: A chat user reads why
- GIVEN the same organisation and a chat agent on `openai`
- WHEN a case handler sends a message
- THEN the chat shows "This assistant cannot answer: your organisation only allows AI providers that never train on its data."
- @e2e exclude reaching the refusal needs a configured chat provider on the test instance; the message is covered by ChatControllerTest::testARunRefusedOnDataUseTellsThePersonWhy and the refusal by ProviderFactoryTest::testTheDataUseStepRefusesARunWithoutAFeature

### Requirement: Every run records the data-use term in force (REQ-NOTRAIN-003)

Hermiq MUST copy the provider's data use and terms reference onto every run's provider disclosure at run time, including runs that name no AI feature. A later change to the declaration MUST NOT change what an earlier run shows.

#### Scenario: A privacy officer answers a tender question
- GIVEN runs from last month on `anthropic`, declared `no-training` at the time
- WHEN a privacy officer opens one on the Runs page after the declaration was changed to `may-train`
- THEN the run still shows "Never trains on your data" with the reference that was in force
- @e2e exclude needs a month of recorded runs; the copy onto the run record is covered by ScheduleServiceTest::testTheRunRecordKeepsTheProviderDisclosure and the Runs row by AnalyticsServiceTest::testARunRowShowsTheDataUseTermInForce

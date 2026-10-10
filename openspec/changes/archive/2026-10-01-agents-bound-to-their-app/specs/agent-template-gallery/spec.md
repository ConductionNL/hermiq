# agent-template-gallery Specification

**Status**: proposed
**Scope**: hermiq
**OpenSpec changes**:
- agents-bound-to-their-app

## Purpose

Let an installed app offer an agent template for itself, reviewed before use. Row `hermiq:ag-app-slug`, and shillinq's `platform-help-agent` question.

## ADDED Requirements

### Requirement: An installed app can offer an agent template for itself (REQ-APPAG-005)

The system MUST dispatch `CollectAgentTemplatesEvent` on install, on upgrade and from the Store's "Check apps for templates" action. A package an app offers MUST be imported quarantined and scanned, with `source` `app`, the offering app in `offeredBy`, and the reason "Offered by the app <appId>. Review before use." An unchanged package MUST NOT be imported twice; a changed package MUST replace the earlier template and send it back to review. An offer naming an app that is not installed, or a package without a name, MUST be refused without stopping the other offers. An agent created from it MUST carry the offering app as its `applicationSlug`.

#### Scenario: An admin approves a help agent a finance app offers
- GIVEN an installed app that offers the template "Finance helper" through the event
- WHEN an organisation admin opens the Store after install
- THEN "Finance helper" is listed as quarantined with the reason "Offered by the app shillinq. Review before use.", and after approval "Use this template" creates an agent tied to that app

#### Scenario: Collecting twice creates no duplicate
- GIVEN an app whose offered package has not changed
- WHEN the repair step runs again on upgrade
- THEN the Store still holds one "Finance helper" template
- @e2e exclude repair step, covered by PHPUnit

# retrieval-scope Specification

**Status**: proposed
**Scope**: hermiq
**OpenSpec changes**:
- memory-retrieval-scope

## Purpose

An agent owner limits which documents the agent searches, and an organisation can hold an agent to documents it has validated, with a refusal decided by hermiq. Rows `hermiq:dm-metadata-filter` and `hermiq:td-validated-sources`.

## ADDED Requirements

### Requirement: An owner can limit file retrieval by owner, file name and modified date (REQ-RSCOPE-001)

Hermiq MUST let an agent owner set file filters on owner (users or groups), file name pattern, and modified after and before. Hermiq MUST resolve the filters through Nextcloud's file search as the person in the session and MUST pass the result as the scope of the vector search. An empty result MUST mean that no file is searched.

#### Scenario: A legal advisor limits the agent to recent team documents
- GIVEN an agent owner at the Juridische Zaken team
- WHEN they set the owner filter to group `juridische-zaken` and modified after 1 January 2025 on the agent form, and a colleague asks about the mandate register
- THEN the answer's sources are only files owned by that group's members and changed in 2025 or later
- @e2e exclude needs an indexed vector backend; covered by PHPUnit on the resolver and a live check

### Requirement: Validated documents carry a restricted Nextcloud tag (REQ-RSCOPE-002)

Hermiq MUST let an administrator choose the system tag that marks a document as validated. Hermiq MUST NOT keep a validated status of its own, and MUST leave who may assign the tag to Nextcloud's tag permissions.

#### Scenario: An administrator sets up validation
- GIVEN a Nextcloud administrator on hermiq's admin settings
- WHEN they choose "Create the Validated tag" and restrict it to the group `informatiebeheer`
- THEN the tag exists as a restricted tag, hermiq's setting names it, and a member of another group cannot assign it in Files
- e2e: `tests/e2e/spec-coverage/retrieval-scope.spec.ts`

### Requirement: An agent in validated mode refuses without asking the model when nothing validated matches (REQ-RSCOPE-003)

When an agent's "Answer only from validated documents" is on, hermiq MUST search only files carrying the validated tag, MUST switch off object search and all tools for the turn, and MUST ignore session settings that would widen the scope. When no validated passage reaches the agent's similarity floor, hermiq MUST store the agent's abstention message as the answer and MUST NOT call the model.

#### Scenario: A citizen question outside the validated documents
- GIVEN the agent "Informatiepunt Veiligheidsregio" in validated mode, with no validated document about fireworks permits
- WHEN a call centre employee asks "Mag ik vuurwerk afsteken op Koningsdag?"
- THEN the answer is "Dit kan ik niet beantwoorden op basis van de gevalideerde documenten. Neem contact op met het Klantcontactcentrum.", the line "No validated document matched this question. The assistant did not answer from other knowledge." is shown, and no request reached the model
- @e2e exclude proving no provider call needs a request counter on the provider; covered by PHPUnit on the engine with a fake driver

#### Scenario: A session cannot switch objects back on
- GIVEN an agent in validated mode
- WHEN a person sends a turn with `includeObjects: true`
- THEN object search does not run for that turn
- @e2e exclude a crafted request body; covered by PHPUnit and Newman

### Requirement: Each answer records the scope it was given (REQ-RSCOPE-004)

Hermiq MUST record on each assistant turn the file filters applied, whether validated mode was on, how many passages passed the floor, the best similarity found, and whether hermiq abstained without calling the model.

#### Scenario: A reviewer checks an abstention
- GIVEN an abstained answer in a session
- WHEN a quality officer reads the session
- THEN the turn shows that validated mode was on, that no passage passed the floor of 0.55, and that the best match scored 0.41
- e2e: `tests/e2e/spec-coverage/retrieval-scope.spec.ts`

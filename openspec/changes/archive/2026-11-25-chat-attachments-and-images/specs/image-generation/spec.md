# image-generation Specification

**Status**: proposed
**Scope**: hermiq
**OpenSpec changes**:
- chat-attachments-and-images

## Purpose

A person or an agent creates an image from a description, and the image lands in Files marked as agent-authored. Rows `hermiq:ch-image-gen` and the answer half of `hermiq:dm-image-chat`.

## ADDED Requirements

### Requirement: Images are created through Nextcloud TaskProcessing (REQ-CIMG-001)

Hermiq MUST create images by running a Nextcloud TaskProcessing text-to-image task for the requesting person. When no text-to-image provider is available, hermiq MUST NOT show the chat action and MUST NOT offer the tool. Hermiq MUST NOT call an outside image API directly.

#### Scenario: No provider, no button
- GIVEN an instance with no text-to-image provider installed
- WHEN a person opens `/chat`
- THEN the composer menu shows no "Create an image" item, and the agent's tool catalog lists no `hermiq.generateImage`
- e2e: `tests/e2e/spec-coverage/image-generation.spec.ts`

### Requirement: A created image is saved in Files and marked as agent-authored (REQ-CIMG-002)

Hermiq MUST save each created image in the requesting person's Files and MUST tag it "Agent authored" in the same operation. When the tag cannot be applied, hermiq MUST delete the file and report failure. The run trace MUST record the file id and the agent, and MUST NOT record the image.

#### Scenario: A communications advisor asks for an illustration
- GIVEN the image generation feature is acknowledged by the DPO and enabled, and a text-to-image provider is installed
- WHEN a communications advisor chooses "Create an image" and describes "Een fietsenstalling bij station Zwolle in de ochtendzon"
- THEN a PNG appears in their Files under `Hermiq/Generated images`, tagged "Agent authored", and the chat shows it as the answer
- e2e: `tests/e2e/spec-coverage/image-generation.spec.ts`

#### Scenario: A file that cannot be marked is not kept
- GIVEN the system tag cannot be assigned
- WHEN an agent calls `hermiq.generateImage`
- THEN the tool result is an error, and no image is left in the person's Files
- @e2e exclude a tag failure cannot be forced from the browser; covered by PHPUnit with a failing tag mapper

### Requirement: An agent can create an image only with a grant (REQ-CIMG-003)

Hermiq MUST expose image creation as the tool `hermiq.generateImage` with scope `create`, and MUST refuse it to an agent that holds no grant for it. The tool MUST run with the rights of the session's person.

#### Scenario: An agent without the grant asks for an image
- GIVEN an agent whose tool grants do not include `hermiq.generateImage`
- WHEN its owner asks it in chat to draw a floor plan
- THEN the tool is not offered to the model, and the agent answers that it cannot create images
- @e2e exclude depends on model behaviour; covered by PHPUnit on the grant filter

### Requirement: A created image shows in the answer (REQ-CIMG-004)

Hermiq MUST store a created image on the assistant turn as an attachment with origin `generated`. The Chat page MUST show it as a thumbnail that links to the file in Files. The `final` stream event MUST carry the turn's attachments.

#### Scenario: The owner sees the image where they asked for it
- GIVEN an agent with the `hermiq.generateImage` grant
- WHEN its owner asks on `/chat` for "een infographic met de afvalkalender van april"
- THEN the answer shows the image as a thumbnail, and choosing it opens the file in Files
- e2e: `tests/e2e/spec-coverage/image-generation.spec.ts`

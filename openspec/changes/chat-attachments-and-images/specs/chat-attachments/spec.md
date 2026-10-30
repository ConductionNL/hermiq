# chat-attachments Specification

**Status**: proposed
**Scope**: hermiq
**OpenSpec changes**:
- chat-attachments-and-images

## Purpose

A person attaches a Nextcloud file to a chat message and asks about it, and the model reads an image or a PDF natively when it can. Rows `hermiq:ch-attach`, `hermiq:dm-image-chat` and `hermiq:dm-native-pdf`.

## ADDED Requirements

### Requirement: The companion's attach control has a route to call (REQ-CATT-001)

Hermiq MUST register `POST /api/chat/attachments` as an authenticated route that accepts one multipart file in the field `file`. It MUST store the file in the caller's own Files and MUST answer with `path`, `name`, `fileId`, `mimeType` and `size`. It MUST reject a type outside the allowed list or a file above the size cap with HTTP 400 and an `error` message in the caller's language.

#### Scenario: A case handler attaches a quotation from the companion
- GIVEN a municipal case handler with the AI companion open on a zaak page
- WHEN they choose the paperclip and pick `offerte-dakrenovatie-2026.pdf` from their device
- THEN the upload returns 200 with the file's id, the file appears in their Files under `Hermiq/Attachments`, and a chip with the file name shows above the message box
- e2e: `tests/e2e/spec-coverage/chat-attachments.spec.ts`

#### Scenario: An oversized file is refused with a reason
- GIVEN the attachment size cap is 20 MB
- WHEN a person uploads a 35 MB video
- THEN the route answers 400 with the message "This file is larger than 20 MB." and nothing is written to their Files
- @e2e exclude a 35 MB fixture is too large for the browser suite; covered by PHPUnit on the controller

### Requirement: A person can attach a file they already have in Files (REQ-CATT-002)

The Chat page MUST offer "Upload from device" and "Choose from Files" on its attach control. A file chosen from Files MUST be sent on the turn by its file id, without a second copy.

#### Scenario: A policy officer asks about a memo already in Files
- GIVEN a policy officer on `/chat` with a session open
- WHEN they choose "Choose from Files", select `Beleid/nota-warmtetransitie.pdf` and send "Vat de drie hoofdpunten samen"
- THEN the sent turn shows the file as an attachment, and no copy of the file is created in their Files
- e2e: `tests/e2e/spec-coverage/chat-attachments.spec.ts`

### Requirement: An attachment is read as the person who sent it (REQ-CATT-003)

Hermiq MUST store an attachment on a session turn as a file reference only (`fileId`, `name`, `mimeType`, `size`, `origin`). It MUST resolve the file at send time in the Files of the person who sent the turn, never as the agent's acting user. A file that person cannot read MUST be refused with the same message as a missing file. Each attachment MUST pass the AI feature's redaction and residency checks before any content reaches a model.

#### Scenario: A participant cannot attach a colleague's private file by id
- GIVEN a shared session with two participants, and a file only the first participant can read
- WHEN the second participant sends a turn naming that file id
- THEN the turn is refused with "This file is not available to you", and no request reaches the model
- @e2e exclude needs two browser identities and a crafted request body; covered by PHPUnit and a Newman call

#### Scenario: A feature that requires redaction refuses an unredacted attachment
- GIVEN the chat companion feature has requires redaction switched on, and filinq has no redaction recorded for `bezwaarschrift-2026-0412.pdf`
- WHEN a case handler attaches that file and asks a question
- THEN the answer is a refusal naming the file and the missing redaction, and the model is not called
- @e2e exclude needs filinq installed with a recorded redaction state; covered by PHPUnit on the enforcement path

### Requirement: A model that reads images or PDFs natively gets them natively (REQ-CATT-004)

Hermiq MUST let an administrator declare, per provider and model, which of `image` and `pdf` the model reads natively. For a declared model, hermiq MUST send each matching attachment as an image or document part in the provider's own request format. Hermiq MUST NOT infer the capability from a model name.

#### Scenario: A photo of a roof reaches a vision model as an image
- GIVEN an administrator has declared `image` for the model the agent uses
- WHEN a building inspector attaches `dakgoot-noordzijde.jpg` and asks "Zie je lekkage?"
- THEN the provider request carries the photo as an image part next to the question
- @e2e exclude the request body sent to the provider is not visible to a browser; covered by PHPUnit per driver

### Requirement: A model without the capability gets the text, and the person is told (REQ-CATT-005)

For an attachment the model cannot read natively, hermiq MUST use the file's text when it can extract it and MUST leave the file out when it cannot. In both cases the answer MUST carry a notice in plain language that names the file and what was done. The notice MUST travel inside the existing `final` event and MUST NOT add an SSE event type.

#### Scenario: A PDF is read as text by a model without PDF support
- GIVEN the agent's model has no declared `pdf` capability
- WHEN a person attaches `jaarverslag-2025.pdf` and asks for the total costs
- THEN the answer uses the PDF's text, and above it the page shows "This model does not read PDFs directly. hermiq used the text of jaarverslag-2025.pdf instead."
- e2e: `tests/e2e/spec-coverage/chat-attachments.spec.ts`

#### Scenario: An image is not silently dropped
- GIVEN the agent's model has no declared `image` capability
- WHEN a person attaches `plattegrond.png` and asks a question
- THEN the answer is given without the image, and the page shows "This model cannot see images. plattegrond.png was not sent."
- e2e: `tests/e2e/spec-coverage/chat-attachments.spec.ts`

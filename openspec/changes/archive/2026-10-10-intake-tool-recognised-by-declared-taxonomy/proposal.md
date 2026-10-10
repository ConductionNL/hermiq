# An intake tool is recognised by its mark and its declared create taxonomy

## Why

The intake surface may only call a tool that an owning app marks `citizenIntake` and whose id ends in `.create`. Nothing could satisfy both. Curated tools have two-part ids (`dossiq.fileCase`), and hermiq compared the catalogue's `name`, which is the LLM-safe form (`dossiq_fileCase`) with no segments at all. So no app's intake tool reached hermiq.

Decision 177 (Ruben, 10 Oct, Q-dossiq-L6-3): OpenRegister forwards a free-form `annotations` map from `#[McpTool]` (openregister REQ-ATTR-007); hermiq recognises an intake tool by the mark plus `scope: create` and `action: create`, read from `mcpId` instead of the last id segment; dossiq declares `dossiq.fileCase`.

## What changes

- `IntakeToolGrant` qualifies a tool by the mark, `scope: create`, `action: create` and OpenRegister's write classification. The id-suffix rule goes.
- The tool is identified by `mcpId`, then `name`, then `id`; `permits()` accepts the dotted id and the safe alias.
- `call()` reads a refusal inside the result as a refusal. Found on the way: OpenRegister's provider bridge returns what a tool throws as `{isError: true}` inside a result the facade reports as successful, so a refused filing read as filed.

The intent stays: a claim alone is not enough, and the surface can still only create.

## Impact

- Spec: `conversational-intake` (one requirement added).
- Code: `lib/Service/Intake/IntakeToolGrant.php`. Tests: `tests/Unit/Service/Intake/IntakeToolGrantTest.php` (new), `IntakeServiceTest` fixture.
- Needs openregister#4546 and the annotations PR on the instance before any curated tool carries a mark.

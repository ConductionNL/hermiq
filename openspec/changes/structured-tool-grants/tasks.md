# Tasks: structured-tool-grants

## Implementation Tasks

### Task 1: Put the legacy grant grammar in one codec
- **spec_ref**: `openspec/changes/structured-tool-grants/specs/structured-tool-grants/spec.md`
- **files**: `lib/Service/Engine/ToolGrantCodec.php`
- **acceptance_criteria**:
  - `{app}.{subject}.{action}`, `{app}.{camelCaseName}`, snake `verb_subject`, `.*` / `.*:write` wildcards and `?key=value&other=in:a,b` constraints are parsed and formatted in exactly one place
  - No consumer splits a tool id itself

- [x] Extract the grammar into `ToolGrantCodec`
  - Done, and moved to OpenRegister by ADR-099 §5: `OCA\OpenRegister\Service\Capability\ToolGrantCodec` (`ToolGrantCodecTest` there). hermiq reads it through `ToolGrantSet`; `tests/bootstrap.php` loads the real OpenRegister source for the fifteen hermiq test classes that exercise it.

### Task 2: Store grants as a structure that keeps the catalogue's own tool id
- **spec_ref**: `openspec/changes/structured-tool-grants/specs/structured-tool-grants/spec.md`
- **files**: `lib/Service/Engine/ToolGrantSet.php`
- **acceptance_criteria**:
  - Grants persist as `app → subject → action`, whose leaf carries the tool id verbatim
  - `hermiq.listFiles` round-trips as `hermiq.listFiles`, never as `hermiq.file.list`
  - One action can hold several entries, so two grants differing only by constraint both survive

- [x] Add `ToolGrantSet` with `fromStored()` / `toStored()`
  - Done in OpenRegister (`Service/Capability/ToolGrantSet`, `ToolGrantSetTest`): `fromGrantStrings()` appends, so two constrained grants for one action both survive, and each leaf keeps the catalogue id verbatim. Storage stays a list (`toGrantStrings()`), as the spec's "Where the structure lives" section records.

### Task 3: Accept both shapes at one boundary
- **spec_ref**: `openspec/changes/structured-tool-grants/specs/structured-tool-grants/spec.md`
- **files**: `lib/Service/Engine/ToolGrantResolver.php`, `lib/Controller/ToolOversightController.php`
- **acceptance_criteria**:
  - Shape detection uses `array_is_list()`, so a structured map with a numeric key is not read as a legacy list
  - A legacy list is passed through, not round-tripped, because regrouping reorders what `baseToolIds()` promises
  - `sanitizeGrants()` is the single boundary

- [x] Route both shapes through `sanitizeGrants()`
  - Done: the one boundary is `ToolGrantSet::fromStored()`, which uses `array_is_list()` and passes a legacy list through `fromGrantStrings()` without regrouping; hermiq's writes go through `ToolGrantWrites::storableGrants()` (`ToolOversightControllerTest`), its reads through `GrantWaiverAudit`.

### Task 4: Make the frontend read the structured shape without dropping constraints
- **spec_ref**: `openspec/changes/structured-tool-grants/specs/structured-tool-grants/spec.md`
- **files**: `src/api/toolTaxonomy.js`, `src/components/ToolGrantMatrix.vue`
- **acceptance_criteria**:
  - `flattenGrants()` yields every grant from either shape
  - Constraints survive the flatten — dropping one widens a grant from one flow to every flow

- [x] Add `flattenGrants()` and `grantStringFor()`
  - Done in `src/api/toolTaxonomy.js`; `tests/grant-flatten.spec.js` covers both shapes and that a constraint survives the flatten.

### Task 5: Verify an unmodified save through the browser
- **spec_ref**: `openspec/changes/structured-tool-grants/specs/structured-tool-grants/spec.md`
- **files**: `tests/e2e/tool-grant-matrix.spec.ts`
- **acceptance_criteria**:
  - Opening the matrix and saving without changing anything leaves the stored grants unchanged in meaning
  - The assertion checks the expected grants, not merely that the stored value is non-empty
  - This is verified in a browser, not only in unit tests — the codec passing is not evidence the round trip does

- [ ] Cover the untouched-save round trip
  - Written, not run here: the last describe block of `tests/e2e/tool-grant-matrix.spec.ts` seeds an untouched grant and two constrained grants for one tool, saves the matrix after ticking one other cell, and asserts all three are stored verbatim. Needs a running Nextcloud.

## Quality

- Unit tests cover both stored shapes, the numeric-key case, and two constrained grants for one tool
- `composer check:strict` and `npm run check:specs` pass

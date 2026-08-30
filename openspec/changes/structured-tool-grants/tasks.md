# Tasks: structured-tool-grants

## Implementation Tasks

### Task 1: Put the legacy grant grammar in one codec
- **spec_ref**: `openspec/changes/structured-tool-grants/specs/structured-tool-grants/spec.md`
- **files**: `lib/Service/Engine/ToolGrantCodec.php`
- **acceptance_criteria**:
  - `{app}.{subject}.{action}`, `{app}.{camelCaseName}`, snake `verb_subject`, `.*` / `.*:write` wildcards and `?key=value&other=in:a,b` constraints are parsed and formatted in exactly one place
  - No consumer splits a tool id itself

- [ ] Extract the grammar into `ToolGrantCodec`

### Task 2: Store grants as a structure that keeps the catalogue's own tool id
- **spec_ref**: `openspec/changes/structured-tool-grants/specs/structured-tool-grants/spec.md`
- **files**: `lib/Service/Engine/ToolGrantSet.php`
- **acceptance_criteria**:
  - Grants persist as `app → subject → action`, whose leaf carries the tool id verbatim
  - `hermiq.listFiles` round-trips as `hermiq.listFiles`, never as `hermiq.file.list`
  - One action can hold several entries, so two grants differing only by constraint both survive

- [ ] Add `ToolGrantSet` with `fromStored()` / `toStored()`

### Task 3: Accept both shapes at one boundary
- **spec_ref**: `openspec/changes/structured-tool-grants/specs/structured-tool-grants/spec.md`
- **files**: `lib/Service/Engine/ToolGrantResolver.php`, `lib/Controller/ToolOversightController.php`
- **acceptance_criteria**:
  - Shape detection uses `array_is_list()`, so a structured map with a numeric key is not read as a legacy list
  - A legacy list is passed through, not round-tripped, because regrouping reorders what `baseToolIds()` promises
  - `sanitizeGrants()` is the single boundary

- [ ] Route both shapes through `sanitizeGrants()`

### Task 4: Make the frontend read the structured shape without dropping constraints
- **spec_ref**: `openspec/changes/structured-tool-grants/specs/structured-tool-grants/spec.md`
- **files**: `src/api/toolTaxonomy.js`, `src/components/ToolGrantMatrix.vue`
- **acceptance_criteria**:
  - `flattenGrants()` yields every grant from either shape
  - Constraints survive the flatten — dropping one widens a grant from one flow to every flow

- [ ] Add `flattenGrants()` and `grantStringFor()`

### Task 5: Verify an unmodified save through the browser
- **spec_ref**: `openspec/changes/structured-tool-grants/specs/structured-tool-grants/spec.md`
- **files**: `tests/e2e/tool-grant-matrix.spec.ts`
- **acceptance_criteria**:
  - Opening the matrix and saving without changing anything leaves the stored grants unchanged in meaning
  - The assertion checks the expected grants, not merely that the stored value is non-empty
  - This is verified in a browser, not only in unit tests — the codec passing is not evidence the round trip does

- [ ] Cover the untouched-save round trip

## Quality

- Unit tests cover both stored shapes, the numeric-key case, and two constrained grants for one tool
- `composer check:strict` and `npm run check:specs` pass

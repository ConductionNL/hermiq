# Test Plan: skill-package-multifile

## Test Cases

### TC-1: A multi-file skill installs with its auxiliary files intact
- **spec_ref**: `openspec/changes/skill-package-multifile/specs/skills-marketplace/spec.md#requirement-a-multi-file-skill-survives-the-install-round-trip-intact`
- **type**: functional
- **persona**: Priya (ZZP developer/integrator) — installs a shared skill and expects it to work
- **preconditions**: A hermiq instance with the skills surface reachable; a directory-form package carrying `references/local-checks.md` and `learnings.md`
- **steps**: Install the package through the marketplace install surface, then open the installed skill's detail page
- **expected result**: The skill lists both auxiliary files by name; `references/local-checks.md` content is byte-identical to what was supplied
- **test command**: `/test-functional`

### TC-2: A single-file package still installs unchanged (regression)
- **spec_ref**: `openspec/changes/skill-package-multifile/specs/skills-marketplace/spec.md#requirement-a-multi-file-skill-survives-the-install-round-trip-intact`
- **type**: regression
- **preconditions**: A package with only a fenced frontmatter block and a body — no `files`
- **steps**: Install it through the same surface
- **expected result**: Install succeeds, `files[]` is empty, frontmatter round-trips byte-for-byte, and the response shape is indistinguishable from the pre-change contract
- **test command**: `/test-regression`

### TC-3: A traversal path is rejected without failing the install
- **spec_ref**: `openspec/changes/skill-package-multifile/specs/skills-marketplace/spec.md#requirement-auxiliary-file-paths-are-validated-on-install`
- **type**: security
- **preconditions**: A package carrying aux entries `../../etc/passwd`, `/etc/shadow`, `refs/../../x.md` alongside one valid `references/ok.md`
- **steps**: Install the package
- **expected result**: Only `references/ok.md` persists; all three unsafe entries are absent and logged as rejected; the request returns 200; nothing is written outside the Skill object
- **test command**: `/test-security`

### TC-4: A dangerous payload hidden in an auxiliary file is caught by the scan
- **spec_ref**: `openspec/changes/skill-package-multifile/specs/skills-marketplace/spec.md#requirement-quarantine--security-scan-on-install`
- **type**: security
- **preconditions**: A package whose body is benign but whose `references/steps.md` carries a pattern the content scanner rates dangerous
- **steps**: Install the package, then attempt one-click approval of the quarantined skill
- **expected result**: The scan report records the dangerous verdict; the skill lands `quarantined`; one-click approval is blocked and only the stricter override can clear it
- **test command**: `/test-security`

### TC-5: Install route contract — optional files parameter
- **spec_ref**: `openspec/changes/skill-package-multifile/contract.md`
- **type**: api
- **preconditions**: Authenticated session against `POST /apps/hermiq/api/skills/marketplace/install`
- **steps**: Post (a) no `files`, (b) `files` as a string, (c) `files` as a valid array
- **expected result**: (a) 200 with `files: []`; (b) 400 `files must be an array`; (c) 200 with entries persisted. Unauthenticated returns 401 in all three
- **test command**: `/test-api`

### TC-6: A 63-file skill parses within the single-file request budget
- **spec_ref**: `openspec/changes/skill-package-multifile/specs/skills-marketplace/spec.md#non-functional-requirements`
- **type**: performance
- **preconditions**: A package modelled on the `create-pr` worst case — 63 aux files across `references/`, `evals/`, plus `learnings.md`
- **steps**: Install it and measure wall time against a single-file install
- **expected result**: Completes in the same request budget; no per-file network or filesystem round trip occurs during parse
- **test command**: `/test-performance`

## Coverage Summary

| Spec requirement | Covered by |
|---|---|
| A multi-file skill survives the install round trip intact | TC-1, TC-2 |
| Auxiliary file paths are validated on install | TC-3 |
| Quarantine + security scan on install (MODIFIED) | TC-4 |
| Install route contract | TC-5 |
| Non-functional — parse performance | TC-6 |

Every scenario in the delta spec maps to at least one case. TC-3 and TC-4 are the two that matter most: they cover the failure modes where the change could make the system *less* safe than before it landed.

## Out of Scope

- Binary/asset auxiliary files — deliberately deferred per proposal Out of Scope; no test asserts binary fidelity.
- Bundling several skills into one repository — belongs to the follow-on bundle change.
- The publish half (`publish()`, `republish()`, `publishFileSelection()`) — unchanged by this work and already covered by existing tests.

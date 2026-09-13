# Design: skills-catalog

## Context

Ports Hermes' agentskills.io-compatible skills to OpenRegister objects. ADR-001 Option C+:
Hermiq owns the catalog + management UX and the Skill objects; OR owns the run loop that
makes an installed skill available during a turn. ADR-003: skills are OR objects. Builds
on `agent-memory`'s per-tenant scoping.

## Decisions

**Byte-for-byte fidelity by preserving the raw frontmatter block.** The spec requires a
serialize→deserialize round trip that reproduces frontmatter + body byte-for-byte. Rather
than parse YAML into a map and re-dump it (which reorders keys / changes quoting and
breaks byte fidelity), the `Skill` stores `frontmatter` as the **raw YAML block string**
exactly as imported. `toPackage()` emits `---\n{frontmatter}\n---\n{body}`; `fromPackage()`
splits on the leading `---`…`---` fence and returns the block verbatim. `name`/
`description` are extracted from the block for browsing/querying but never used to
reconstruct it. This is dependency-free (no Symfony Yaml) so the serializer unit-tests run
in the CI stub env.

**Skill vs. SkillSource.** `Skill` is the installed/importable unit (frontmatter + body +
files + lifecycle). `SkillSource` records an origin (a package, a hub URL, or local) for
future `skills-marketplace` (V2) cross-org sharing — declared now, minimally used.

**Install = association, not copy.** `installOnAgent` appends the agent uuid to the skill's
`installedOn` array (idempotent). The skill stays a single tenant-scoped object; the run
loop reads `installedOn` to decide availability (OR seam). No per-agent skill copies.

**Lifecycle.** `state` is `active|stale|archived` (default `active`). Hermiq exposes the
field + the catalog; the self-improvement transitions (active→stale→archived) are driven
by the agent run loop / a future maintenance job — this change ships the state, not the
transition policy.

**Tenant scoping is native.** All reads/writes run in the caller's session context through
OR `ObjectService`, so `owner`/`organisation` are inherited and RBAC denies cross-tenant
access — same model as `agent-memory`.

## Integration seam (OR-owned, NOT implemented here)

The agent run loop (OR `ChatService`/agent core) would, at run start, read the agent's
installed skills (`Skill` objects whose `installedOn` contains the agent uuid) and inject
their bodies/tools into the turn. Hermiq exposes the catalog + install association + export;
wiring skills INTO an OR agent turn is an OR change (same agent-core seam as
`nc-native-tools`).

## Risks / Trade-offs

- **Raw-frontmatter divergence on structured edits.** [Editing `name`/`description` fields
  without updating the raw block diverges them] → Import/export fidelity is the contract;
  the UI imports/exports whole packages and does not offer structured frontmatter editing.
- **Files not round-tripped in the package string.** [`files[]` are stored but the package
  string only carries frontmatter+body] → agentskills.io auxiliary files are persisted on
  the object; multi-file package (de)serialisation is a follow-up (the round-trip contract
  is scoped to frontmatter+body).

## Open Questions

- **Open — hub publishing.** Cross-org sharing + external agentskills.io hub publishing is
  the V2 `skills-marketplace` spec built on this catalog; `SkillSource` is the seam.
- **RESOLVED — fidelity strategy.** Preserve the raw frontmatter block (no YAML re-dump).

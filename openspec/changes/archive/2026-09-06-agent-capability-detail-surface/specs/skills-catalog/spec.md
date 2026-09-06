## ADDED Requirements

### Requirement: Detach an installed skill from an agent
The system MUST let a user remove a previously-installed skill's association with a specific
agent, symmetrically undoing the install operation: the skill's `installedOn` list MUST no
longer contain the agent's uuid, and the agent's `skillInstalls` allowlist MUST no longer
contain the skill's uuid. Detaching MUST be idempotent — detaching a skill/agent pair that is
not currently associated MUST succeed as a no-op rather than error.

#### Scenario: A user detaches a skill from their agent
- GIVEN a `Skill` object installed on agent X (agent X's uuid is in the skill's `installedOn`)
- WHEN the user detaches that skill from agent X
- THEN the system MUST remove agent X's uuid from the skill's `installedOn`
- AND the system MUST remove the skill's uuid from agent X's `skillInstalls`
- AND agent X's next run MUST NOT have that skill available
@e2e exclude covered by SkillServiceTest::testUninstallFromAgentDesyncsBothDirections (both-sides removal via ObjectService); the DELETE /api/skills/{id}/install/{agentId} route + controller mirror the install endpoint. Newman/Playwright coverage deferred.

#### Scenario: Detaching an already-detached skill/agent pair is a no-op
- GIVEN a `Skill` object that is NOT installed on agent Y
- WHEN the user (or a repeated client request) detaches that skill from agent Y
- THEN the system MUST return success
- AND the skill's `installedOn` and agent Y's `skillInstalls` MUST remain unchanged
@e2e exclude idempotency is covered by SkillServiceTest::testUninstallFromAgentIsIdempotent (agent-side write skipped when already absent). Newman/Playwright coverage deferred.

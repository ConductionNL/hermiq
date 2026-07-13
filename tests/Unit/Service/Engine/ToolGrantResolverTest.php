<?php

/**
 * Unit tests for ToolGrantResolver (agent-tool-governance-and-disclosure,
 * hermiq-prefer-tool-hints).
 *
 * Covers the grant-expansion matrix: exact ids pass through verbatim, a schema
 * wildcard grants read verbs only (default-deny on writes), a `:write` modifier
 * additionally grants write verbs, an explicitly-named write verb is granted
 * alongside a read-only wildcard, an empty `Agent.tools` preserves legacy
 * "all discovered tools allowed" behaviour EXCEPT default-deny still strips
 * classifiable derived write ids — PLUS (hermiq-prefer-tool-hints) declared
 * descriptor hints (`scope`/`destructiveHint`/`readOnlyHint`) take precedence
 * over the verb-suffix heuristic, and a hint-less, non-3-segment id now FAILS
 * CLOSED (classified write/destructive) instead of silently passing as read.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\Engine
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/agent-tool-governance-and-disclosure/tasks.md#task-1
 * @spec openspec/specs/agent-tool-governance/spec.md#scenario-a-declared-hint-overrides-a-conflicting-verb-suffix
 * @spec openspec/specs/agent-tool-governance/spec.md#scenario-a-hint-less-curated-tool-fails-closed
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Engine;

use OCA\Hermiq\Service\Engine\ToolGrantResolver;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the schema-scoped grant expansion + default-deny resolver.
 *
 * @spec openspec/changes/agent-tool-governance-and-disclosure/tasks.md#task-1
 */
class ToolGrantResolverTest extends TestCase
{
    /**
     * A representative derived catalog for `pipelinq.lead` plus one hand-written
     * (non-derived) tool, in the LLPhant-descriptor shape `listTools()` returns.
     *
     * @return array<int, array<string,mixed>>
     */
    private function catalog(): array
    {
        return [
            ['name' => 'pipelinq_lead_search', 'mcpId' => 'pipelinq.lead.search', 'description' => 'Search leads'],
            ['name' => 'pipelinq_lead_get', 'mcpId' => 'pipelinq.lead.get', 'description' => 'Get a lead'],
            ['name' => 'pipelinq_lead_create', 'mcpId' => 'pipelinq.lead.create', 'description' => 'Create a lead'],
            ['name' => 'pipelinq_lead_update', 'mcpId' => 'pipelinq.lead.update', 'description' => 'Update a lead'],
            ['name' => 'pipelinq_lead_delete', 'mcpId' => 'pipelinq.lead.delete', 'description' => 'Delete a lead'],
            ['name' => 'hermiq_sendMail', 'mcpId' => 'hermiq.sendMail', 'description' => 'Send an email'],
        ];

    }//end catalog()

    /**
     * A schema wildcard resolves to read verbs only — write/destructive verbs
     * are excluded (default-deny).
     *
     * @return void
     *
     * @spec openspec/changes/agent-tool-governance-and-disclosure/specs/agent-tool-governance/spec.md#scenario-a-schema-wildcard-grants-read-verbs-only
     */
    public function testWildcardGrantsReadVerbsOnly(): void
    {
        $resolver = new ToolGrantResolver();
        $resolved = $resolver->resolve(grants: ['pipelinq.lead.*'], catalog: $this->catalog());

        sort($resolved);
        $this->assertSame(['pipelinq.lead.get', 'pipelinq.lead.search'], $resolved);

    }//end testWildcardGrantsReadVerbsOnly()

    /**
     * A wildcard plus an explicitly-named write verb includes both the read
     * verbs and the named write verb.
     *
     * @return void
     *
     * @spec openspec/changes/agent-tool-governance-and-disclosure/specs/agent-tool-governance/spec.md#scenario-a-write-tool-is-granted-only-when-named-explicitly
     */
    public function testExplicitWriteVerbGrantedAlongsideWildcard(): void
    {
        $resolver = new ToolGrantResolver();
        $resolved = $resolver->resolve(
            grants: ['pipelinq.lead.*', 'pipelinq.lead.delete'],
            catalog: $this->catalog()
        );

        sort($resolved);
        $this->assertSame(['pipelinq.lead.delete', 'pipelinq.lead.get', 'pipelinq.lead.search'], $resolved);

    }//end testExplicitWriteVerbGrantedAlongsideWildcard()

    /**
     * The `:write` modifier expands a wildcard to read AND write verbs.
     *
     * @return void
     */
    public function testWriteModifierGrantsReadAndWriteVerbs(): void
    {
        $resolver = new ToolGrantResolver();
        $resolved = $resolver->resolve(grants: ['pipelinq.lead.*:write'], catalog: $this->catalog());

        sort($resolved);
        $this->assertSame(
            ['pipelinq.lead.create', 'pipelinq.lead.delete', 'pipelinq.lead.get', 'pipelinq.lead.search', 'pipelinq.lead.update'],
            $resolved
        );

    }//end testWriteModifierGrantsReadAndWriteVerbs()

    /**
     * An exact-id grant (no wildcard) is passed through verbatim — including a
     * write verb named explicitly, and a hand-written non-derived id.
     *
     * @return void
     */
    public function testExactIdGrantsPassThroughVerbatim(): void
    {
        $resolver = new ToolGrantResolver();
        $resolved = $resolver->resolve(
            grants: ['pipelinq.lead.create', 'hermiq.sendMail'],
            catalog: $this->catalog()
        );

        sort($resolved);
        $this->assertSame(['hermiq.sendMail', 'pipelinq.lead.create'], $resolved);

    }//end testExactIdGrantsPassThroughVerbatim()

    /**
     * A wildcard for a schema with NO write verbs in the catalog resolves to
     * only the read verbs actually present — never a fabricated id.
     *
     * @return void
     */
    public function testWildcardOnlyIncludesCatalogPresentVerbs(): void
    {
        $resolver = new ToolGrantResolver();
        $catalog  = [
            ['name' => 'openregister_schemas_search', 'mcpId' => 'openregister.schemas.search'],
        ];

        $resolved = $resolver->resolve(grants: ['openregister.schemas.*'], catalog: $catalog);

        $this->assertSame(['openregister.schemas.search'], $resolved);

    }//end testWildcardOnlyIncludesCatalogPresentVerbs()

    /**
     * An empty `Agent.tools` preserves "all discovered tools allowed" for every
     * derived read id, but strips classifiable derived write/destructive ids
     * (default-deny still applies) — AND (hermiq-prefer-tool-hints) now also
     * strips `hermiq.sendMail`, a hint-less 2-segment id, because it FAILS
     * CLOSED rather than silently passing as read.
     *
     * @return void
     *
     * @spec openspec/changes/agent-tool-governance-and-disclosure/tasks.md#task-1
     * @spec openspec/specs/agent-tool-governance/spec.md#scenario-a-hint-less-curated-tool-fails-closed
     */
    public function testEmptyGrantsAllowsAllExceptDerivedWritesAndFailsClosedOnHintlessNonDerivedIds(): void
    {
        $resolver = new ToolGrantResolver();
        $resolved = $resolver->resolve(grants: [], catalog: $this->catalog());

        sort($resolved);
        $this->assertSame(
            ['pipelinq.lead.get', 'pipelinq.lead.search'],
            $resolved,
            'create/update/delete derived ids must be stripped; the hint-less non-derived hand-written id'
            .' must ALSO be stripped now (fail closed on an unclassifiable id).'
        );

    }//end testEmptyGrantsAllowsAllExceptDerivedWritesAndFailsClosedOnHintlessNonDerivedIds()

    /**
     * An empty `Agent.tools` resolution classifies each id from its OWN
     * descriptor's hints FIRST — a curated (2-segment) tool with
     * `destructiveHint:true` is stripped even though its shape alone would be
     * unclassifiable, and a curated tool with `readOnlyHint:true` survives even
     * though it would otherwise fail closed.
     *
     * @return void
     *
     * @spec openspec/specs/agent-tool-governance/spec.md#scenario-a-declared-hint-overrides-a-conflicting-verb-suffix
     */
    public function testEmptyGrantsClassifiesCuratedToolsFromHints(): void
    {
        $resolver = new ToolGrantResolver();
        $catalog  = [
            ['name' => 'pipelinq_createLead', 'mcpId' => 'pipelinq.createLead', 'destructiveHint' => true],
            ['name' => 'pipelinq_getLeadSummary', 'mcpId' => 'pipelinq.getLeadSummary', 'readOnlyHint' => true],
        ];

        $resolved = $resolver->resolve(grants: [], catalog: $catalog);

        $this->assertSame(
            ['pipelinq.getLeadSummary'],
            $resolved,
            'destructiveHint:true must be stripped even though the id is a curated 2-segment id;'
            .' readOnlyHint:true must survive.'
        );

    }//end testEmptyGrantsClassifiesCuratedToolsFromHints()

    /**
     * A declared `destructiveHint:true` on a 3-segment derived id overrides a
     * read-shaped (`.get`) verb suffix — hints take precedence over the
     * verb-suffix heuristic, not just fill a gap it leaves.
     *
     * @return void
     *
     * @spec openspec/specs/agent-tool-governance/spec.md#scenario-a-declared-hint-overrides-a-conflicting-verb-suffix
     */
    public function testHintOverridesConflictingVerbSuffix(): void
    {
        $resolver = new ToolGrantResolver();
        $catalog  = [
            ['name' => 'pipelinq_lead_get', 'mcpId' => 'pipelinq.lead.get', 'destructiveHint' => true],
        ];

        $resolved = $resolver->resolve(grants: [], catalog: $catalog);

        $this->assertSame([], $resolved, 'destructiveHint:true must win over the ".get" verb suffix.');

    }//end testHintOverridesConflictingVerbSuffix()

    /**
     * Hint-less, isWriteOrDestructive() classification: a 3-segment
     * `{app}.{schema}.{verb}` id still uses the unchanged verb-suffix heuristic
     * (regression parity) — but a 2-segment or bare id, which was previously
     * NEVER classified this way, now FAILS CLOSED (classified write/destructive)
     * per hermiq-prefer-tool-hints.
     *
     * @return void
     *
     * @spec openspec/specs/agent-tool-governance/spec.md#scenario-a-hint-less-curated-tool-fails-closed
     */
    public function testIsWriteOrDestructiveHintlessClassification(): void
    {
        // 3-segment verb-suffix fallback — unchanged (regression).
        $this->assertTrue(ToolGrantResolver::isWriteOrDestructive(id: 'pipelinq.lead.delete'));
        $this->assertTrue(ToolGrantResolver::isWriteOrDestructive(id: 'pipelinq.lead.create'));
        $this->assertTrue(ToolGrantResolver::isWriteOrDestructive(id: 'pipelinq.lead.update'));
        $this->assertFalse(ToolGrantResolver::isWriteOrDestructive(id: 'pipelinq.lead.search'));
        $this->assertFalse(ToolGrantResolver::isWriteOrDestructive(id: 'pipelinq.lead.get'));

        // Hint-less, non-3-segment ids: fail CLOSED (was `false` before hermiq-prefer-tool-hints).
        $this->assertTrue(ToolGrantResolver::isWriteOrDestructive(id: 'hermiq.sendMail'));
        $this->assertTrue(ToolGrantResolver::isWriteOrDestructive(id: 'objects'));

    }//end testIsWriteOrDestructiveHintlessClassification()

    /**
     * Declared descriptor hints take precedence over the id's own shape —
     * `scope`, `destructiveHint`, and `readOnlyHint` are each checked, in that
     * priority order, and can classify a 2-segment (otherwise-unclassifiable) id
     * either way.
     *
     * @return void
     *
     * @spec openspec/specs/agent-tool-governance/spec.md#scenario-a-declared-hint-overrides-a-conflicting-verb-suffix
     */
    public function testIsWriteOrDestructiveHintClassification(): void
    {
        // scope
        $this->assertTrue(ToolGrantResolver::isWriteOrDestructive(id: 'pipelinq.createLead', descriptor: ['scope' => 'create']));
        $this->assertFalse(ToolGrantResolver::isWriteOrDestructive(id: 'pipelinq.getLeadSummary', descriptor: ['scope' => 'read']));

        // destructiveHint
        $this->assertTrue(ToolGrantResolver::isWriteOrDestructive(id: 'pipelinq.createLead', descriptor: ['destructiveHint' => true]));
        $this->assertFalse(ToolGrantResolver::isWriteOrDestructive(id: 'pipelinq.createLead', descriptor: ['destructiveHint' => false]));

        // readOnlyHint
        $this->assertFalse(ToolGrantResolver::isWriteOrDestructive(id: 'pipelinq.getLeadSummary', descriptor: ['readOnlyHint' => true]));
        $this->assertTrue(ToolGrantResolver::isWriteOrDestructive(id: 'pipelinq.getLeadSummary', descriptor: ['readOnlyHint' => false]));

        // A descriptor present but carrying none of the three hint keys falls
        // through to the hint-less rules exactly as if no descriptor were given.
        $this->assertTrue(ToolGrantResolver::isWriteOrDestructive(id: 'hermiq.sendMail', descriptor: ['description' => 'Send an email']));
        $this->assertTrue(ToolGrantResolver::isWriteOrDestructive(id: 'pipelinq.lead.create', descriptor: []));
        $this->assertFalse(ToolGrantResolver::isWriteOrDestructive(id: 'pipelinq.lead.get', descriptor: []));

    }//end testIsWriteOrDestructiveHintClassification()

    /**
     * hasWildcardGrant() detects `.*`/`.*:write` entries and ignores exact ids.
     *
     * @return void
     */
    public function testHasWildcardGrant(): void
    {
        $resolver = new ToolGrantResolver();

        $this->assertTrue($resolver->hasWildcardGrant(grants: ['pipelinq.lead.*']));
        $this->assertTrue($resolver->hasWildcardGrant(grants: ['pipelinq.lead.*:write']));
        $this->assertFalse($resolver->hasWildcardGrant(grants: ['pipelinq.lead.search', 'hermiq.sendMail']));
        $this->assertFalse($resolver->hasWildcardGrant(grants: []));

    }//end testHasWildcardGrant()

    /**
     * Non-string / empty grant entries are dropped rather than fatal.
     *
     * @return void
     */
    public function testNonStringGrantsAreIgnored(): void
    {
        $resolver = new ToolGrantResolver();
        $resolved = $resolver->resolve(grants: [123, '', null, 'hermiq.sendMail'], catalog: $this->catalog());

        $this->assertSame(['hermiq.sendMail'], $resolved);

    }//end testNonStringGrantsAreIgnored()
}//end class

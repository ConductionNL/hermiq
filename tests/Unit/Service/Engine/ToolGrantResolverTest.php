<?php

/**
 * Unit tests for ToolGrantResolver (agent-tool-governance-and-disclosure).
 *
 * Covers the grant-expansion matrix: exact ids pass through verbatim, a schema
 * wildcard grants read verbs only (default-deny on writes), a `:write` modifier
 * additionally grants write verbs, an explicitly-named write verb is granted
 * alongside a read-only wildcard, an empty `Agent.tools` preserves legacy
 * "all discovered tools allowed" behaviour EXCEPT default-deny still strips
 * classifiable derived write ids, and non-derived (2-segment / bare) ids are
 * NEVER classified write/destructive regardless of shape.
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
     * NON-derived id, but strips classifiable derived write/destructive ids
     * (default-deny still applies).
     *
     * @return void
     *
     * @spec openspec/changes/agent-tool-governance-and-disclosure/tasks.md#task-1
     */
    public function testEmptyGrantsAllowsAllExceptDerivedWrites(): void
    {
        $resolver = new ToolGrantResolver();
        $resolved = $resolver->resolve(grants: [], catalog: $this->catalog());

        sort($resolved);
        $this->assertSame(
            ['hermiq.sendMail', 'pipelinq.lead.get', 'pipelinq.lead.search'],
            $resolved,
            'create/update/delete derived ids must be stripped; the non-derived hand-written id must survive.'
        );

    }//end testEmptyGrantsAllowsAllExceptDerivedWrites()

    /**
     * isWriteOrDestructive() only classifies 3-segment `{app}.{schema}.{verb}`
     * ids whose trailing verb is a write verb — a 2-segment id (any shape) is
     * NEVER classified this way, preserving pre-existing whitelist behaviour.
     *
     * @return void
     */
    public function testIsWriteOrDestructiveClassification(): void
    {
        $this->assertTrue(ToolGrantResolver::isWriteOrDestructive(id: 'pipelinq.lead.delete'));
        $this->assertTrue(ToolGrantResolver::isWriteOrDestructive(id: 'pipelinq.lead.create'));
        $this->assertTrue(ToolGrantResolver::isWriteOrDestructive(id: 'pipelinq.lead.update'));
        $this->assertFalse(ToolGrantResolver::isWriteOrDestructive(id: 'pipelinq.lead.search'));
        $this->assertFalse(ToolGrantResolver::isWriteOrDestructive(id: 'pipelinq.lead.get'));
        $this->assertFalse(ToolGrantResolver::isWriteOrDestructive(id: 'hermiq.sendMail'));
        $this->assertFalse(ToolGrantResolver::isWriteOrDestructive(id: 'objects'));

    }//end testIsWriteOrDestructiveClassification()

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

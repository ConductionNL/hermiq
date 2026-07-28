<?php

/**
 * Unit tests for AgentContextBuilder (agent-object-leaf).
 *
 * The declarative, fail-closed context allowlist:
 *   - only allowlisted properties present on the instance are returned;
 *   - an absent/empty allowlist yields an EMPTY context (never the whole object);
 *   - a listed-but-missing property is omitted, not an error;
 *   - an unlisted confidential field is never included;
 *   - `maxLength` caps are applied multibyte-safely.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\Agent
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/hermiq-agent-leaf/specs/agent-object-leaf/spec.md#requirement-declarative-bounded-agent-context-allowlist
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Agent;

use OCA\Hermiq\Service\Agent\AgentContextBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Tests for AgentContextBuilder.
 *
 * @spec openspec/changes/hermiq-agent-leaf/tasks.md#3-declarative-context-allowlist
 */
class AgentContextBuilderTest extends TestCase
{

    /**
     * The system under test.
     *
     * @var AgentContextBuilder
     */
    private AgentContextBuilder $builder;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->builder = new AgentContextBuilder();
    }//end setUp()

    /**
     * Only allowlisted fields reach the agent; an unlisted confidential field never does.
     *
     * @return void
     */
    public function testOnlyAllowlistedFieldsReturned(): void
    {
        $object = [
            'title'       => 'Permit request',
            'status'      => 'open',
            'description' => 'A short description.',
            'bsn'         => '123456789',
            'initiator'   => 'Jane Doe',
        ];
        $config = ['x-openregister-agent-context' => ['title', 'status', 'description']];

        $context = $this->builder->build($object, $config);

        $this->assertSame(['title', 'status', 'description'], array_keys($context));
        $this->assertArrayNotHasKey('bsn', $context);
        $this->assertArrayNotHasKey('initiator', $context);
    }//end testOnlyAllowlistedFieldsReturned()

    /**
     * No allowlist yields an EMPTY context (fail-closed), never the whole object.
     *
     * @return void
     */
    public function testNoAllowlistYieldsEmptyContext(): void
    {
        $object = ['title' => 'x', 'bsn' => '123456789'];

        $this->assertSame([], $this->builder->build($object, []));
        $this->assertSame([], $this->builder->build($object, ['x-openregister-agent-context' => []]));
    }//end testNoAllowlistYieldsEmptyContext()

    /**
     * A listed property missing on the instance is omitted, not an error.
     *
     * @return void
     */
    public function testMissingListedFieldIsOmitted(): void
    {
        $object = ['title' => 'x'];
        $config = ['x-openregister-agent-context' => ['title', 'status', 'deadline']];

        $context = $this->builder->build($object, $config);

        $this->assertSame(['title' => 'x'], $context);
    }//end testMissingListedFieldIsOmitted()

    /**
     * `maxLength` caps truncate strings multibyte-safely; the map allowlist shape works.
     *
     * @return void
     */
    public function testMaxLengthCapMultibyteSafe(): void
    {
        // 5 multibyte characters — a byte-based cut would corrupt them.
        $object = ['description' => 'ëëëëëworld'];
        $config = ['x-openregister-agent-context' => ['description' => ['maxLength' => 5]]];

        $context = $this->builder->build($object, $config);

        $this->assertSame('ëëëëë…', $context['description']);
    }//end testMaxLengthCapMultibyteSafe()

    /**
     * The list-of-{property,maxLength} allowlist shape is accepted.
     *
     * @return void
     */
    public function testEntryObjectAllowlistShape(): void
    {
        $object = ['description' => 'abcdefgh', 'title' => 'T'];
        $config = [
            'x-openregister-agent-context' => [
                ['property' => 'description', 'maxLength' => 3],
                'title',
            ],
        ];

        $context = $this->builder->build($object, $config);

        $this->assertSame('abc…', $context['description']);
        $this->assertSame('T', $context['title']);
    }//end testEntryObjectAllowlistShape()

    /**
     * Empty-string and null values on allowlisted fields are dropped.
     *
     * @return void
     */
    public function testEmptyValuesDropped(): void
    {
        $object = ['title' => '', 'status' => null, 'note' => 'kept'];
        $config = ['x-openregister-agent-context' => ['title', 'status', 'note']];

        $this->assertSame(['note' => 'kept'], $this->builder->build($object, $config));
    }//end testEmptyValuesDropped()
}//end class

<?php

/**
 * Unit tests for AgentTemplateSerializer (agent-template-gallery).
 *
 * Covers: toPackage() emits only the portable fields (never state/source/quarantineReason/
 * scanReport/createdBy, nor agent-template-github-store's githubOwner/githubRepo/publishedAt
 * provenance fields), fromPackage() round-trips those fields, and fromPackage() is
 * tolerant of missing optional fields and malformed JSON (mirrors SkillSerializerTest's
 * lossless-round-trip + tolerant-defaults coverage).
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/agent-template-gallery/tasks.md#task-2-agenttemplateserializer-json-package-deserialisation
 * @spec openspec/changes/agent-template-github-store/specs/agent-template-github-store/spec.md#requirement-the-system-must-record-github-publish-provenance-without-leaking-it-into-packages
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service;

use OCA\Hermiq\Service\AgentTemplateSerializer;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the agent-template-gallery AgentTemplateSerializer.
 *
 * @spec openspec/changes/agent-template-gallery/tasks.md#task-2-agenttemplateserializer-json-package-deserialisation
 */
class AgentTemplateSerializerTest extends TestCase
{

    /**
     * toPackage() emits only the portable fields — never state/source/quarantineReason/
     * scanReport/createdBy.
     *
     * @return void
     *
     * @spec openspec/specs/agent-template-gallery/spec.md#requirement-an-agenttemplate-carries-no-secrets-and-no-tenant-data
     */
    public function testToPackageEmitsOnlyPortableFields(): void
    {
        $serializer = new AgentTemplateSerializer();

        $package = $serializer->toPackage(
            template: [
                'name'              => 'Morning briefing',
                'description'       => 'Summarises your day',
                'category'          => 'productivity',
                'systemPrompt'      => 'Summarise the day ahead',
                'suggestedProvider' => 'ollama',
                'suggestedModel'    => 'qwen2.5',
                'tools'             => ['openregister.searchObjects'],
                'skillRefs'         => [['skillId' => 'skill-1', 'name' => 'Calendar reader']],
                'suggestedSchedule' => ['kind' => 'cron', 'cronExpr' => '0 7 * * *', 'deliver' => 'talk'],
                'version'           => '0.1.0',
                'state'             => 'active',
                'source'            => 'local',
                'quarantineReason'  => 'should never appear',
                'scanReport'        => ['severity' => 'clean'],
                'createdBy'         => 'alice',
                'githubOwner'       => 'acme-council',
                'githubRepo'        => 'morning-briefing-template',
                'publishedAt'       => '2026-01-01T00:00:00+00:00',
            ]
        );

        $decoded = json_decode($package, true);

        $this->assertSame('Morning briefing', $decoded['name']);
        $this->assertSame('ollama', $decoded['suggestedProvider']);
        $this->assertSame(['openregister.searchObjects'], $decoded['tools']);
        $this->assertArrayNotHasKey('state', $decoded);
        $this->assertArrayNotHasKey('source', $decoded);
        $this->assertArrayNotHasKey('quarantineReason', $decoded);
        $this->assertArrayNotHasKey('scanReport', $decoded);
        $this->assertArrayNotHasKey('createdBy', $decoded);
        // agent-template-github-store: publish provenance is never round-tripped through
        // the portable package (design.md/spec.md "record ... without leaking it into packages").
        $this->assertArrayNotHasKey('githubOwner', $decoded);
        $this->assertArrayNotHasKey('githubRepo', $decoded);
        $this->assertArrayNotHasKey('publishedAt', $decoded);

    }//end testToPackageEmitsOnlyPortableFields()

    /**
     * A serialise → deserialise round trip reproduces every portable field.
     *
     * @return void
     *
     * @spec openspec/specs/agent-template-gallery/spec.md#requirement-an-agenttemplate-carries-no-secrets-and-no-tenant-data
     */
    public function testRoundTripReproducesFields(): void
    {
        $serializer = new AgentTemplateSerializer();

        $original = [
            'name'              => 'Inbox triage',
            'description'       => 'Reviews unread mail',
            'category'          => 'productivity',
            'systemPrompt'      => 'Triage the inbox',
            'suggestedProvider' => 'openai',
            'suggestedModel'    => 'gpt-4o-mini',
            'tools'             => ['a.b.search'],
            'skillRefs'         => [['skillId' => 's1', 'name' => 'Mail reader']],
            'suggestedSchedule' => ['kind' => 'interval', 'intervalMinutes' => 60, 'deliver' => 'notification'],
            'version'           => '1.2.0',
        ];

        $package = $serializer->toPackage(template: $original);
        $parsed  = $serializer->fromPackage(package: $package);

        $this->assertSame($original['name'], $parsed['name']);
        $this->assertSame($original['description'], $parsed['description']);
        $this->assertSame($original['category'], $parsed['category']);
        $this->assertSame($original['systemPrompt'], $parsed['systemPrompt']);
        $this->assertSame($original['suggestedProvider'], $parsed['suggestedProvider']);
        $this->assertSame($original['suggestedModel'], $parsed['suggestedModel']);
        $this->assertSame($original['tools'], $parsed['tools']);
        $this->assertSame($original['skillRefs'], $parsed['skillRefs']);
        $this->assertSame($original['suggestedSchedule'], $parsed['suggestedSchedule']);
        $this->assertSame($original['version'], $parsed['version']);

    }//end testRoundTripReproducesFields()

    /**
     * fromPackage() is tolerant of missing optional fields — every key still resolves to
     * a well-shaped default (mirrors SkillSerializer::fromPackage()'s empty-string/array
     * defaults).
     *
     * @return void
     *
     * @spec openspec/specs/agent-template-gallery/spec.md#requirement-an-agenttemplate-carries-no-secrets-and-no-tenant-data
     */
    public function testFromPackageToleratesMissingFields(): void
    {
        $serializer = new AgentTemplateSerializer();

        $parsed = $serializer->fromPackage(package: '{"name": "Minimal"}');

        $this->assertSame('Minimal', $parsed['name']);
        $this->assertSame('', $parsed['description']);
        $this->assertSame('', $parsed['category']);
        $this->assertSame('', $parsed['systemPrompt']);
        $this->assertSame('', $parsed['suggestedProvider']);
        $this->assertSame('', $parsed['suggestedModel']);
        $this->assertSame([], $parsed['tools']);
        $this->assertSame([], $parsed['skillRefs']);
        $this->assertSame([], $parsed['suggestedSchedule']);
        $this->assertSame('0.1.0', $parsed['version']);

    }//end testFromPackageToleratesMissingFields()

    /**
     * fromPackage() never throws on malformed JSON — it falls back to a well-shaped,
     * empty template instead of a fatal error.
     *
     * @return void
     */
    public function testFromPackageToleratesMalformedJson(): void
    {
        $serializer = new AgentTemplateSerializer();

        $parsed = $serializer->fromPackage(package: 'not json at all {{{');

        $this->assertSame('', $parsed['name']);
        $this->assertSame([], $parsed['tools']);

    }//end testFromPackageToleratesMalformedJson()
}//end class

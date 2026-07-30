<?php

/**
 * Unit tests for SkillSerializer (skills-catalog).
 *
 * Covers the lossless agentskills.io round trip: fromPackage(toPackage(x)) reproduces the
 * frontmatter and body byte-for-byte, and name/description are extracted from the
 * frontmatter.
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
 * @spec openspec/changes/skills-catalog/tasks.md#task-6-1
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service;

use OCA\Hermiq\Service\SkillSerializer;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the skills-catalog SkillSerializer.
 *
 * @spec openspec/changes/skills-catalog/tasks.md#task-6-1
 */
class SkillSerializerTest extends TestCase
{

    /**
     * A serialize → deserialize round trip reproduces frontmatter + body byte-for-byte.
     *
     * @return void
     *
     * @spec openspec/changes/skills-catalog/tasks.md#task-2-2
     */
    public function testRoundTripIsByteForByte(): void
    {
        $serializer = new SkillSerializer();

        $skill = [
            'frontmatter' => "name: Weekly Reporter\ndescription: Summarises the week\nversion: 1.0.0\nlicense: EUPL-1.2",
            'body'        => "# Weekly Reporter\n\nGather the week's activity and produce a digest.\n",
        ];

        $package     = $serializer->toPackage(skill: $skill);
        $reparsed    = $serializer->fromPackage(package: $package);

        $this->assertSame($skill['frontmatter'], $reparsed['frontmatter']);
        $this->assertSame($skill['body'], $reparsed['body']);

    }//end testRoundTripIsByteForByte()

    /**
     * fromPackage extracts name + description from the frontmatter (quotes stripped).
     *
     * @return void
     *
     * @spec openspec/changes/skills-catalog/tasks.md#task-2-1
     */
    public function testFromPackageExtractsNameAndDescription(): void
    {
        $serializer = new SkillSerializer();

        $package = "---\nname: \"My Skill\"\ndescription: What it does\n---\n# Body\n";
        $parsed  = $serializer->fromPackage(package: $package);

        $this->assertSame('My Skill', $parsed['name']);
        $this->assertSame('What it does', $parsed['description']);
        $this->assertSame("name: \"My Skill\"\ndescription: What it does", $parsed['frontmatter']);
        $this->assertSame("# Body\n", $parsed['body']);

    }//end testFromPackageExtractsNameAndDescription()

    /**
     * A package with no frontmatter fence is treated as an all-body skill.
     *
     * @return void
     *
     * @spec openspec/changes/skills-catalog/tasks.md#task-2-1
     */
    public function testPackageWithoutFrontmatterIsAllBody(): void
    {
        $serializer = new SkillSerializer();

        $parsed = $serializer->fromPackage(package: "just a body, no fences");

        $this->assertSame('', $parsed['frontmatter']);
        $this->assertSame('just a body, no fences', $parsed['body']);
        $this->assertSame('', $parsed['name']);

    }//end testPackageWithoutFrontmatterIsAllBody()

    /**
     * skill-maturity regression: the exported agentskills.io package is byte-identical
     * with and without the maturity metadata (`maturityLevel`, `targetLevel`,
     * `levelEvidence`) — qualifying/attesting a skill never changes its export, and no
     * maturity field ever leaks into the package.
     *
     * @return void
     *
     * @spec openspec/specs/skill-maturity/spec.md#requirement-the-agentskillsio-export-is-byte-identical-regardless-of-maturity
     */
    public function testMaturityFieldsNeverEnterTheExportedPackage(): void
    {
        $serializer = new SkillSerializer();

        $bare = [
            'frontmatter' => "name: tender-summary\ndescription: Summarise a tender publication — use when the user pastes a TED notice.\nversion: 0.1.0",
            'body'        => "# Tender Summary\n\n1. Extract essentials.\n",
        ];

        $qualified = array_merge(
            $bare,
            [
                'maturityLevel' => 4,
                'targetLevel'   => 5,
                'levelEvidence' => [
                    'l1' => [
                        'passed'    => true,
                        'checkedAt' => '2026-07-01T00:00:00+00:00',
                    ],
                    'l4' => [
                        'attestedBy' => 'admin',
                        'attestedAt' => '2026-01-15T09:00:00+00:00',
                        'note'       => 'seeded',
                    ],
                ],
            ]
        );

        $before = $serializer->toPackage(skill: $bare);
        $after  = $serializer->toPackage(skill: $qualified);

        $this->assertSame($before, $after);
        $this->assertStringNotContainsString('maturityLevel', $after);
        $this->assertStringNotContainsString('targetLevel', $after);
        $this->assertStringNotContainsString('levelEvidence', $after);

    }//end testMaturityFieldsNeverEnterTheExportedPackage()

    /**
     * A CRLF package normalises to LF and still round-trips its content.
     *
     * @return void
     *
     * @spec openspec/changes/skills-catalog/tasks.md#task-2-2
     */
    public function testCrlfNormalisesToLf(): void
    {
        $serializer = new SkillSerializer();

        $package = "---\r\nname: X\r\n---\r\nbody line\r\n";
        $parsed  = $serializer->fromPackage(package: $package);

        $this->assertSame('name: X', $parsed['frontmatter']);
        $this->assertSame("body line\n", $parsed['body']);

    }//end testCrlfNormalisesToLf()
}//end class

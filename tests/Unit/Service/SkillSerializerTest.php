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

    /**
     * Directory form round-trips frontmatter, body AND auxiliary files.
     *
     * @return void
     *
     * @spec openspec/changes/skill-package-multifile/specs/skills-marketplace/spec.md#requirement-a-multi-file-skill-survives-the-install-round-trip-intact
     */
    public function testDirectoryFormRoundTripsAuxiliaryFiles(): void
    {
        $serializer = new SkillSerializer();

        $skill = [
            'frontmatter' => "name: Create PR\ndescription: Open a PR with local checks",
            'body'        => "Follow the checklist in references/local-checks.md\n",
            'files'       => [
                ['name' => 'references/local-checks.md', 'content' => "1. composer check:strict\n"],
                ['name' => 'learnings.md', 'content' => "- 2026-07-31: CI differs from the container\n"],
            ],
        ];

        $package  = $serializer->toPackageFiles(skill: $skill);
        $reparsed = $serializer->fromPackageFiles(files: $package);

        $this->assertSame($skill['frontmatter'], $reparsed['frontmatter']);
        $this->assertSame($skill['body'], $reparsed['body']);
        $this->assertCount(2, $reparsed['files']);

        $byName = array_column($reparsed['files'], 'content', 'name');
        $this->assertSame("1. composer check:strict\n", $byName['references/local-checks.md']);
        $this->assertSame("- 2026-07-31: CI differs from the container\n", $byName['learnings.md']);

    }//end testDirectoryFormRoundTripsAuxiliaryFiles()

    /**
     * A package with only SKILL.md parses to an empty files array — the
     * back-compatible single-file path.
     *
     * @return void
     *
     * @spec openspec/changes/skill-package-multifile/specs/skills-marketplace/spec.md#requirement-a-multi-file-skill-survives-the-install-round-trip-intact
     */
    public function testDirectoryFormWithOnlySkillFileYieldsNoAuxFiles(): void
    {
        $serializer = new SkillSerializer();

        $parsed = $serializer->fromPackageFiles(
            files: [SkillSerializer::SKILL_FILE => "---\nname: Solo\n---\njust a body\n"]
        );

        $this->assertSame('name: Solo', $parsed['frontmatter']);
        $this->assertSame("just a body\n", $parsed['body']);
        $this->assertSame([], $parsed['files']);

    }//end testDirectoryFormWithOnlySkillFileYieldsNoAuxFiles()

    /**
     * Unsafe auxiliary paths are dropped, never sanitised — and a package whose
     * every auxiliary entry is unsafe still yields its body.
     *
     * @return void
     *
     * @spec openspec/changes/skill-package-multifile/specs/skills-marketplace/spec.md#requirement-auxiliary-file-paths-are-validated-on-install
     */
    public function testUnsafeAuxiliaryPathsAreRejectedNotSanitised(): void
    {
        $serializer = new SkillSerializer();

        $parsed = $serializer->fromPackageFiles(
            files: [
                SkillSerializer::SKILL_FILE => "---\nname: Crafted\n---\nbody\n",
                '../../etc/passwd'          => 'root:x:0:0',
                '/etc/shadow'               => 'secret',
                'refs/../../x.md'           => 'escape',
                'bad\\windows.md'           => 'backslash',
                './relative.md'             => 'dot segment',
                ''                          => 'empty name',
                'references/ok.md'          => "safe\n",
            ]
        );

        $names = array_column($parsed['files'], 'name');

        $this->assertSame(['references/ok.md'], $names, 'Only the safe nested path survives.');
        $this->assertNotContains('etc/passwd', $names);
        $this->assertSame("body\n", $parsed['body'], 'A bad aux path must not deny the valid body.');

    }//end testUnsafeAuxiliaryPathsAreRejectedNotSanitised()

    /**
     * Every auxiliary entry unsafe still installs the body with an empty files set.
     *
     * @return void
     *
     * @spec openspec/changes/skill-package-multifile/specs/skills-marketplace/spec.md#requirement-auxiliary-file-paths-are-validated-on-install
     */
    public function testAllUnsafePathsStillYieldsValidBody(): void
    {
        $serializer = new SkillSerializer();

        $parsed = $serializer->fromPackageFiles(
            files: [
                SkillSerializer::SKILL_FILE => "---\nname: OnlyBad\n---\nstill valid\n",
                '../a.md'                   => 'x',
                '/b.md'                     => 'y',
            ]
        );

        $this->assertSame([], $parsed['files']);
        $this->assertSame("still valid\n", $parsed['body']);
        $this->assertSame('OnlyBad', $parsed['name']);

    }//end testAllUnsafePathsStillYieldsValidBody()

    /**
     * Nested paths within bounds are accepted with the separator preserved, and an
     * over-long path is rejected.
     *
     * @return void
     *
     * @spec openspec/changes/skill-package-multifile/specs/skills-marketplace/spec.md#requirement-auxiliary-file-paths-are-validated-on-install
     */
    public function testPathSafetyBoundaries(): void
    {
        $serializer = new SkillSerializer();

        $this->assertTrue($serializer->isSafeAuxPath(path: 'references/persona.md'));
        $this->assertTrue($serializer->isSafeAuxPath(path: 'evals/workspace/iteration-1/benchmark.json'));
        $this->assertTrue($serializer->isSafeAuxPath(path: str_repeat('a', 200)));

        $this->assertFalse($serializer->isSafeAuxPath(path: str_repeat('a', 201)));
        $this->assertFalse($serializer->isSafeAuxPath(path: ''));
        $this->assertFalse($serializer->isSafeAuxPath(path: '/abs.md'));
        $this->assertFalse($serializer->isSafeAuxPath(path: 'a//b.md'));
        $this->assertFalse($serializer->isSafeAuxPath(path: 'a/../b.md'));

    }//end testPathSafetyBoundaries()

    /**
     * toPackageFiles drops an auxiliary entry that would collide with SKILL.md, so a
     * crafted skill cannot overwrite its own frontmatter block through the files set.
     *
     * @return void
     *
     * @spec openspec/changes/skill-package-multifile/specs/skills-marketplace/spec.md#requirement-auxiliary-file-paths-are-validated-on-install
     */
    public function testAuxiliaryEntryCannotOverwriteSkillFile(): void
    {
        $serializer = new SkillSerializer();

        $package = $serializer->toPackageFiles(
            skill: [
                'frontmatter' => 'name: Real',
                'body'        => "real body\n",
                'files'       => [
                    ['name' => SkillSerializer::SKILL_FILE, 'content' => 'HIJACKED'],
                ],
            ]
        );

        $this->assertStringContainsString('real body', $package[SkillSerializer::SKILL_FILE]);
        $this->assertStringNotContainsString('HIJACKED', $package[SkillSerializer::SKILL_FILE]);

    }//end testAuxiliaryEntryCannotOverwriteSkillFile()
}//end class

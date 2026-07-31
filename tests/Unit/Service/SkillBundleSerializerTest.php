<?php

/**
 * Unit tests for SkillBundleSerializer (skill-bundle-publish).
 *
 * Covers: many skills round-trip through one bundle tree with frontmatter, body and
 * auxiliary files intact; the manifest is consumed rather than surfaced as a skill;
 * a crafted manifest name cannot escape the bundle; an entry that escapes its own
 * `skills/<name>/` prefix is dropped; and an unsupported formatVersion refuses to
 * parse rather than half-parsing.
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
 * @spec openspec/changes/skill-bundle-publish/specs/skills-marketplace/spec.md#requirement-many-skills-publish-to-a-single-repository
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service;

use OCA\Hermiq\Service\SkillBundleSerializer;
use OCA\Hermiq\Service\SkillSerializer;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the skill-bundle-publish SkillBundleSerializer.
 *
 * @spec openspec/changes/skill-bundle-publish/specs/skills-marketplace/spec.md#requirement-many-skills-publish-to-a-single-repository
 */
class SkillBundleSerializerTest extends TestCase
{

    /**
     * A real (not mocked) bundle serialiser — the whole point of this class is the
     * composition with SkillSerializer, which mocking would hide.
     *
     * @return SkillBundleSerializer
     */
    private function serializer(): SkillBundleSerializer
    {
        return new SkillBundleSerializer(new SkillSerializer());

    }//end serializer()

    /**
     * Three skills, one multi-file, round-trip through the bundle byte-identically.
     *
     * @return void
     *
     * @spec openspec/changes/skill-bundle-publish/specs/skills-marketplace/spec.md#requirement-many-skills-publish-to-a-single-repository
     */
    public function testBundleRoundTripsEverySkill(): void
    {
        $serializer = $this->serializer();

        $skills = [
            [
                'name'        => 'create-pr',
                'frontmatter' => "name: create-pr\ndescription: Open a PR",
                'body'        => "Follow references/local-checks.md\n",
                'files'       => [
                    ['name' => 'references/local-checks.md', 'content' => "1. composer check:strict\n"],
                    ['name' => 'learnings.md', 'content' => "- vetted\n"],
                ],
            ],
            [
                'name'        => 'clean-env',
                'frontmatter' => 'name: clean-env',
                'body'        => "Reset the environment.\n",
                'files'       => [],
            ],
            [
                'name'        => 'blog-write',
                'frontmatter' => 'name: blog-write',
                'body'        => "Write a post.\n",
                'files'       => [['name' => 'assets/blog-template.mdx', 'content' => "# Title\n"]],
            ],
        ];

        $bundle = $serializer->toBundle(skills: $skills);

        $this->assertArrayHasKey(SkillBundleSerializer::MANIFEST_FILE, $bundle);
        $this->assertArrayHasKey('skills/create-pr/SKILL.md', $bundle);
        $this->assertArrayHasKey('skills/create-pr/references/local-checks.md', $bundle);
        $this->assertArrayHasKey('skills/blog-write/assets/blog-template.mdx', $bundle);

        $parsed = $serializer->fromBundle(files: $bundle);
        $this->assertCount(3, $parsed);

        $byName = [];
        foreach ($parsed as $skill) {
            $byName[$skill['bundleName']] = $skill;
        }

        $this->assertSame("Follow references/local-checks.md\n", $byName['create-pr']['body']);
        $this->assertSame("name: create-pr\ndescription: Open a PR", $byName['create-pr']['frontmatter']);
        $this->assertCount(2, $byName['create-pr']['files']);
        $this->assertSame([], $byName['clean-env']['files']);
        $this->assertCount(1, $byName['blog-write']['files']);

        $aux = array_column($byName['create-pr']['files'], 'content', 'name');
        $this->assertSame("1. composer check:strict\n", $aux['references/local-checks.md']);

    }//end testBundleRoundTripsEverySkill()

    /**
     * The manifest is consumed as metadata and never surfaces as a skill.
     *
     * @return void
     *
     * @spec openspec/changes/skill-bundle-publish/specs/skills-marketplace/spec.md#requirement-many-skills-publish-to-a-single-repository
     */
    public function testManifestIsNeverParsedAsASkill(): void
    {
        $serializer = $this->serializer();
        $bundle     = $serializer->toBundle(
            skills: [['name' => 'solo', 'frontmatter' => 'name: solo', 'body' => "b\n", 'files' => []]]
        );

        $parsed = $serializer->fromBundle(files: $bundle);

        $this->assertCount(1, $parsed);
        $this->assertSame('solo', $parsed[0]['bundleName']);

    }//end testManifestIsNeverParsedAsASkill()

    /**
     * A crafted manifest name never reaches a path concatenation.
     *
     * @return void
     *
     * @spec openspec/changes/skill-bundle-publish/specs/skills-marketplace/spec.md#requirement-bundle-entries-are-validated-before-use-as-paths
     */
    public function testCraftedManifestNameIsRejected(): void
    {
        $serializer = $this->serializer();

        $bundle = [
            SkillBundleSerializer::MANIFEST_FILE => json_encode(
                [
                    'formatVersion' => SkillBundleSerializer::FORMAT_VERSION,
                    'skills'        => [
                        ['name' => '../../etc'],
                        ['name' => '/absolute'],
                        ['name' => 'ok-skill'],
                    ],
                ]
            ),
            'skills/ok-skill/SKILL.md' => "---\nname: ok-skill\n---\nbody\n",
            'skills/../../etc/SKILL.md' => 'escaped',
        ];

        $parsed = $serializer->fromBundle(files: $bundle);

        $this->assertCount(1, $parsed, 'Only the well-named skill survives.');
        $this->assertSame('ok-skill', $parsed[0]['bundleName']);

    }//end testCraftedManifestNameIsRejected()

    /**
     * An auxiliary entry that escapes its own prefix is dropped, not relocated.
     *
     * @return void
     *
     * @spec openspec/changes/skill-bundle-publish/specs/skills-marketplace/spec.md#requirement-bundle-entries-are-validated-before-use-as-paths
     */
    public function testEntryEscapingItsPrefixIsDropped(): void
    {
        $serializer = $this->serializer();

        $bundle = [
            SkillBundleSerializer::MANIFEST_FILE => json_encode(
                ['formatVersion' => SkillBundleSerializer::FORMAT_VERSION, 'skills' => [['name' => 'demo']]]
            ),
            'skills/demo/SKILL.md'          => "---\nname: demo\n---\nbody\n",
            'skills/demo/references/ok.md'  => "safe\n",
            'skills/demo/../../escape.md'   => 'escaped',
            'skills/demo//double.md'        => 'empty segment',
        ];

        $parsed = $serializer->fromBundle(files: $bundle);

        $this->assertCount(1, $parsed);
        $names = array_column($parsed[0]['files'], 'name');
        $this->assertSame(['references/ok.md'], $names);

    }//end testEntryEscapingItsPrefixIsDropped()

    /**
     * A skill declared in the manifest but missing its SKILL.md is skipped rather
     * than producing an empty-bodied skill.
     *
     * @return void
     *
     * @spec openspec/changes/skill-bundle-publish/specs/skills-marketplace/spec.md#requirement-bundle-entries-are-validated-before-use-as-paths
     */
    public function testDeclaredSkillWithoutSkillFileIsSkipped(): void
    {
        $serializer = $this->serializer();

        $parsed = $serializer->fromBundle(
            files: [
                SkillBundleSerializer::MANIFEST_FILE => json_encode(
                    ['formatVersion' => SkillBundleSerializer::FORMAT_VERSION, 'skills' => [['name' => 'ghost']]]
                ),
                'skills/ghost/references/only.md' => 'orphan',
            ]
        );

        $this->assertSame([], $parsed);

    }//end testDeclaredSkillWithoutSkillFileIsSkipped()

    /**
     * A repository without a manifest is NOT a bundle, and an unsupported major
     * version refuses rather than half-parsing.
     *
     * @return void
     *
     * @spec openspec/changes/skill-bundle-publish/specs/skills-marketplace/spec.md#requirement-many-skills-publish-to-a-single-repository
     */
    public function testNonBundleAndUnsupportedVersionRefuse(): void
    {
        $serializer = $this->serializer();

        $noManifest = ['skills/demo/SKILL.md' => "---\nname: demo\n---\nb"];
        $this->assertFalse($serializer->isBundle(files: $noManifest));
        $this->assertSame([], $serializer->fromBundle(files: $noManifest));

        $future = [
            SkillBundleSerializer::MANIFEST_FILE => json_encode(
                ['formatVersion' => '9.0', 'skills' => [['name' => 'demo']]]
            ),
            'skills/demo/SKILL.md' => "---\nname: demo\n---\nb",
        ];
        $this->assertFalse($serializer->isBundle(files: $future));
        $this->assertSame([], $serializer->fromBundle(files: $future));

    }//end testNonBundleAndUnsupportedVersionRefuse()

    /**
     * The bundle honours its skill cap rather than fanning out without bound.
     *
     * @return void
     *
     * @spec openspec/changes/skill-bundle-publish/specs/skills-marketplace/spec.md#requirement-bundle-entries-are-validated-before-use-as-paths
     */
    public function testSkillCapIsEnforced(): void
    {
        $serializer = $this->serializer();

        $skills = [];
        for ($i = 0; $i < (SkillBundleSerializer::MAX_SKILLS + 5); $i++) {
            $skills[] = [
                'name'        => 'skill-'.$i,
                'frontmatter' => 'name: skill-'.$i,
                'body'        => "b\n",
                'files'       => [],
            ];
        }

        $bundle   = $serializer->toBundle(skills: $skills);
        $manifest = json_decode($bundle[SkillBundleSerializer::MANIFEST_FILE], true);

        $this->assertCount(SkillBundleSerializer::MAX_SKILLS, $manifest['skills']);

    }//end testSkillCapIsEnforced()
}//end class

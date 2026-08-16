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
class SkillBundleSerializerTest extends TestCase {

	/**
	 * A real (not mocked) bundle serialiser — the whole point of this class is the
	 * composition with SkillSerializer, which mocking would hide.
	 *
	 * @return SkillBundleSerializer
	 */
	private function serializer(): SkillBundleSerializer {
		return new SkillBundleSerializer(new SkillSerializer());
	}//end serializer()

	/**
	 * A namespaced name reaches the bundle instead of being dropped.
	 *
	 * `intelligence:update` follows the `/namespace:command` convention, and the
	 * colon only ever mattered to a DIRECTORY name — the skill keeps calling
	 * itself whatever its frontmatter says, and fromBundle() records the folder
	 * separately as bundleName. Dropping the whole skill over it lost real work.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/skill-bundle-publish/specs/skills-marketplace/spec.md#requirement-many-skills-publish-to-a-single-repository
	 */
	public function testANamespacedNameIsFoldedIntoASafeDirectoryRatherThanDropped(): void {
		$dropped = null;
		$bundle = $this->serializer()->toBundle(
			skills: [
				[
					'name' => 'intelligence:update',
					'frontmatter' => 'name: intelligence:update',
					'body' => "Pull the latest data.\n",
					'files' => [],
				],
			],
			dropped: $dropped
		);

		self::assertSame([], (array)$dropped, 'a colon must not cost the whole skill');
		self::assertArrayHasKey('skills/intelligence-update/SKILL.md', $bundle);
		// The skill keeps its own name; only the folder was made safe.
		self::assertStringContainsString('intelligence:update', $bundle['skills/intelligence-update/SKILL.md']);

	}//end testANamespacedNameIsFoldedIntoASafeDirectoryRatherThanDropped()

	/**
	 * Traversal characters cannot survive into a path.
	 *
	 * The original code rejected these outright; sanitising must keep the SAME
	 * guarantee rather than trade it for convenience.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/skill-bundle-publish/specs/skills-marketplace/spec.md#requirement-many-skills-publish-to-a-single-repository
	 */
	public function testTraversalCharactersCannotReachAPath(): void {
		$dropped = null;
		$bundle = $this->serializer()->toBundle(
			skills: [
				[
					'name' => '../../etc/passwd',
					'frontmatter' => 'name: evil',
					'body' => "x\n",
					'files' => [],
				],
			],
			dropped: $dropped
		);

		// A path is not a name: rejected outright rather than laundered into a
		// tidy `etc` folder, which would accept a hostile value under a clean name.
		self::assertCount(1, (array)$dropped);
		self::assertSame('invalid_name', ((array)$dropped)[0]['reason']);

		foreach (array_keys($bundle) as $path) {
			self::assertStringNotContainsString('..', (string)$path);
		}

	}//end testTraversalCharactersCannotReachAPath()

	/**
	 * Two names folding onto one directory is reported, never silently merged.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/skill-bundle-publish/specs/skills-marketplace/spec.md#requirement-many-skills-publish-to-a-single-repository
	 */
	public function testACollidingDirectoryNameIsReportedNotOverwritten(): void {
		$dropped = null;
		$bundle = $this->serializer()->toBundle(
			skills: [
				['name' => 'intelligence:update', 'frontmatter' => 'name: a', 'body' => "a\n", 'files' => []],
				['name' => 'intelligence-update', 'frontmatter' => 'name: b', 'body' => "b\n", 'files' => []],
			],
			dropped: $dropped
		);

		self::assertCount(1, (array)$dropped);
		self::assertSame('duplicate_directory_name', ((array)$dropped)[0]['reason']);
		self::assertStringContainsString('a', $bundle['skills/intelligence-update/SKILL.md']);

	}//end testACollidingDirectoryNameIsReportedNotOverwritten()

	/**
	 * Three skills, one multi-file, round-trip through the bundle byte-identically.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/skill-bundle-publish/specs/skills-marketplace/spec.md#requirement-many-skills-publish-to-a-single-repository
	 */
	public function testBundleRoundTripsEverySkill(): void {
		$serializer = $this->serializer();

		$skills = [
			[
				'name' => 'create-pr',
				'frontmatter' => "name: create-pr\ndescription: Open a PR",
				'body' => "Follow references/local-checks.md\n",
				'files' => [
					['name' => 'references/local-checks.md', 'content' => "1. composer check:strict\n"],
					['name' => 'learnings.md', 'content' => "- vetted\n"],
				],
			],
			[
				'name' => 'clean-env',
				'frontmatter' => 'name: clean-env',
				'body' => "Reset the environment.\n",
				'files' => [],
			],
			[
				'name' => 'blog-write',
				'frontmatter' => 'name: blog-write',
				'body' => "Write a post.\n",
				'files' => [['name' => 'assets/blog-template.mdx', 'content' => "# Title\n"]],
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
	public function testManifestIsNeverParsedAsASkill(): void {
		$serializer = $this->serializer();
		$bundle = $serializer->toBundle(
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
	public function testCraftedManifestNameIsRejected(): void {
		$serializer = $this->serializer();

		$bundle = [
			SkillBundleSerializer::MANIFEST_FILE => json_encode(
				[
					'formatVersion' => SkillBundleSerializer::FORMAT_VERSION,
					'skills' => [
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
	public function testEntryEscapingItsPrefixIsDropped(): void {
		$serializer = $this->serializer();

		$bundle = [
			SkillBundleSerializer::MANIFEST_FILE => json_encode(
				['formatVersion' => SkillBundleSerializer::FORMAT_VERSION, 'skills' => [['name' => 'demo']]]
			),
			'skills/demo/SKILL.md' => "---\nname: demo\n---\nbody\n",
			'skills/demo/references/ok.md' => "safe\n",
			'skills/demo/../../escape.md' => 'escaped',
			'skills/demo//double.md' => 'empty segment',
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
	public function testDeclaredSkillWithoutSkillFileIsSkipped(): void {
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
	public function testNonBundleAndUnsupportedVersionRefuse(): void {
		$serializer = $this->serializer();

		// Non-bundle: parses to nothing, so the caller reports "not a bundle"
		// rather than installing a partial set.
		$noManifest = ['skills/demo/SKILL.md' => "---\nname: demo\n---\nb"];
		$this->assertSame([], $serializer->fromBundle(files: $noManifest));

		// A future major version REFUSES rather than half-parsing — a bundle that
		// partly reads is worse than one that declines, because the caller would
		// believe an incomplete skill set was complete.
		$future = [
			SkillBundleSerializer::MANIFEST_FILE => json_encode(
				['formatVersion' => '9.0', 'skills' => [['name' => 'demo']]]
			),
			'skills/demo/SKILL.md' => "---\nname: demo\n---\nb",
		];
		$this->assertSame([], $serializer->fromBundle(files: $future));

	}//end testNonBundleAndUnsupportedVersionRefuse()

	/**
	 * The bundle honours its skill cap rather than fanning out without bound.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/skill-bundle-publish/specs/skills-marketplace/spec.md#requirement-bundle-entries-are-validated-before-use-as-paths
	 */
	public function testSkillCapIsEnforced(): void {
		$serializer = $this->serializer();

		$skills = [];
		for ($i = 0; $i < (SkillBundleSerializer::MAX_SKILLS + 5); $i++) {
			$skills[] = [
				'name' => 'skill-' . $i,
				'frontmatter' => 'name: skill-' . $i,
				'body' => "b\n",
				'files' => [],
			];
		}

		$dropped = [];
		$bundle = $serializer->toBundle(skills: $skills, dropped: $dropped);
		$manifest = json_decode($bundle[SkillBundleSerializer::MANIFEST_FILE], true);

		$this->assertCount(SkillBundleSerializer::MAX_SKILLS, $manifest['skills']);

		// The cap must REPORT what it discarded. Silently capping is how the first
		// real bundle shipped 64 of hydra's 94 skills while the API reported all 94
		// as published — and the artefact was internally consistent, so nothing in
		// the repository revealed the loss.
		$this->assertCount(5, $dropped, 'Every skill beyond the cap must be reported as dropped.');
		$this->assertSame('cap_reached', $dropped[0]['reason']);

	}//end testSkillCapIsEnforced()

	/**
	 * A bundle within the cap drops nothing — the counter must not cry wolf.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/skill-bundle-publish/specs/skills-marketplace/spec.md#requirement-many-skills-publish-to-a-single-repository
	 */
	public function testNothingIsDroppedWithinTheCap(): void {
		$serializer = $this->serializer();

		// 94 = hydra's real skill count, the set that exposed the 64 cap.
		$skills = [];
		for ($i = 0; $i < 94; $i++) {
			$skills[] = [
				'name' => 'skill-' . $i,
				'frontmatter' => 'name: skill-' . $i,
				'body' => "b\n",
				'files' => [],
			];
		}

		$dropped = [];
		$bundle = $serializer->toBundle(skills: $skills, dropped: $dropped);
		$manifest = json_decode($bundle[SkillBundleSerializer::MANIFEST_FILE], true);

		$this->assertCount(94, $manifest['skills'], "hydra's 94-skill set must bundle whole.");
		$this->assertSame([], $dropped);

	}//end testNothingIsDroppedWithinTheCap()

	/**
	 * An unusable skill name is reported as dropped, not merely skipped.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/skill-bundle-publish/specs/skills-marketplace/spec.md#requirement-bundle-entries-are-validated-before-use-as-paths
	 */
	public function testInvalidNameIsReportedAsDropped(): void {
		$serializer = $this->serializer();

		$dropped = [];
		$serializer->toBundle(
			skills: [
				['name' => '../../etc', 'frontmatter' => 'name: x', 'body' => "b\n", 'files' => []],
				['name' => 'good-skill', 'frontmatter' => 'name: good-skill', 'body' => "b\n", 'files' => []],
			],
			dropped: $dropped
		);

		$this->assertCount(1, $dropped);
		$this->assertSame('invalid_name', $dropped[0]['reason']);

	}//end testInvalidNameIsReportedAsDropped()

	/**
	 * An agent round-trips through toBundle()/agentsFromBundle() with its
	 * arbitrary fields (prompt/tools/anything) intact, and the identity fields
	 * an installed-elsewhere agent must NOT collide on are stripped.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/skills-marketplace/spec.md#requirement-a-skill-bundle-may-additionally-carry-agent-definitions
	 */
	public function testAgentRoundTripsThroughBundle(): void {
		$serializer = $this->serializer();

		$tree = $serializer->toBundle(
			skills: [],
			agents: [
				[
					'uuid' => 'should-not-survive',
					'id' => 'should-not-survive-either',
					'name' => 'Hydra Triage',
					'description' => 'Reads pipeline state.',
					'prompt' => 'You are the triage agent.',
					'tools' => ['hydra.change.*', 'hydra.cycle.*'],
					'requiresApproval' => true,
				],
			]
		);

		$this->assertArrayHasKey('agents/hydra-triage.json', $tree);

		$agents = $serializer->agentsFromBundle(files: $tree);
		$this->assertCount(1, $agents);
		$this->assertSame('Hydra Triage', $agents[0]['name']);
		$this->assertSame('You are the triage agent.', $agents[0]['prompt']);
		$this->assertSame(['hydra.change.*', 'hydra.cycle.*'], $agents[0]['tools']);
		$this->assertTrue($agents[0]['requiresApproval']);
		$this->assertArrayNotHasKey('uuid', $agents[0], 'a fresh install must get a fresh identity, never reuse the source uuid');
		$this->assertArrayNotHasKey('id', $agents[0]);

	}//end testAgentRoundTripsThroughBundle()

	/**
	 * A 1.0-shaped bundle (no `agents` manifest key at all) is still valid and
	 * agent-less — the MINOR version bump must not break every bundle published
	 * before this requirement existed.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/skills-marketplace/spec.md#scenario-a-10-bundle-with-no-agents-key-still-installs-its-skills
	 */
	public function testLegacyBundleWithNoAgentsKeyParsesAsAgentless(): void {
		$serializer = $this->serializer();

		$legacyManifest = [
			'formatVersion' => '1.0',
			'skills' => [['name' => 'good-skill']],
		];
		$files = [
			SkillBundleSerializer::MANIFEST_FILE => json_encode($legacyManifest),
			'skills/good-skill/SKILL.md' => "---\nname: good-skill\n---\nbody\n",
		];

		$this->assertSame([], $serializer->agentsFromBundle(files: $files));
		$this->assertCount(1, $serializer->fromBundle(files: $files), 'skills must still install from a pre-agents bundle');

	}//end testLegacyBundleWithNoAgentsKeyParsesAsAgentless()

	/**
	 * Two agents whose names sanitise to the same file name: the second is
	 * dropped and reported, never silently overwriting the first — the same
	 * guarantee `toBundle()` already gives skills.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/skills-marketplace/spec.md#requirement-a-skill-bundle-may-additionally-carry-agent-definitions
	 */
	public function testDuplicateAgentFileNameIsDroppedNotOverwritten(): void {
		$serializer = $this->serializer();

		$droppedAgents = [];
		$serializer->toBundle(
			skills: [],
			dropped: $dropped,
			agents: [
				['name' => 'Hydra Triage', 'prompt' => 'first'],
				['name' => 'hydra-triage', 'prompt' => 'second'],
			],
			droppedAgents: $droppedAgents
		);

		$this->assertCount(1, $droppedAgents);
		$this->assertSame('duplicate_directory_name', $droppedAgents[0]['reason']);

	}//end testDuplicateAgentFileNameIsDroppedNotOverwritten()
}//end class

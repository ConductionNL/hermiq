<?php

/**
 * Golden test of a bundle's exact files, drops and rejection log, both ways.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
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
use Psr\Log\AbstractLogger;
use Stringable;

/**
 * Exact outcomes of toBundle(), fromBundle() and agentsFromBundle().
 *
 * @covers \OCA\Hermiq\Service\SkillBundleSerializer
 *
 * @spec openspec/changes/skill-bundle-publish/specs/skills-marketplace/spec.md#requirement-many-skills-publish-to-a-single-repository
 */
class SkillBundleSerializerGoldenTest extends TestCase {

	/**
	 * Every log line, in order.
	 *
	 * @var list<string>
	 */
	private array $lines = [];

	/**
	 * A bundle of mixed skills and agents writes exact files and reports exact drops.
	 *
	 * @return void
	 */
	public function testToBundleWritesExactFilesAndDrops(): void {
		$skills = [
			['name' => 'lint', 'frontmatter' => 'name: lint', 'body' => 'Do it.', 'files' => [['name' => 'references/a.md', 'content' => 'A']]],
			'not an array',
			['name' => '..', 'frontmatter' => 'name: up', 'body' => 'x'],
			['name' => 'ns:lint', 'frontmatter' => 'name: ns:lint', 'body' => 'y'],
			['name' => 'ns-lint', 'frontmatter' => 'name: ns-lint', 'body' => 'z'],
		];
		$agents = [
			['name' => 'Triage', 'uuid' => 'u-1', 'id' => 4, '@self' => ['x'], 'prompt' => 'Sort'],
			['name' => '', 'prompt' => 'none'],
			['name' => 'triage', 'prompt' => 'again'],
		];

		$dropped = ['stale'];
		$droppedAgents = ['stale'];
		$bundle = $this->serializer()->toBundle(skills: $skills, dropped: $dropped, agents: $agents, droppedAgents: $droppedAgents);

		$this->assertMatchesFixture(
			fixture: 'skill-bundle-to.json',
			actual: ['bundle' => $bundle, 'dropped' => $dropped, 'droppedAgents' => $droppedAgents, 'log' => $this->lines]
		);

	}//end testToBundleWritesExactFilesAndDrops()

	/**
	 * Reading a bundle back gives exact skills, agents and rejection log lines.
	 *
	 * @return void
	 */
	public function testFromBundleReadsExactEntries(): void {
		$files = [
			'hermiq-skills.json' => (string)json_encode(
				[
					'formatVersion' => '1.1',
					'skills' => [['name' => 'lint'], ['name' => '../up'], ['name' => 'missing']],
					'agents' => ['triage', ['name' => 'broken'], ['name' => 'absent'], ['name' => 'nameless']],
				]
			),
			'skills/lint/SKILL.md' => "---\nname: lint\ndescription: Lints\n---\nDo it.\n",
			'skills/lint/references/a.md' => 'A',
			'agents/triage.json' => '{"name":"Triage","prompt":"Sort"}',
			'agents/broken.json' => '{oops',
			'agents/nameless.json' => '{"prompt":"p"}',
		];

		$serializer = $this->serializer();
		$actual = ['skills' => $serializer->fromBundle(files: $files), 'agents' => $serializer->agentsFromBundle(files: $files), 'log' => $this->lines];

		$this->assertMatchesFixture(fixture: 'skill-bundle-from.json', actual: $actual);

	}//end testFromBundleReadsExactEntries()

	/**
	 * Compare with the recorded fixture, or record it when GOLDEN_RECORD=1.
	 *
	 * @param string               $fixture The fixture file name.
	 * @param array<string, mixed> $actual  The outcome.
	 *
	 * @return void
	 */
	private function assertMatchesFixture(string $fixture, array $actual): void {
		$path = __DIR__ . '/../../Fixtures/Golden/' . $fixture;
		$actual = json_decode((string)json_encode($actual, JSON_THROW_ON_ERROR), true);
		if (getenv('GOLDEN_RECORD') === '1') {
			file_put_contents($path, json_encode($actual, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)) . "\n");
			$this->markTestSkipped(message: 'Fixture recorded.');
		}

		$this->assertSame(expected: json_decode((string)file_get_contents($path), true), actual: $actual);

	}//end assertMatchesFixture()

	/**
	 * The serialiser over a real SkillSerializer and a recording logger.
	 *
	 * @return SkillBundleSerializer
	 */
	private function serializer(): SkillBundleSerializer {
		$lines = &$this->lines;
		$logger = new class($lines) extends AbstractLogger {

			/**
			 * The recorded lines.
			 *
			 * @var list<string>
			 */
			private array $lines;

			/**
			 * Record into the given list.
			 *
			 * @param list<string> $lines The list to record into.
			 */
			public function __construct(array &$lines) {
				$this->lines = &$lines;
			}//end __construct()

			/**
			 * Record one line.
			 *
			 * @param mixed             $level   The level.
			 * @param string|Stringable $message The message.
			 * @param array             $context The context.
			 *
			 * @return void
			 */
			public function log($level, string|Stringable $message, array $context = []): void {
				$this->lines[] = $level . ' ' . $message . ' ' . json_encode($context);
			}//end log()
		};

		return new SkillBundleSerializer(skillSerializer: new SkillSerializer(), logger: $logger);

	}//end serializer()
}//end class

<?php

/**
 * Golden test for the tender-summary learnings upgrade of SeedMaturityExampleSkills.
 *
 * Runs the upgrade over every combination of stored files, stored level
 * evidence and freshness payload, and compares the saved payload and the
 * output lines with a recorded fixture. Today's date is written as TODAY so
 * the fixture does not age. Set GOLDEN_RECORD=1 to (re)write the fixture; a
 * run in record mode compares nothing.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/skill-learnings/spec.md#requirement-one-seeded-skill-demonstrates-the-learnings-shape
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Repair;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Hermiq\Repair\Seed\LearningsSeedTarget;
use OCA\Hermiq\Repair\SeedMaturityExampleSkills;
use OCA\Hermiq\Service\SeedFreshnessService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use ReflectionMethod;

/**
 * Golden comparison of the learnings upgrade.
 *
 * @covers \OCA\Hermiq\Repair\SeedMaturityExampleSkills
 * @uses   \OCA\Hermiq\Service\SeedFreshnessService
 *
 * @spec openspec/specs/skill-learnings/spec.md#requirement-one-seeded-skill-demonstrates-the-learnings-shape
 */
class SeedLearningsUpgradeGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/../../Fixtures/Golden/seed-learnings-upgrade.json';

	private const SAVED_ARGUMENTS = ['object', 'register', 'schema', 'uuid', '_rbac', '_multitenancy'];

	/**
	 * Every combination gives the recorded save and output.
	 *
	 * @return void
	 */
	public function testUpgradeMatchesTheRecordedFixture(): void {
		$learnings = ['name' => 'learnings.md', 'content' => 'mine'];
		$candidates = ['name' => 'learning-candidates.md', 'content' => 'theirs'];
		$fileSets = [
			'none' => null,
			'not-a-list' => 'files',
			'empty' => [],
			'learnings' => [$learnings, 'junk'],
			'candidates' => [['name' => 'README.md'], $candidates],
			'both' => [$candidates, $learnings],
			'keyed' => ['a' => ['content' => 'nameless'], 'b' => $learnings],
		];
		$evidenceSets = [
			'none' => null,
			'not-a-map' => 'evidence',
			'no-l6' => ['l5' => ['ok' => true]],
			'l6-empty' => ['l6' => []],
			'l6-text' => ['l6' => 'x'],
			'l6-set' => ['l6' => ['candidateCount' => 9], 'l3' => 1],
		];

		$actual = [];
		foreach ($fileSets as $fileName => $files) {
			foreach ($evidenceSets as $evidenceName => $evidence) {
				foreach (['stored', 'refreshed', 'refreshed-bare'] as $mode) {
					$actual[] = [
						'files' => $fileName,
						'evidence' => $evidenceName,
						'mode' => $mode,
						'outcome' => $this->upgrade(stored: $this->payload(files: $files, evidence: $evidence), mode: $mode),
					];
				}
			}
		}

		$today = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d');
		$actual = json_decode(str_replace($today, 'TODAY', json_encode($actual, JSON_THROW_ON_ERROR)), true, 512, JSON_THROW_ON_ERROR);

		if (getenv('GOLDEN_RECORD') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
			$this->markTestSkipped(message: 'Fixture recorded.');
		}

		$expected = json_decode((string)file_get_contents(self::FIXTURE), true, 512, JSON_THROW_ON_ERROR);
		$this->assertCount(expectedCount: count($expected), haystack: $actual);
		foreach ($expected as $index => $one) {
			$this->assertSame(
				expected: json_encode($one, JSON_THROW_ON_ERROR),
				actual: json_encode($actual[$index], JSON_THROW_ON_ERROR),
				message: 'case ' . $index
			);
		}

	}//end testUpgradeMatchesTheRecordedFixture()

	/**
	 * A stored tender-summary payload with the given files and evidence (absent when null).
	 *
	 * @param mixed $files The files value.
	 * @param mixed $evidence The levelEvidence value.
	 *
	 * @return array<string, mixed>
	 */
	private function payload(mixed $files, mixed $evidence): array {
		$payload = ['name' => 'tender-summary', 'state' => 'active'];
		if ($files !== null) {
			$payload['files'] = $files;
		}

		if ($evidence !== null) {
			$payload['levelEvidence'] = $evidence;
		}

		return $payload;

	}//end payload()

	/**
	 * Run the upgrade once and return what it saved and said.
	 *
	 * @param array<string, mixed> $stored The stored payload.
	 * @param string $mode `stored`, `refreshed` (a freshness payload) or `refreshed-bare`.
	 *
	 * @return array<string, mixed>
	 */
	private function upgrade(array $stored, string $mode): array {
		$saved = [];
		$names = array_map(
			static fn ($parameter): string => $parameter->getName(),
			(new ReflectionMethod(ObjectService::class, 'saveObject'))->getParameters()
		);
		$objectService = $this->createMock(originalClassName: ObjectService::class);
		$objectService->method('saveObject')->willReturnCallback(
			function (...$args) use (&$saved, $names): ObjectEntity {
				$named = [];
				foreach ($args as $position => $value) {
					$key = $position;
					if (is_int($position) === true) {
						$key = $names[$position];
					}

					if (in_array($key, self::SAVED_ARGUMENTS, true) === true) {
						$named[$key] = $value;
					}
				}

				ksort($named);
				$saved[] = $named;
				return new ObjectEntity();
			}
		);

		$lines = [];
		$output = $this->createMock(originalClassName: IOutput::class);
		$output->method('info')->willReturnCallback(
			function (string $message) use (&$lines): void {
				$lines[] = ['info', $message];
			}
		);

		$skill = new ObjectEntity();
		$skill->setUuid('uuid-tender');
		$skill->setObject($stored);

		$refreshed = null;
		if ($mode === 'refreshed') {
			$refreshed = $stored + ['lastActivityAt' => '2026-11-01T00:00:00+00:00'];
		}

		if ($mode === 'refreshed-bare') {
			$refreshed = ['name' => 'tender-summary', 'lastActivityAt' => '2026-11-01T00:00:00+00:00'];
		}

		$step = new SeedMaturityExampleSkills(
			container: $this->createMock(originalClassName: ContainerInterface::class),
			logger: new NullLogger(),
			freshness: new SeedFreshnessService()
		);
		$method = new ReflectionMethod(SeedMaturityExampleSkills::class, 'ensureLearningsSeed');
		$method->invoke($step, $objectService, new LearningsSeedTarget(skill: $skill, output: $output, refreshed: $refreshed));

		return ['saved' => $saved, 'output' => $lines];

	}//end upgrade()
}//end class

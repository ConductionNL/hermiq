<?php

/**
 * Golden comparison of run alignment and replay diffs.
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
 * @spec openspec/specs/run-replay-and-dry-run/spec.md#requirement-steps-are-aligned-so-an-extra-step-shows-as-one-difference-req-rcmp-002
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service;

use OCA\Hermiq\Service\RunComparator;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Seeded step sequences give the recorded comparison and replay diff.
 *
 * @covers \OCA\Hermiq\Service\RunComparator
 *
 * @spec openspec/specs/run-replay-and-dry-run/spec.md#requirement-steps-are-aligned-so-an-extra-step-shows-as-one-difference-req-rcmp-002
 */
class RunComparatorGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/../../Fixtures/Golden/run-comparator.json';

	private const CASES = 150;

	private const NAMES = ['Search', 'Read', 'Send', 'Write'];

	private const OUTCOMES = ['ok', 'ok', 'error', null, 7];

	/**
	 * The seeded generator of the current case.
	 *
	 * @var Randomizer
	 */
	private Randomizer $random;

	/**
	 * Every generated case gives the recorded comparison and replay diff.
	 *
	 * @return void
	 */
	public function testComparisonsMatchTheRecordedFixture(): void {
		$actual = [];
		for ($case = 0; $case < self::CASES; $case++) {
			$this->random = new Randomizer(new Mt19937($case + 1));
			$left = $this->steps();
			$right = $this->steps();
			$leftSummary = ['Done', 'Failed', ''][$this->random->getInt(0, 2)];
			$rightSummary = ['Done', 'Failed', ''][$this->random->getInt(0, 2)];

			$comparator = new RunComparator();
			$actual[] = [
				'compare' => $comparator->compare(leftSteps: $left, rightSteps: $right, leftSummary: $leftSummary, rightSummary: $rightSummary),
				'replay' => $comparator->toReplayDiff(
					originalSteps: $left,
					replaySteps: $right,
					originalSummary: $leftSummary,
					replaySummary: $rightSummary
				),
			];
		}

		if (getenv('GOLDEN_RECORD') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, (JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)) . "\n");
			$this->markTestSkipped(message: 'Fixture recorded.');
		}

		$expected = json_decode((string)file_get_contents(self::FIXTURE), true, 512, JSON_THROW_ON_ERROR);
		$this->assertSame(expected: $expected, actual: json_decode((string)json_encode($actual, JSON_THROW_ON_ERROR), true));

	}//end testComparisonsMatchTheRecordedFixture()

	/**
	 * A seeded run: tool steps, other steps and junk.
	 *
	 * @return array<int, mixed>
	 */
	private function steps(): array {
		$steps = [];
		for ($i = $this->random->getInt(0, 7); $i > 0; $i--) {
			$kind = $this->random->getInt(0, 9);
			if ($kind === 0) {
				$steps[] = 'junk';
				continue;
			}

			if ($kind === 1) {
				$steps[] = ['type' => 'thought', 'name' => 'Search'];
				continue;
			}

			$steps[] = [
				'type' => 'tool',
				'name' => self::NAMES[$this->random->getInt(0, 3)],
				'outcome' => self::OUTCOMES[$this->random->getInt(0, 4)],
				'durationMs' => [12, '40', null, 'slow'][$this->random->getInt(0, 3)],
			];
		}

		return $steps;

	}//end steps()
}//end class

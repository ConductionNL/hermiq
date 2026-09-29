<?php

/**
 * Unit tests for RunComparator (observability-compare-two-runs).
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
 * @spec openspec/changes/observability-compare-two-runs/specs/run-replay-and-dry-run/spec.md#requirement-steps-are-aligned-so-an-extra-step-shows-as-one-difference-req-rcmp-002
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service;

use OCA\Hermiq\Service\RunComparator;
use PHPUnit\Framework\TestCase;

/**
 * The step alignment behind run comparison and the replay diff.
 */
class RunComparatorTest extends TestCase {

	/**
	 * Build a tool step as a run records it.
	 *
	 * @param string $name The tool name.
	 * @param string $outcome The outcome.
	 * @param int $durationMs The duration.
	 *
	 * @return array<string, mixed> The step.
	 */
	private function tool(string $name, string $outcome='ok', int $durationMs=100): array {
		return ['type' => 'tool', 'name' => $name, 'outcome' => $outcome, 'durationMs' => $durationMs];
	}//end tool()

	/**
	 * One extra lookup does not mark everything changed.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/observability-compare-two-runs/specs/run-replay-and-dry-run/spec.md#requirement-steps-are-aligned-so-an-extra-step-shows-as-one-difference-req-rcmp-002
	 */
	public function testOneExtraLookupDoesNotMarkEverythingChanged(): void {
		$left  = [$this->tool('Search contacts'), $this->tool('Read file'), $this->tool('Send email')];
		$right = [$this->tool('Search contacts'), $this->tool('Read file'), $this->tool('Read file'), $this->tool('Send email')];

		$result = (new RunComparator())->compare(leftSteps: $left, rightSteps: $right, leftSummary: 'Done', rightSummary: 'Done');

		$marks = array_column($result['steps'], 'mark');
		$this->assertSame(['same', 'same', 'only-right', 'same'], $marks);
		$this->assertSame(1, $result['differences']);
		$this->assertFalse($result['summaryChanged']);
	}//end testOneExtraLookupDoesNotMarkEverythingChanged()

	/**
	 * A step in both runs with another outcome is one difference, not two.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/observability-compare-two-runs/specs/run-replay-and-dry-run/spec.md#requirement-steps-are-aligned-so-an-extra-step-shows-as-one-difference-req-rcmp-002
	 */
	public function testAStepWithAnotherOutcomeIsOneDifference(): void {
		$left  = [$this->tool('Read file'), $this->tool('Send email', 'ok', 300)];
		$right = [$this->tool('Read file'), $this->tool('Send email', 'error', 900)];

		$result = (new RunComparator())->compare(leftSteps: $left, rightSteps: $right, leftSummary: 'Sent', rightSummary: 'Failed');

		$this->assertSame(['same', 'different-outcome'], array_column($result['steps'], 'mark'));
		$this->assertSame(['name' => 'Send email', 'outcome' => 'error', 'durationMs' => 900], $result['steps'][1]['right']);
		$this->assertTrue($result['summaryChanged']);
	}//end testAStepWithAnotherOutcomeIsOneDifference()

	/**
	 * Only tool steps are compared; guardrail and delivery steps are not tool calls.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/observability-compare-two-runs/specs/run-replay-and-dry-run/spec.md#requirement-steps-are-aligned-so-an-extra-step-shows-as-one-difference-req-rcmp-002
	 */
	public function testOnlyToolStepsAreAligned(): void {
		$left  = [['type' => 'guardrail', 'name' => 'Input check'], $this->tool('Read file')];
		$right = [$this->tool('Read file'), ['type' => 'delivery', 'name' => 'Talk']];

		$result = (new RunComparator())->compare(leftSteps: $left, rightSteps: $right, leftSummary: '', rightSummary: '');

		$this->assertSame(['same'], array_column($result['steps'], 'mark'));
		$this->assertSame(0, $result['differences']);
	}//end testOnlyToolStepsAreAligned()
}//end class

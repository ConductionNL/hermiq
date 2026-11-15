<?php

/**
 * Golden test of rubric scoring through the judge call.
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
 * @spec openspec/changes/agent-evals/specs/agent-evals/spec.md#requirement-llm-as-judge-scoring-goes-through-the-existing-providerfactory-chokepoint
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service;

use OCA\Hermiq\Service\EvalScoringService;
use OCA\Hermiq\Service\Llm\ModelPolicyViolationException;
use OCA\Hermiq\Service\Llm\ProviderFactory;
use OCA\Hermiq\Service\Llm\ProviderUnavailableException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

/**
 * Every rubric outcome, with the exact judge call it makes.
 *
 * @covers \OCA\Hermiq\Service\EvalScoringService
 * @uses \OCA\Hermiq\Service\JudgedOutput
 *
 * @spec openspec/changes/agent-evals/specs/agent-evals/spec.md#requirement-llm-as-judge-scoring-goes-through-the-existing-providerfactory-chokepoint
 */
class EvalRubricScoringGoldenTest extends TestCase {

	/**
	 * The rubric outcomes: case fields, judge answer (string or throwable), expected result.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: string|Throwable|null, 2: array<string, mixed>}>
	 */
	public static function cases(): array {
		$rubric = ['expectationType' => 'rubric', 'rubric' => 'Is it polite?', 'prompt' => 'hi'];
		return [
			'missing rubric' => [
				['expectationType' => 'rubric'],
				null,
				['passed' => false, 'errorMessage' => 'Case is missing rubric.', 'score' => null, 'judgeRationale' => null],
			],
			'default threshold passes at it' => [
				$rubric,
				'{"score": 0.7, "rationale": "fine"}',
				['passed' => true, 'errorMessage' => null, 'score' => 0.7, 'judgeRationale' => 'fine'],
			],
			'default threshold fails below it' => [
				$rubric,
				'{"score": 0.69, "rationale": "meh"}',
				['passed' => false, 'errorMessage' => null, 'score' => 0.69, 'judgeRationale' => 'meh'],
			],
			'numeric string threshold' => [
				$rubric + ['rubricPassThreshold' => '0.95'],
				'{"score": 0.9, "rationale": "close"}',
				['passed' => false, 'errorMessage' => null, 'score' => 0.9, 'judgeRationale' => 'close'],
			],
			'non-numeric threshold ignored' => [
				$rubric + ['rubricPassThreshold' => 'high'],
				'{"score": 0.9, "rationale": "ok"}',
				['passed' => true, 'errorMessage' => null, 'score' => 0.9, 'judgeRationale' => 'ok'],
			],
			'policy violation' => [
				$rubric,
				new ModelPolicyViolationException(message: 'blocked by policy', code: 422),
				['passed' => false, 'errorMessage' => 'blocked by policy', 'score' => null, 'judgeRationale' => null],
			],
			'provider unavailable' => [
				$rubric,
				new ProviderUnavailableException(message: 'no provider'),
				['passed' => false, 'errorMessage' => 'no provider', 'score' => null, 'judgeRationale' => null],
			],
			'other failure' => [
				$rubric,
				new RuntimeException('socket closed'),
				['passed' => false, 'errorMessage' => 'Judge call failed: socket closed', 'score' => null, 'judgeRationale' => null],
			],
			'unparseable' => [
				$rubric,
				'no score here',
				[
					'passed' => false,
					'errorMessage' => 'Could not parse a numeric score from the judge response.',
					'score' => null,
					'judgeRationale' => null,
				],
			],
		];

	}//end cases()

	/**
	 * Each outcome gives the recorded result after the recorded judge call.
	 *
	 * @param array<string, mixed> $case     The EvalCase.
	 * @param string|Throwable|null $answer  The judge answer, or null when no call is expected.
	 * @param array<string, mixed> $expected The result.
	 *
	 * @return void
	 *
	 * @dataProvider cases
	 */
	public function testRubricOutcome(array $case, string|Throwable|null $answer, array $expected): void {
		$calls = [];
		$factory = $this->createMock(originalClassName: ProviderFactory::class);
		$factory->method('generateText')->willReturnCallback(
			static function (...$arguments) use (&$calls, $answer): string {
				$calls[] = $arguments;
				if ($answer instanceof Throwable) {
					throw $answer;
				}

				return (string)$answer;
			}
		);

		$result = (new EvalScoringService(providerFactory: $factory))->score(case: $case, actualOutput: 'Hello there', organisation: 'org-7');

		$this->assertSame(expected: $expected, actual: $result);
		if ($answer === null) {
			$this->assertSame(expected: [], actual: $calls);
			return;
		}

		$this->assertCount(expectedCount: 1, haystack: $calls);
		$this->assertSame(expected: [null, true, 'org-7'], actual: array_slice($calls[0], 1, 3));
		$this->assertStringContainsString(needle: 'Is it polite?', haystack: $calls[0][0]);
		$this->assertStringContainsString(needle: 'Hello there', haystack: $calls[0][0]);

	}//end testRubricOutcome()
}//end class

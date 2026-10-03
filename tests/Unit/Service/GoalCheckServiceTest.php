<?php

/**
 * Goal checks (agents-standing-goal): an object count against its target, and a
 * judge question through the rubric scorer.
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
 * @spec openspec/changes/agents-standing-goal/specs/agent-schedule/spec.md#requirement-goal-turns-continue-the-same-session-through-the-scheduled-run-gates-req-aggoal-002
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service;

use OCA\Hermiq\Service\EvalScoringService;
use OCA\Hermiq\Service\GoalCheckService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;

/**
 * Tests for GoalCheckService.
 *
 * @spec openspec/changes/agents-standing-goal/specs/agent-schedule/spec.md#requirement-goal-turns-continue-the-same-session-through-the-scheduled-run-gates-req-aggoal-002
 */
class GoalCheckServiceTest extends TestCase {

	/**
	 * An object service that holds N matching objects and records the query.
	 *
	 * @param int $count The objects.
	 *
	 * @return ObjectService
	 */
	private function objects(int $count): ObjectService {
		return new class($count) extends ObjectService {
			/** @var array<string, mixed> */
			public array $seen = [];

			public function __construct(private int $count) {
			}

			public function setRegister(mixed $register): static {
				$this->seen['register'] = $register;
				return $this;
			}

			public function setSchema(mixed $schema): static {
				$this->seen['schema'] = $schema;
				return $this;
			}

			public function findAll(array $config = [], bool $_rbac = true, bool $_multitenancy = true): array {
				$this->seen['config'] = $config;
				$this->seen['rbac'] = $_rbac;
				return array_fill(0, $this->count, new ObjectEntity());
			}
		};
	}//end objects()

	/**
	 * The count reads as the current user (RBAC on) with the goal's filters.
	 *
	 * @return void
	 */
	public function testAnObjectCountIsReachedAtItsTarget(): void {
		$check = ['kind' => 'objectCount', 'register' => 'permits', 'schema' => 'application', 'filters' => ['status' => 'overdue'], 'target' => 0];

		$four = $this->objects(4);
		$result = (new GoalCheckService($four, $this->createMock(EvalScoringService::class)))->check($check, '', '');
		$this->assertFalse($result['reached']);
		$this->assertSame(4, $result['value']);
		$this->assertSame('4 left, target 0', $result['summary']);
		$this->assertTrue($four->seen['rbac']);
		$this->assertSame(['status' => 'overdue'], $four->seen['config']['filters']);
		$this->assertSame('permits', $four->seen['register']);

		$this->assertTrue((new GoalCheckService($this->objects(0), $this->createMock(EvalScoringService::class)))->check($check, '', '')['reached']);
	}//end testAnObjectCountIsReachedAtItsTarget()

	/**
	 * The judge is the rubric scorer, with the goal's question and the organisation.
	 *
	 * @return void
	 */
	public function testAJudgeAnswersThroughTheRubricScorer(): void {
		$judge = $this->createMock(EvalScoringService::class);
		$judge->expects($this->once())->method('score')
			->with($this->callback(static fn (array $case): bool => $case['expectationType'] === 'rubric' && str_contains($case['rubric'], 'Is every reminder sent?')), 'All sent.', 'org-1')
			->willReturn(['passed' => true, 'errorMessage' => null, 'score' => 1.0, 'judgeRationale' => 'Every reminder went out.']);

		$result = (new GoalCheckService($this->objects(0), $judge))->check(['kind' => 'judge', 'question' => 'Is every reminder sent?'], 'All sent.', 'org-1');
		$this->assertTrue($result['reached']);
		$this->assertSame('Every reminder went out.', $result['summary']);
	}//end testAJudgeAnswersThroughTheRubricScorer()
}//end class

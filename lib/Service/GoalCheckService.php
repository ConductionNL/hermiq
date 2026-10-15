<?php

/**
 * Hermiq GoalCheckService.
 *
 * Runs a standing goal's check after a turn (agents-standing-goal). Two kinds:
 * `objectCount` counts the objects that match the goal's filters as the person
 * who set the goal (the caller runs it under that identity, RBAC on), and is
 * reached when the count equals the target; `judge` asks the model, through
 * EvalScoringService's rubric scorer and so through the provider chokepoint,
 * whether the session's last answer reaches the goal.
 *
 * @category Service
 * @package  OCA\Hermiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/agents-standing-goal/specs/agent-schedule/spec.md#requirement-goal-turns-continue-the-same-session-through-the-scheduled-run-gates-req-aggoal-002
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service;

use OCA\OpenRegister\Service\ObjectService;
use Throwable;

/**
 * Check whether a goal is reached.
 *
 * @spec openspec/changes/agents-standing-goal/specs/agent-schedule/spec.md#requirement-goal-turns-continue-the-same-session-through-the-scheduled-run-gates-req-aggoal-002
 */
class GoalCheckService {

	/**
	 * The most objects an objectCount check reads; a count above it reads as this many.
	 */
	public const COUNT_CAP = 500;

	/**
	 * Constructor.
	 *
	 * @param ObjectService      $objectService Object reads as the current user.
	 * @param EvalScoringService $scoring       The rubric judge (provider chokepoint).
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly EvalScoringService $scoring,
	) {
	}//end __construct()

	/**
	 * Run a goal's check.
	 *
	 * @param array<string, mixed> $check        The goal's check.
	 * @param string               $lastAnswer   The session's last answer (judge only).
	 * @param string               $organisation The agent's organisation (judge model policy).
	 *
	 * @return array{reached: bool, value: int|null, summary: string}
	 *
	 * @spec openspec/changes/agents-standing-goal/specs/agent-schedule/spec.md#requirement-goal-turns-continue-the-same-session-through-the-scheduled-run-gates-req-aggoal-002
	 */
	public function check(array $check, string $lastAnswer, string $organisation): array {
		$kind = (string)($check['kind'] ?? '');
		if ($kind === 'objectCount') {
			return $this->countCheck(check: $check);
		}

		if ($kind === 'judge') {
			return $this->judgeCheck(question: (string)($check['question'] ?? ''), lastAnswer: $lastAnswer, organisation: $organisation);
		}

		return ['reached' => false, 'value' => null, 'summary' => 'Unknown check kind.'];
	}//end check()

	/**
	 * Count the matching objects as the current user and compare with the target.
	 *
	 * @param array<string, mixed> $check The objectCount check.
	 *
	 * @return array{reached: bool, value: int|null, summary: string}
	 */
	private function countCheck(array $check): array {
		$register = (string)($check['register'] ?? '');
		$schema   = (string)($check['schema'] ?? '');
		$target   = (int)($check['target'] ?? 0);
		if ($register === '' || $schema === '') {
			return ['reached' => false, 'value' => null, 'summary' => 'The check names no register or schema.'];
		}

		$config = ['limit' => self::COUNT_CAP];
		$filters = ($check['filters'] ?? []);
		if (is_array($filters) === true && $filters !== []) {
			$config['filters'] = $filters;
		}

		try {
			$objects = $this->objectService->setRegister($register)->setSchema($schema)->findAll(config: $config);
		} catch (Throwable $e) {
			return ['reached' => false, 'value' => null, 'summary' => 'The count could not be read: ' . $e->getMessage()];
		}

		$count = count($objects);

		return [
			'reached' => $count === $target,
			'value' => $count,
			'summary' => sprintf('%d left, target %d', $count, $target),
		];
	}//end countCheck()

	/**
	 * Ask the judge whether the last answer reaches the goal.
	 *
	 * @param string $question     The judge question.
	 * @param string $lastAnswer   The session's last answer.
	 * @param string $organisation The organisation (model policy and budget).
	 *
	 * @return array{reached: bool, value: int|null, summary: string}
	 */
	private function judgeCheck(string $question, string $lastAnswer, string $organisation): array {
		if (trim($question) === '') {
			return ['reached' => false, 'value' => null, 'summary' => 'The check asks no question.'];
		}

		$orgOrNull = null;
		if ($organisation !== '') {
			$orgOrNull = $organisation;
		}

		$verdict = $this->scoring->score(
			case: ['expectationType' => 'rubric', 'rubric' => 'Answer yes only if the goal is reached: ' . $question],
			actualOutput: $lastAnswer,
			organisation: $orgOrNull
		);

		return [
			'reached' => ($verdict['passed'] ?? false) === true,
			'value' => null,
			'summary' => (string)($verdict['judgeRationale'] ?? $verdict['errorMessage'] ?? ''),
		];
	}//end judgeCheck()
}//end class

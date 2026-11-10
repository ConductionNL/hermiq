<?php

/**
 * Hermiq LearnerSignalReader
 *
 * Reads one learner's signal objects from the learner-signal register, at the
 * slug that register actually answers to on this instance.
 *
 * The pairing with {@see LearnerSignalRegister} is the point: that class answers
 * "can this register be read here, and under which slug", and this one does the
 * reading with the answer. The slug is a PARAMETER on every method here and is
 * never a literal, because a literal is wrong on half the estate and wrong
 * silently. OpenRegister finds no register, matches no rows, and returns the
 * empty list a register holding no matching objects returns.
 *
 * Split out of `CourseRecommendationEngine`, which owns the ranking and the
 * governance gates and had grown past the class-length threshold. The seam is
 * the one this change already drew: resolve, then read.
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
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/ai-course-recommendations/tasks.md#task-2-5
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Cross-app learner-signal reads, each degrading independently.
 *
 * @spec openspec/changes/ai-course-recommendations/specs/course-recommendations/spec.md
 */
class LearnerSignalReader {

	/**
	 * Scholiq schema slug: Enrolment.
	 *
	 * @var string
	 */
	private const SCHEMA_ENROLMENT = 'enrolment';

	/**
	 * Scholiq schema slug: Course.
	 *
	 * @var string
	 */
	private const SCHEMA_COURSE = 'course';

	/**
	 * Scholiq schema slug: XapiStatement.
	 *
	 * @var string
	 */
	private const SCHEMA_XAPI_STATEMENT = 'xapi-statement';

	/**
	 * Scholiq schema slug: LearningPlan.
	 *
	 * @var string
	 */
	private const SCHEMA_LEARNING_PLAN = 'learning-plan';

	/**
	 * Scholiq schema slug: the wave-2 competency-gap signal (does not exist in
	 * Scholiq at this revision — reads speculatively and degrades to "unavailable"
	 * per design.md "Cross-app signal read").
	 *
	 * @var string
	 */
	private const SCHEMA_COMPETENCY_ATTAINMENT = 'competency-attainment';

	/**
	 * Every signal collect() reads, in read order: schema, learner filter field, limit.
	 *
	 * Courses are deliberately UNfiltered by lifecycle: scoreCandidates() needs every
	 * course the learner is already enrolled in (which may be archived) to resolve
	 * curriculum-path/mandatory-renewal signals from it, not just published
	 * candidates. Candidate ELIGIBILITY (published only) is enforced inside
	 * scoreCandidates(), not at the query layer.
	 *
	 * Competency attainment is optional and speculative (the schema does not exist in
	 * Scholiq at this revision; a Throwable from an unknown schema degrades identically
	 * to a read failure).
	 *
	 * @var array<string, array{0: string, 1: string|null, 2: int}>
	 */
	private const SIGNAL_READS = [
		'enrolments' => [self::SCHEMA_ENROLMENT, 'learnerId', 200],
		'courses' => [self::SCHEMA_COURSE, null, 500],
		'xapiStatements' => [self::SCHEMA_XAPI_STATEMENT, 'verified_actor_id', 500],
		'plans' => [self::SCHEMA_LEARNING_PLAN, 'learnerId', 200],
		'competency' => [self::SCHEMA_COMPETENCY_ATTAINMENT, 'learnerId', 200],
	];

	/**
	 * Constructor.
	 *
	 * @param ObjectService   $objectService OpenRegister read path.
	 * @param LoggerInterface $logger        PSR-3 logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Read every learner-signal source for one learner (each independently
	 * degraded, per readSignal()) and derive the `signalsUsed` summary + the
	 * flattened OPEN-goal / competency-gap-id lists the scorer needs.
	 *
	 * @param string $learnerUid The learner's NC user id.
	 * @param string $registerSlug The slug the learner-signal register answers to here.
	 *
	 * @return array{
	 *     courses: array<int, array<string, mixed>>,
	 *     enrolments: array<int, array<string, mixed>>,
	 *     xapiStatements: array<int, array<string, mixed>>,
	 *     goals: array<int, array<string, mixed>>,
	 *     gapIds: array<int, string>,
	 *     signalsUsed: array<string, mixed>
	 * }
	 *
	 * @spec openspec/changes/ai-course-recommendations/tasks.md#task-2-5
	 */
	public function collect(string $learnerUid, string $registerSlug): array {
		$scope = new LearnerSignalScope(learnerUid: $learnerUid, register: $registerSlug);
		$signals = [];
		foreach (array_keys(self::SIGNAL_READS) as $signal) {
			$signals[$signal] = $this->readSignal(scope: $scope, signal: $signal);
		}

		return $this->summarise(signals: $signals);

	}//end collect()

	/**
	 * Shape the read signals into what the scorer needs, failed reads as empty lists.
	 *
	 * @param array<string, array<int, array<string, mixed>>|null> $signals Each signal's rows, or null on failure.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/ai-course-recommendations/tasks.md#task-2-5
	 */
	private function summarise(array $signals): array {
		$competencyRows = ($signals['competency'] ?? []);
		$collected = [
			'courses' => ($signals['courses'] ?? []),
			'enrolments' => ($signals['enrolments'] ?? []),
			'xapiStatements' => ($signals['xapiStatements'] ?? []),
			'goals' => $this->openGoals(plans: ($signals['plans'] ?? [])),
			'gapIds' => $this->competencyGapIds(rows: $competencyRows),
		];
		$collected['signalsUsed'] = $this->signalsUsed(collected: $collected, competencyAvailable: ($competencyRows !== []));

		return $collected;

	}//end summarise()

	/**
	 * The `signalsUsed` summary of one collection.
	 *
	 * @param array<string, array<int, mixed>> $collected           The shaped signals.
	 * @param bool                             $competencyAvailable Whether any competency row was read.
	 *
	 * @return array<string, int|bool>
	 *
	 * @spec openspec/changes/ai-course-recommendations/tasks.md#task-2-5
	 */
	private function signalsUsed(array $collected, bool $competencyAvailable): array {
		return [
			'enrolmentCount' => count($collected['enrolments']),
			'completedCourseCount' => $this->completedCount(enrolments: $collected['enrolments']),
			'xapiStatementCount' => count($collected['xapiStatements']),
			'goalCount' => count($collected['goals']),
			'competencyDataAvailable' => $competencyAvailable,
		];

	}//end signalsUsed()

	/**
	 * The open goals of the learning plans; a goal without a status is open.
	 *
	 * @param array<int, array<string, mixed>> $plans The learning plans.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/ai-course-recommendations/tasks.md#task-2-5
	 */
	private function openGoals(array $plans): array {
		$goals = [];
		foreach ($plans as $plan) {
			foreach ((array)($plan['goals'] ?? []) as $goal) {
				if (is_array($goal) === true && (string)($goal['status'] ?? 'open') === 'open') {
					$goals[] = $goal;
				}
			}
		}

		return $goals;
	}//end openGoals()

	/**
	 * The non-empty competency ids of the competency attainment rows.
	 *
	 * @param array<int, array<string, mixed>> $rows The competency attainment rows.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/changes/ai-course-recommendations/tasks.md#task-2-5
	 */
	private function competencyGapIds(array $rows): array {
		$gapIds = [];
		foreach ($rows as $row) {
			$id = (string)($row['competencyId'] ?? '');
			if ($id !== '') {
				$gapIds[] = $id;
			}
		}

		return $gapIds;
	}//end competencyGapIds()

	/**
	 * The number of enrolments whose lifecycle is `completed`.
	 *
	 * @param array<int, array<string, mixed>> $enrolments The learner's enrolments.
	 *
	 * @return int
	 *
	 * @spec openspec/changes/ai-course-recommendations/tasks.md#task-2-5
	 */
	private function completedCount(array $enrolments): int {
		$completed = 0;
		foreach ($enrolments as $enrolment) {
			if ((string)($enrolment['lifecycle'] ?? '') === 'completed') {
				$completed++;
			}
		}

		return $completed;
	}//end completedCount()

	/**
	 * Read one Scholiq signal schema, independently degrading to null on ANY
	 * failure — a missing/unknown schema (the not-yet-shipped
	 * `competency-attainment`) and a transient OpenRegister error are treated
	 * identically (spec.md "Cross-app signal reads degrade gracefully").
	 *
	 * @param LearnerSignalScope $scope  The learner and the RESOLVED register slug, from
	 *                                   {@see LearnerSignalRegister}. Never a literal: with
	 *                                   the other slug this read matches nothing and returns
	 *                                   the empty list an empty register returns, so a wrong
	 *                                   value here is silent by construction.
	 * @param string             $signal A key of SIGNAL_READS.
	 *
	 * @return array<int, array<string, mixed>>|null The object payloads (each carries
	 *                                               `_uuid`), or null on failure.
	 *
	 * @spec openspec/changes/ai-course-recommendations/tasks.md#task-2-5
	 */
	private function readSignal(LearnerSignalScope $scope, string $signal): ?array {
		[$schema, $learnerField, $limit] = self::SIGNAL_READS[$signal];
		try {
			$objects = $this->objectService
				->setRegister($scope->register)
				->setSchema($schema)
				->findAll(config: ['filters' => $scope->filtersOn(field: $learnerField), 'limit' => $limit]);
		} catch (Throwable $e) {
			$this->logger->warning(
				'[CourseRecommendationEngine] Scholiq signal read failed for schema "' . $schema . '": ' . $e->getMessage(),
				['exception' => $e]
			);
			return null;
		}

		$out = [];
		foreach ($objects as $object) {
			if ($object instanceof ObjectEntity) {
				$out[] = array_merge($object->getObject(), ['_uuid' => (string)$object->getUuid()]);
			}
		}

		return $out;
	}//end readSignal()
}//end class

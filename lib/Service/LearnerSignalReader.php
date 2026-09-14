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
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) Five independent, sequential signal
	 *   reads (each its own degrade branch by design — spec.md "Cross-app signal reads
	 *   degrade gracefully") plus the goal/gap derivation they feed; splitting further
	 *   would scatter the single place `signalsUsed` is assembled.
	 *
	 * @spec openspec/changes/ai-course-recommendations/tasks.md#task-2-5
	 */
	public function collect(string $learnerUid, string $registerSlug): array {
		$enrolments = $this->readSignal(
			register: $registerSlug,
			schema: self::SCHEMA_ENROLMENT,
			filters: ['learnerId' => $learnerUid],
			limit: 200
		) ?? [];
		// Deliberately UNfiltered by lifecycle: scoreCandidates() needs every course
		// the learner is already enrolled in (which may be archived) to resolve
		// curriculum-path/mandatory-renewal signals from it, not just published
		// candidates. Candidate ELIGIBILITY (published only) is enforced inside
		// scoreCandidates(), not at the query layer.
		$courses = $this->readSignal(register: $registerSlug, schema: self::SCHEMA_COURSE, filters: [], limit: 500) ?? [];
		$xapiStatements = $this->readSignal(
			register: $registerSlug,
			schema: self::SCHEMA_XAPI_STATEMENT,
			filters: ['verified_actor_id' => $learnerUid],
			limit: 500
		) ?? [];
		$plans = $this->readSignal(
			register: $registerSlug,
			schema: self::SCHEMA_LEARNING_PLAN,
			filters: ['learnerId' => $learnerUid],
			limit: 200
		) ?? [];
		// Optional, speculative (schema does not exist in Scholiq at this revision;
		// a Throwable from an unknown schema degrades identically to a read failure).
		$competencyRaw = $this->readSignal(
			register: $registerSlug,
			schema: self::SCHEMA_COMPETENCY_ATTAINMENT,
			filters: ['learnerId' => $learnerUid],
			limit: 200
		);

		$goals = [];
		foreach ($plans as $plan) {
			foreach ((array)($plan['goals'] ?? []) as $goal) {
				if (is_array($goal) === true && (string)($goal['status'] ?? 'open') === 'open') {
					$goals[] = $goal;
				}
			}
		}

		$hasCompetencyData = ($competencyRaw !== null && $competencyRaw !== []);
		$gapIds = [];
		if ($hasCompetencyData === true) {
			foreach ($competencyRaw as $row) {
				$id = (string)($row['competencyId'] ?? '');
				if ($id !== '') {
					$gapIds[] = $id;
				}
			}
		}

		$completedCount = 0;
		foreach ($enrolments as $enrolment) {
			if ((string)($enrolment['lifecycle'] ?? '') === 'completed') {
				$completedCount++;
			}
		}

		return [
			'courses' => $courses,
			'enrolments' => $enrolments,
			'xapiStatements' => $xapiStatements,
			'goals' => $goals,
			'gapIds' => $gapIds,
			'signalsUsed' => [
				'enrolmentCount' => count($enrolments),
				'completedCourseCount' => $completedCount,
				'xapiStatementCount' => count($xapiStatements),
				'goalCount' => count($goals),
				'competencyDataAvailable' => $hasCompetencyData,
			],
		];

	}//end collect()

	/**
	 * Read one Scholiq signal schema, independently degrading to null on ANY
	 * failure — a missing/unknown schema (the not-yet-shipped
	 * `competency-attainment`) and a transient OpenRegister error are treated
	 * identically (spec.md "Cross-app signal reads degrade gracefully").
	 *
	 * @param string $register The RESOLVED register slug, from {@see LearnerSignalRegister}.
	 *                         Never a literal: with the other slug this read matches
	 *                         nothing and returns the empty list an empty register
	 *                         returns, so a wrong value here is silent by construction.
	 * @param string $schema Scholiq schema slug.
	 * @param array<string, mixed> $filters OpenRegister filter map.
	 * @param int $limit Max objects to read.
	 *
	 * @return array<int, array<string, mixed>>|null The object payloads (each carries
	 *                                               `_uuid`), or null on failure.
	 *
	 * @spec openspec/changes/ai-course-recommendations/tasks.md#task-2-5
	 */
	private function readSignal(string $register, string $schema, array $filters, int $limit): ?array {
		try {
			$objects = $this->objectService
				->setRegister($register)
				->setSchema($schema)
				->findAll(config: ['filters' => $filters, 'limit' => $limit]);
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

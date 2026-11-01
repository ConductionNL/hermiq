<?php

/**
 * Hermiq CourseCandidateScorer (ai-course-recommendations).
 *
 * The pure, deterministic candidate filter and weighted score behind
 * CourseRecommendationEngine::scoreCandidates(). No I/O: the same learner data
 * always yields the same candidate set, rank, score and matched signals.
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
 * @spec openspec/changes/ai-course-recommendations/specs/course-recommendations/spec.md
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service;

use DateTimeImmutable;

/**
 * Deterministic weighted-signal scorer for course candidates.
 *
 * The learner evidence is indexed once per call by CourseEvidenceIndexer; every
 * signal check takes that evidence and one candidate.
 *
 * @psalm-import-type Evidence from CourseEvidenceIndexer
 * @psalm-type Candidate = array{uuid: array-key, course: array<string, mixed>}
 *
 * @spec openspec/changes/ai-course-recommendations/specs/course-recommendations/spec.md
 */
class CourseCandidateScorer {

	/**
	 * Named signal: candidate overlaps an open LearningPlan goal.
	 *
	 * @var string
	 */
	public const SIGNAL_GOAL_ALIGNMENT = 'goal-alignment';

	/**
	 * Named signal: candidate continues a programme/parent the learner is already in.
	 *
	 * @var string
	 */
	public const SIGNAL_CURRICULUM_PATH = 'curriculum-path';

	/**
	 * Named signal: candidate is the renewal course for a completed mandatory course.
	 *
	 * @var string
	 */
	public const SIGNAL_MANDATORY_RENEWAL = 'mandatory-renewal';

	/**
	 * Named signal: the learner has recent xAPI activity on a sibling/parent course.
	 *
	 * @var string
	 */
	public const SIGNAL_ENGAGEMENT_RECENCY = 'engagement-recency';

	/**
	 * Named signal: candidate closes a documented competency gap (optional).
	 *
	 * @var string
	 */
	public const SIGNAL_COMPETENCY_GAP = 'competency-gap';

	/**
	 * Deterministic weight per signal (a plain weighted sum — see design.md
	 * "Ranking approach"; not a trained/learned model).
	 *
	 * @var array<string, float>
	 */
	private const SIGNAL_WEIGHTS = [
		self::SIGNAL_GOAL_ALIGNMENT => 40.0,
		self::SIGNAL_CURRICULUM_PATH => 30.0,
		self::SIGNAL_MANDATORY_RENEWAL => 50.0,
		self::SIGNAL_ENGAGEMENT_RECENCY => 20.0,
		self::SIGNAL_COMPETENCY_GAP => 25.0,
	];

	/**
	 * Fixed enum order `matchedSignals` is emitted in, for deterministic output.
	 *
	 * @var array<int, string>
	 */
	private const SIGNAL_ORDER = [
		self::SIGNAL_GOAL_ALIGNMENT,
		self::SIGNAL_CURRICULUM_PATH,
		self::SIGNAL_MANDATORY_RENEWAL,
		self::SIGNAL_ENGAGEMENT_RECENCY,
		self::SIGNAL_COMPETENCY_GAP,
	];

	/**
	 * Scores the learner's candidate courses.
	 *
	 * Candidate set = published courses minus courses the learner has an
	 * active/completed enrolment for. A candidate with zero matched signals is
	 * excluded, since it cannot be explained.
	 *
	 * @param array<string, array<int, mixed>> $learner Keys `courses`, `enrolments`,
	 *                                                  `xapiStatements`, `goals`, `gapIds`.
	 * @param DateTimeImmutable $now The current time (recency window anchor).
	 *
	 * @return array{candidateCount: int, recommendations: array<int, array<string, mixed>>}
	 *
	 * @spec openspec/changes/ai-course-recommendations/specs/course-recommendations/spec.md#requirement-ranking-is-deterministic-and-reproducible-explanation-phrasing-is-optional-and-never-changes-it
	 */
	public function score(array $learner, DateTimeImmutable $now): array {
		$evidence = (new CourseEvidenceIndexer())->index(learner: $learner, now: $now);

		$candidateCount = 0;
		$scored = [];
		foreach ($evidence['courses'] as $uuid => $course) {
			if ((string)($course['lifecycle'] ?? '') !== 'published' || isset($evidence['excluded'][$uuid]) === true) {
				continue;
			}

			$candidateCount++;
			$signals = $this->signalsFor(evidence: $evidence, candidate: ['uuid' => $uuid, 'course' => $course]);
			if ($signals === []) {
				continue;
			}

			$scored[] = [
				'courseId' => $uuid,
				'courseCode' => (string)($course['code'] ?? ''),
				'courseName' => (string)($course['name'] ?? ''),
				'score' => $this->weightOf(signals: $signals),
				'matchedSignals' => $signals,
			];
		}

		return ['candidateCount' => $candidateCount, 'recommendations' => $this->ranked(scored: $scored)];
	}//end score()

	/**
	 * The signals one candidate matches, unique and in SIGNAL_ORDER.
	 *
	 * @param array<string, mixed> $evidence The indexed learner evidence.
	 * @param array<string, mixed> $candidate The candidate's `uuid` and `course`.
	 *
	 * @return array<int, string>
	 *
	 * @psalm-param Evidence $evidence
	 * @psalm-param Candidate $candidate
	 */
	private function signalsFor(array $evidence, array $candidate): array {
		$course = $candidate['course'];
		$checks = [
			self::SIGNAL_GOAL_ALIGNMENT => $this->alignsWithGoal(course: $course, haystacks: $evidence['goalHaystacks']),
			self::SIGNAL_CURRICULUM_PATH => $this->continuesPath(course: $course, evidence: $evidence),
			self::SIGNAL_MANDATORY_RENEWAL => $this->isRenewal(course: $course, renewalSlugs: $evidence['renewalSlugs']),
			self::SIGNAL_ENGAGEMENT_RECENCY => $this->followsRecentActivity(evidence: $evidence, candidate: $candidate),
			self::SIGNAL_COMPETENCY_GAP => $this->closesGap(course: $course, gaps: $evidence['gaps']),
		];

		$signals = array_keys(array_filter($checks));
		usort(
			$signals,
			static fn (string $a, string $b): int => array_search($a, self::SIGNAL_ORDER, true) <=> array_search($b, self::SIGNAL_ORDER, true)
		);

		return $signals;
	}//end signalsFor()

	/**
	 * Whether any of the course's tags occurs in an open goal's text.
	 *
	 * @param array<string, mixed> $course The candidate course.
	 * @param array<int, string> $haystacks The open goals' texts.
	 *
	 * @return bool
	 */
	private function alignsWithGoal(array $course, array $haystacks): bool {
		// Array_filter's default callback drops empty tags.
		$tags = array_filter(array_map('strtolower', array_map('strval', (array)($course['tags'] ?? []))));
		foreach ($tags as $tag) {
			foreach ($haystacks as $haystack) {
				if (str_contains($haystack, $tag) === true) {
					return true;
				}
			}
		}

		return false;
	}//end alignsWithGoal()

	/**
	 * Whether the course's parent or one of its programmes is already continued.
	 *
	 * @param array<string, mixed> $course The candidate course.
	 * @param array<string, mixed> $evidence The indexed learner evidence.
	 *
	 * @return bool
	 *
	 * @psalm-param Evidence $evidence
	 */
	private function continuesPath(array $course, array $evidence): bool {
		$parentId = (string)($course['parentCourseId'] ?? '');
		if ($parentId !== '' && isset($evidence['continuedParents'][$parentId]) === true) {
			return true;
		}

		foreach (array_map('strval', (array)($course['programmeIds'] ?? [])) as $programmeId) {
			if (isset($evidence['continuedProgrammes'][$programmeId]) === true) {
				return true;
			}
		}

		return false;
	}//end continuesPath()

	/**
	 * Whether the course's code is the renewal slug of a completed mandatory course.
	 *
	 * @param array<string, mixed> $course The candidate course.
	 * @param array<string, true> $renewalSlugs The renewal slugs.
	 *
	 * @return bool
	 */
	private function isRenewal(array $course, array $renewalSlugs): bool {
		$code = (string)($course['code'] ?? '');
		return $code !== '' && isset($renewalSlugs[$code]) === true;
	}//end isRenewal()

	/**
	 * Whether the learner was recently active on the candidate's parent or a sibling.
	 *
	 * @param array<string, mixed> $evidence The indexed learner evidence.
	 * @param array<string, mixed> $candidate The candidate's `uuid` and `course`.
	 *
	 * @return bool
	 *
	 * @psalm-param Evidence $evidence
	 * @psalm-param Candidate $candidate
	 */
	private function followsRecentActivity(array $evidence, array $candidate): bool {
		$parentId = (string)($candidate['course']['parentCourseId'] ?? '');
		if ($parentId !== '' && isset($evidence['recent'][$parentId]) === true) {
			return true;
		}

		foreach (($evidence['siblings'][$parentId] ?? []) as $siblingUuid) {
			if ($siblingUuid !== $candidate['uuid'] && isset($evidence['recent'][$siblingUuid]) === true) {
				return true;
			}
		}

		return false;
	}//end followsRecentActivity()

	/**
	 * Whether the course teaches a competency the learner has an open gap in.
	 *
	 * @param array<string, mixed> $course The candidate course.
	 * @param array<string, true> $gaps The gap competency ids.
	 *
	 * @return bool
	 */
	private function closesGap(array $course, array $gaps): bool {
		if ($gaps === []) {
			return false;
		}

		foreach ((array)($course['competencyIds'] ?? []) as $competencyId) {
			if (isset($gaps[(string)$competencyId]) === true) {
				return true;
			}
		}

		return false;
	}//end closesGap()

	/**
	 * Sum of the weights of the matched signals.
	 *
	 * @param array<int, string> $signals The matched signals.
	 *
	 * @return float
	 */
	private function weightOf(array $signals): float {
		$score = 0.0;
		foreach ($signals as $signal) {
			$score += self::SIGNAL_WEIGHTS[$signal];
		}

		return $score;
	}//end weightOf()

	/**
	 * Orders by score desc, then course code asc, and numbers the ranks from 1.
	 *
	 * @param array<int, array<string, mixed>> $scored The scored candidates.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function ranked(array $scored): array {
		usort(
			$scored,
			static function (array $a, array $b): int {
				if ($a['score'] === $b['score']) {
					return $a['courseCode'] <=> $b['courseCode'];
				}

				return $b['score'] <=> $a['score'];
			}
		);

		foreach (array_keys($scored) as $index) {
			$scored[$index]['rank'] = $index + 1;
		}

		return $scored;
	}//end ranked()
}//end class

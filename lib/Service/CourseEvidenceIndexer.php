<?php

/**
 * Hermiq CourseEvidenceIndexer (ai-course-recommendations).
 *
 * Indexes a learner's courses, enrolments, xAPI statements, goals and gap ids
 * into the lookups CourseCandidateScorer's signal checks read. No I/O.
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

use DateInterval;
use DateTimeImmutable;
use Throwable;

/**
 * Builds the per-call evidence lookups for course scoring.
 *
 * @psalm-type Evidence = array{
 *     courses: array<array-key, array<string, mixed>>,
 *     excluded: array<array-key, true>,
 *     continuedParents: array<array-key, true>,
 *     continuedProgrammes: array<string, true>,
 *     renewalSlugs: array<string, true>,
 *     recent: array<array-key, true>,
 *     siblings: array<string, array<int, array-key>>,
 *     goalHaystacks: array<int, string>,
 *     gaps: array<string, true>
 * }
 *
 * @spec openspec/changes/ai-course-recommendations/specs/course-recommendations/spec.md
 */
class CourseEvidenceIndexer {

	/**
	 * Engagement-recency lookback window (days) for the xAPI signal.
	 *
	 * @var int
	 */
	private const ENGAGEMENT_RECENCY_WINDOW_DAYS = 90;

	/**
	 * Indexes the learner data into the lookups the signal checks read.
	 *
	 * @param array<string, array<int, mixed>> $learner Keys `courses`, `enrolments`,
	 *                                                  `xapiStatements`, `goals`, `gapIds`.
	 * @param DateTimeImmutable $now The recency window anchor.
	 *
	 * @return array<string, mixed>
	 *
	 * @psalm-return Evidence
	 *
	 * @spec openspec/changes/ai-course-recommendations/specs/course-recommendations/spec.md#requirement-ranking-is-deterministic-and-reproducible-explanation-phrasing-is-optional-and-never-changes-it
	 */
	public function index(array $learner, DateTimeImmutable $now): array {
		$courses = $this->indexByUuid(courses: $learner['courses']);
		[$excluded, $mandatoryDone] = $this->enrolmentSets(enrolments: $learner['enrolments']);
		[$continuedParents, $continuedProgrammes] = $this->continuedPaths(excluded: $excluded, courses: $courses);

		return [
			'courses' => $courses,
			'excluded' => $excluded,
			'continuedParents' => $continuedParents,
			'continuedProgrammes' => $continuedProgrammes,
			'renewalSlugs' => $this->renewalSlugs(mandatoryDone: $mandatoryDone, courses: $courses),
			'recent' => $this->recentCourseIds(statements: $learner['xapiStatements'], now: $now),
			'siblings' => $this->siblingsByParent(courses: $courses),
			'goalHaystacks' => $this->goalHaystacks(goals: $learner['goals']),
			'gaps' => array_fill_keys(array_map('strval', $learner['gapIds']), true),
		];
	}//end index()

	/**
	 * Courses keyed by their non-empty `_uuid`; a later duplicate wins.
	 *
	 * @param array<int, array<string, mixed>> $courses Course payloads.
	 *
	 * @return array<array-key, array<string, mixed>>
	 */
	private function indexByUuid(array $courses): array {
		$byUuid = [];
		foreach ($courses as $course) {
			$uuid = (string)($course['_uuid'] ?? '');
			if ($uuid !== '') {
				$byUuid[$uuid] = $course;
			}
		}

		return $byUuid;
	}//end indexByUuid()

	/**
	 * Course ids the learner is active in or completed, and the completed mandatory ones.
	 *
	 * @param array<int, array<string, mixed>> $enrolments The learner's enrolment payloads.
	 *
	 * @return array{0: array<array-key, true>, 1: array<array-key, true>}
	 */
	private function enrolmentSets(array $enrolments): array {
		$excluded = [];
		$mandatoryDone = [];
		foreach ($enrolments as $enrolment) {
			$courseId = (string)($enrolment['courseId'] ?? '');
			$lifecycle = (string)($enrolment['lifecycle'] ?? '');
			if ($courseId === '') {
				continue;
			}

			if (in_array($lifecycle, ['active', 'completed'], true) === true) {
				$excluded[$courseId] = true;
			}

			if ($this->isMandatoryDone(enrolment: $enrolment) === true) {
				$mandatoryDone[$courseId] = true;
			}
		}

		return [$excluded, $mandatoryDone];
	}//end enrolmentSets()

	/**
	 * Whether an enrolment is a completed mandatory course.
	 *
	 * @param array<string, mixed> $enrolment One enrolment.
	 *
	 * @return bool
	 */
	private function isMandatoryDone(array $enrolment): bool {
		return ((string)($enrolment['lifecycle'] ?? '') === 'completed' && ($enrolment['mandatory'] ?? false) === true);
	}//end isMandatoryDone()

	/**
	 * Parents and programmes the learner already continues through their enrolments.
	 *
	 * @param array<array-key, true> $excluded Course ids the learner is enrolled in.
	 * @param array<array-key, array<string, mixed>> $courses Courses keyed by uuid.
	 *
	 * @return array{0: array<array-key, true>, 1: array<string, true>}
	 */
	private function continuedPaths(array $excluded, array $courses): array {
		$parents = [];
		$programmes = [];
		foreach (array_keys($excluded) as $enrolledUuid) {
			$parents[$enrolledUuid] = true;
			foreach ((array)($courses[$enrolledUuid]['programmeIds'] ?? []) as $programmeId) {
				$programmes[(string)$programmeId] = true;
			}
		}

		return [$parents, $programmes];
	}//end continuedPaths()

	/**
	 * Renewal slugs of the completed mandatory courses.
	 *
	 * @param array<array-key, true> $mandatoryDone Completed mandatory course ids.
	 * @param array<array-key, array<string, mixed>> $courses Courses keyed by uuid.
	 *
	 * @return array<string, true>
	 */
	private function renewalSlugs(array $mandatoryDone, array $courses): array {
		$slugs = [];
		foreach (array_keys($mandatoryDone) as $mandatoryUuid) {
			$slug = (string)($courses[$mandatoryUuid]['renewalCourseSlug'] ?? '');
			if ($slug !== '') {
				$slugs[$slug] = true;
			}
		}

		return $slugs;
	}//end renewalSlugs()

	/**
	 * Course ids with xAPI activity inside the recency window.
	 *
	 * @param array<int, array<string, mixed>> $statements The learner's xAPI statement payloads.
	 * @param DateTimeImmutable $now The window anchor.
	 *
	 * @return array<array-key, true>
	 */
	private function recentCourseIds(array $statements, DateTimeImmutable $now): array {
		$threshold = $now->sub(new DateInterval('P' . self::ENGAGEMENT_RECENCY_WINDOW_DAYS . 'D'));
		$recent = [];
		foreach ($statements as $statement) {
			$courseId = (string)($statement['courseId'] ?? '');
			if ($courseId === '') {
				continue;
			}

			$statementTime = $this->statementTime(timestamp: ($statement['timestamp'] ?? null));
			if ($statementTime !== null && $statementTime >= $threshold) {
				$recent[$courseId] = true;
			}
		}

		return $recent;
	}//end recentCourseIds()

	/**
	 * A statement's time, or null when it has no readable timestamp.
	 *
	 * @param mixed $timestamp The statement's timestamp.
	 *
	 * @return DateTimeImmutable|null
	 */
	private function statementTime(mixed $timestamp): ?DateTimeImmutable {
		if (is_string($timestamp) === false || $timestamp === '') {
			return null;
		}

		try {
			return new DateTimeImmutable($timestamp);
		} catch (Throwable $e) {
			return null;
		}
	}//end statementTime()

	/**
	 * Course uuids grouped by their non-empty `parentCourseId`.
	 *
	 * @param array<array-key, array<string, mixed>> $courses Courses keyed by uuid.
	 *
	 * @return array<string, array<int, array-key>>
	 */
	private function siblingsByParent(array $courses): array {
		$siblings = [];
		foreach ($courses as $uuid => $course) {
			$parentId = (string)($course['parentCourseId'] ?? '');
			if ($parentId !== '') {
				$siblings[$parentId][] = $uuid;
			}
		}

		return $siblings;
	}//end siblingsByParent()

	/**
	 * Lower-cased `domain description` text of every open goal that has any.
	 *
	 * @param array<int, array<string, mixed>> $goals The learner's open goals.
	 *
	 * @return array<int, string>
	 */
	private function goalHaystacks(array $goals): array {
		$haystacks = [];
		foreach ($goals as $goal) {
			$text = strtolower(
				trim((string)($goal['domain'] ?? '') . ' ' . (string)($goal['description'] ?? ''))
			);
			if ($text !== '') {
				$haystacks[] = $text;
			}
		}

		return $haystacks;
	}//end goalHaystacks()
}//end class

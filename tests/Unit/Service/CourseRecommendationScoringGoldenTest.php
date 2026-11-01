<?php

/**
 * Golden test for CourseRecommendationEngine::scoreCandidates(), CourseCandidateScorer and
 * CourseEvidenceIndexer.
 *
 * Runs the deterministic scoring pass over generated learner data and compares
 * the result with a recorded fixture. Set GOLDEN_RECORD=1 to (re)write the
 * fixture; a run in record mode compares nothing.
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
 * @spec openspec/changes/ai-course-recommendations/specs/course-recommendations/spec.md
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Hermiq\Service\AiFeatureService;
use OCA\Hermiq\Service\CourseRecommendationEngine;
use OCA\Hermiq\Service\LearnerSignalReader;
use OCA\Hermiq\Service\LearnerSignalRegister;
use OCA\Hermiq\Service\Llm\ProviderFactory;
use OCA\Hermiq\Service\ScheduleService;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Golden comparison of the deterministic course scoring.
 *
 * @covers \OCA\Hermiq\Service\CourseRecommendationEngine
 * @covers \OCA\Hermiq\Service\CourseCandidateScorer
 * @covers \OCA\Hermiq\Service\CourseEvidenceIndexer
 *
 * @spec openspec/changes/ai-course-recommendations/specs/course-recommendations/spec.md
 */
class CourseRecommendationScoringGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/../../Fixtures/Golden/course-scoring.json';

	private const CASES = 400;

	private const UUIDS = ['c1', 'c2', 'c3', 'c4', 'c5', 'c6', 'c7', 'c8', '7', '42', ''];

	private const LIFECYCLES = ['published', 'published', 'published', 'draft', 'retired', ''];

	private const TAGS = ['math', 'Science', '', 'ethics', 'data', 'DATA', 'safety'];

	private const WORDS = ['math', 'science', 'ethics', 'data', 'safety', 'law', 'art', ''];

	private const PROGRAMMES = ['p1', 'p2', 'p3', 4];

	private const CODES = ['C-1', 'C-2', 'R-1', ''];

	private const COMPETENCIES = ['k1', 'k2', 'k3', 5];

	private const ENROLMENT_STATES = ['active', 'completed', 'completed', 'withdrawn', ''];

	private const MANDATORY = [true, true, true, false, 'yes', null];

	private const AGES_IN_DAYS = [0, 1, 89, 90, 91, 200];

	private const BAD_TIMESTAMPS = ['not-a-date', '', null, 12345];

	/**
	 * Every generated case scores exactly as recorded.
	 *
	 * @return void
	 */
	public function testScoringMatchesTheRecordedFixture(): void {
		$engine = $this->engine();
		$now = new DateTimeImmutable('2026-06-15T12:00:00+00:00', new DateTimeZone('UTC'));
		$actual = [];
		for ($case = 0; $case < self::CASES; $case++) {
			mt_srand($case + 1);
			$input = $this->generateCase();
			$result = $engine->scoreCandidates(
				$input['courses'],
				$input['enrolments'],
				$this->statements(now: $now),
				$input['goals'],
				$input['gapIds'],
				$now
			);
			$actual[] = json_decode(json_encode($result, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
		}

		if (getenv('GOLDEN_RECORD') === '1') {
			if (is_dir(dirname(self::FIXTURE)) === false) {
				mkdir(dirname(self::FIXTURE), 0777, true);
			}

			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
			$this->markTestSkipped('Fixture recorded.');
		}

		$expected = json_decode((string)file_get_contents(self::FIXTURE), true, 512, JSON_THROW_ON_ERROR);
		$this->assertSame($expected, $actual);
		$this->assertGreaterThan(0, array_sum(array_map(static fn (array $r): int => count($r['recommendations']), $actual)));
	}//end testScoringMatchesTheRecordedFixture()

	/**
	 * Builds the engine; scoring touches none of its collaborators.
	 *
	 * @return CourseRecommendationEngine
	 */
	private function engine(): CourseRecommendationEngine {
		return new CourseRecommendationEngine(
			$this->createMock(ObjectService::class),
			$this->createMock(LearnerSignalRegister::class),
			$this->createMock(LearnerSignalReader::class),
			$this->createMock(AiFeatureService::class),
			$this->createMock(ScheduleService::class),
			$this->createMock(ProviderFactory::class),
			$this->createMock(OrganisationMapper::class),
			$this->createMock(LoggerInterface::class)
		);
	}//end engine()

	/**
	 * Generates one case's courses, enrolments, goals and gap ids from the seeded generator.
	 *
	 * @return array<string, array<int, mixed>>
	 */
	private function generateCase(): array {
		$courses = [];
		for ($i = mt_rand(0, 9); $i > 0; $i--) {
			$courses[] = $this->course();
		}

		$enrolments = [];
		for ($i = mt_rand(0, 5); $i > 0; $i--) {
			$enrolments[] = $this->omitSome(
				payload: [
					'courseId' => $this->pick(pool: self::UUIDS),
					'lifecycle' => $this->pick(pool: self::ENROLMENT_STATES),
					'mandatory' => $this->pick(pool: self::MANDATORY),
				]
			);
		}

		$goals = [];
		for ($i = mt_rand(0, 3); $i > 0; $i--) {
			$goals[] = $this->omitSome(
				payload: [
					'domain' => $this->pick(pool: self::WORDS),
					'description' => 'Learn ' . $this->pick(pool: self::WORDS) . ' and ' . $this->pick(pool: self::WORDS),
				]
			);
		}

		return [
			'courses' => $courses,
			'enrolments' => $enrolments,
			'goals' => $goals,
			'gapIds' => $this->subset(pool: self::COMPETENCIES),
		];
	}//end generateCase()

	/**
	 * One generated course payload.
	 *
	 * @return array<string, mixed>
	 */
	private function course(): array {
		$parents = self::UUIDS;
		$parents[] = 'missing-parent';
		return $this->omitSome(
			payload: [
				'_uuid' => $this->pick(pool: self::UUIDS),
				'lifecycle' => $this->pick(pool: self::LIFECYCLES),
				'tags' => $this->subset(pool: self::TAGS),
				'parentCourseId' => $this->pick(pool: $parents),
				'programmeIds' => $this->subset(pool: self::PROGRAMMES),
				'code' => $this->pick(pool: self::CODES),
				'renewalCourseSlug' => $this->pick(pool: self::CODES),
				'competencyIds' => $this->subset(pool: self::COMPETENCIES),
				'name' => 'Course ' . mt_rand(1, 99),
			]
		);
	}//end course()

	/**
	 * Generated xAPI statements around the recency window.
	 *
	 * @param DateTimeImmutable $now The scoring anchor.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function statements(DateTimeImmutable $now): array {
		$statements = [];
		for ($i = mt_rand(0, 6); $i > 0; $i--) {
			$timestamp = $now->modify('-' . $this->pick(pool: self::AGES_IN_DAYS) . ' days')->format(DATE_ATOM);
			if (mt_rand(0, 5) === 0) {
				$timestamp = $this->pick(pool: self::BAD_TIMESTAMPS);
			}

			$statements[] = $this->omitSome(payload: ['courseId' => $this->pick(pool: self::UUIDS), 'timestamp' => $timestamp]);
		}

		return $statements;
	}//end statements()

	/**
	 * Picks one element.
	 *
	 * @param array<int, mixed> $pool The candidates.
	 *
	 * @return mixed
	 */
	private function pick(array $pool): mixed {
		return $pool[mt_rand(0, count($pool) - 1)];
	}//end pick()

	/**
	 * Picks a random subset, in pool order.
	 *
	 * @param array<int, mixed> $pool The candidates.
	 *
	 * @return array<int, mixed>
	 */
	private function subset(array $pool): array {
		return array_values(array_filter($pool, static fn (): bool => mt_rand(0, 2) === 0));
	}//end subset()

	/**
	 * Drops each key with a small chance, to exercise the missing-key defaults.
	 *
	 * @param array<string, mixed> $payload The full payload.
	 *
	 * @return array<string, mixed>
	 */
	private function omitSome(array $payload): array {
		return array_filter($payload, static fn (): bool => mt_rand(0, 9) !== 0);
	}//end omitSome()
}//end class

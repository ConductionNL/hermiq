<?php

/**
 * Hermiq CourseRecommendationEngine (ai-course-recommendations).
 *
 * The Hermiq-owned, EU-AI-Act-governed next-best-course recommendation engine.
 * Reads a learner's own `Enrolment`, `Course`, `XapiStatement` and `LearningPlan`
 * objects cross-app from Scholiq's OpenRegister register (the mirror direction of
 * `AssessmentPublishGuard.php`'s proven Scholiq→Hermiq read), computes a
 * DETERMINISTIC weighted-signal score per candidate course, and — only once the
 * ranking is fixed — MAY call the credential-broker-backed `ProviderFactory` to
 * phrase (never re-rank) a natural-language explanation.
 *
 * EU AI Act Annex III §3 posture (see design.md "EU AI Act posture" for the full
 * rationale):
 * - The `course-recommendations` AiFeature gate (2.2 below) is checked FIRST,
 *   before any Scholiq read or LLM call — a disabled feature has zero Scholiq/LLM
 *   footprint, not merely a hidden UI. This check also re-runs on every cached-hit
 *   path (getOrRegenerate() never trusts a stale "was enabled" cache entry): once
 *   the DPO/admin disables the feature, serving stops immediately, not after the
 *   24h TTL.
 * - The ranking (scoreCandidates()) is a pure, deterministic weighted-signal sum
 *   over explicit signals — no LLM call, no training data, fully reproducible from
 *   the same input. An optional LLM call (explain()) ONLY phrases the explanation
 *   string for an already-fixed candidate; it can never add, remove, or reorder a
 *   recommendation, and its prompt is scoped to that ONE candidate's own
 *   `matchedSignals` — never the full candidate list or another learner's data.
 * - No `Enrolment` is ever written by this engine — advisory only.
 *
 * Reconciliation note (kill-switch scope): design.md's "Ranking approach" Stage 2
 * and spec.md's Requirement 4 both describe the tenant kill-switch
 * (`ScheduleService::isOrganisationEngaged()`) as skipping ONLY the optional LLM
 * phrasing step (falls back to a deterministic template; ranking still runs).
 * design.md's "Alternatives considered" section describes a DIFFERENT, rejected
 * alternative ("fail the entire recommendation closed") using near-identical
 * language, which reads as an unreconciled draft-stage note. This implementation
 * follows the two normative Requirement-4/Stage-2 sources (the deterministic
 * ranking is not an "agent run" in `ScheduleService`'s own docblock sense, so
 * halting only the LLM step is the internally-consistent reading) — the engine's
 * unit tests assert this behavior explicitly.
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
 * @spec openspec/changes/ai-course-recommendations/tasks.md#2-recommendation-engine-cross-app-read-deterministic-scoring-optional-llm-phrasing
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use OCA\Hermiq\Service\Llm\ChatDriver;
use OCA\Hermiq\Service\Llm\ProviderFactory;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Service\ObjectService;
use OCP\App\IAppManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Cross-app-aware, deterministic-ranking course recommender.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)   Reuses seven existing governance/read
 *   collaborators (feature gate, kill-switch, tenant resolution, LLM broker, cross-app
 *   read, persistence, logging) rather than inventing new ones — see design.md "Goals".
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)     scoreCandidates() and the signal
 *   constants are public specifically so tests can exercise the deterministic ranking
 *   as a pure function without mocking any I/O collaborator (task 2.6).
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) The sum of many small, single-purpose
 *   private helpers (gate checks, five independent signal reads, the pure scorer, the
 *   two-stage explanation pipeline, persistence) — mirrors the accepted precedent in
 *   `ScheduleService`/`TenantOpsService`/`DeliveryService` for governed-pipeline classes.
 *
 * @spec openspec/changes/ai-course-recommendations/specs/course-recommendations/spec.md
 */
class CourseRecommendationEngine {

	/**
	 * OpenRegister register slug that holds Hermiq's own objects.
	 *
	 * @var string
	 */
	private const HERMIQ_REGISTER = 'hermiq';

	/**
	 * Schema slug for CourseRecommendation objects.
	 *
	 * @var string
	 */
	private const SCHEMA_COURSE_RECOMMENDATION = 'courserecommendation';

	/**
	 * AiFeature slug governing this capability (EU AI Act Annex III §3, high-risk).
	 *
	 * @var string
	 */
	private const AIFEATURE_SLUG = 'course-recommendations';

	/**
	 * The optional runtime peer app whose learner-signal schemas this engine reads.
	 *
	 * @var string
	 */
	private const SCHOLIQ_APP_ID = 'scholiq';

	/**
	 * OpenRegister register slug that holds Scholiq's objects.
	 *
	 * @var string
	 */
	private const SCHOLIQ_REGISTER = 'scholiq';

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
	 * Freshness TTL (hours) — a plain constant, not configurable at this revision.
	 *
	 * @var int
	 */
	private const TTL_HOURS = 24;

	/**
	 * How many top-ranked candidates get an attempted LLM-phrased explanation;
	 * the rest always get the deterministic template (cost/latency bound).
	 *
	 * @var int
	 */
	private const TOP_N_FOR_EXPLANATION = 5;

	/**
	 * Engagement-recency lookback window (days) for the xAPI signal.
	 *
	 * @var int
	 */
	private const ENGAGEMENT_RECENCY_WINDOW_DAYS = 90;

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
	 * Constructor.
	 *
	 * @param ObjectService $objectService OpenRegister read/write (single write-path).
	 * @param IAppManager $appManager Tells "Scholiq absent" from "no data".
	 * @param AiFeatureService $aiFeatureService The AiFeature DPO-ack gate (2.2).
	 * @param ScheduleService $scheduleService The tenant kill-switch (2.3/Stage 2).
	 * @param ProviderFactory $providerFactory Credential-broker-backed LLM call.
	 * @param OrganisationMapper $organisationMapper Resolves the caller's own organisation.
	 * @param LoggerInterface $logger PSR-3 logger.
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) Seven distinct, reused collaborators.
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly IAppManager $appManager,
		private readonly AiFeatureService $aiFeatureService,
		private readonly ScheduleService $scheduleService,
		private readonly ProviderFactory $providerFactory,
		private readonly OrganisationMapper $organisationMapper,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Serve the learner's current recommendations, regenerating when stale/absent.
	 *
	 * The AiFeature gate and the Scholiq-installed check run UNCONDITIONALLY first
	 * — even on what would otherwise be a fresh-cache hit — so a feature disabled
	 * (or Scholiq uninstalled) after a recommendation was generated stops being
	 * served immediately, not only after the 24h TTL (EU AI Act fail-closed
	 * posture: "the feature genuinely does not compute or serve anything
	 * pre-acknowledgement", design.md "EU AI Act posture" §1).
	 *
	 * @param string $learnerUid The caller's own Nextcloud user id (self-scoped;
	 *                           callers MUST resolve this from their own session,
	 *                           never from request input — spec.md "Recommendation
	 *                           access is self-scoped").
	 *
	 * @return array<string, mixed> The CourseRecommendation payload (plus `uuid`
	 *                              when persisted).
	 *
	 * @spec openspec/changes/ai-course-recommendations/tasks.md#task-2-8
	 */
	public function getOrRegenerate(string $learnerUid): array {
		$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

		// Gate 1 (2.2): the AiFeature DPO-ack gate — zero Scholiq/LLM footprint
		// when missing or not enabled.
		$feature = $this->aiFeatureService->findBySlug(slug: self::AIFEATURE_SLUG);
		if ($feature === null || (string)($feature->getObject()['lifecycle'] ?? '') !== 'enabled') {
			return $this->unavailableResult(learnerUid: $learnerUid);
		}

		// Gate 2 (2.4): Scholiq installed.
		if ($this->appManager->isInstalled(self::SCHOLIQ_APP_ID) === false) {
			$this->logger->info(
				'[CourseRecommendationEngine] Scholiq is not installed; returning an unavailable recommendation set.'
			);
			return $this->unavailableResult(learnerUid: $learnerUid);
		}

		$existing = $this->findExistingRecommendation(learnerUid: $learnerUid);
		if ($existing !== null) {
			$data = $existing->getObject();
			$staleAt = $data['staleAt'] ?? null;
			if ((string)($data['status'] ?? '') === 'fresh'
				&& is_string($staleAt) === true
				&& $staleAt !== ''
				&& $now < new DateTimeImmutable($staleAt)
			) {
				$data['uuid'] = (string)$existing->getUuid();
				return $data;
			}
		}

		return $this->regenerate(learnerUid: $learnerUid, existing: $existing, now: $now);
	}//end getOrRegenerate()

	/**
	 * Compute a fresh recommendation set (both gates already passed) and persist it.
	 *
	 * @param string $learnerUid The learner's NC user id.
	 * @param ObjectEntity|null $existing The previously-persisted CourseRecommendation, if any.
	 * @param DateTimeImmutable $now The current time (UTC).
	 *
	 * @return array<string, mixed> The persisted (or, on a write failure, in-memory) payload.
	 *
	 * @spec openspec/changes/ai-course-recommendations/tasks.md#task-2-5
	 * @spec openspec/changes/ai-course-recommendations/tasks.md#task-2-6
	 * @spec openspec/changes/ai-course-recommendations/tasks.md#task-2-7
	 */
	private function regenerate(string $learnerUid, ?ObjectEntity $existing, DateTimeImmutable $now): array {
		$organisation = $this->resolveOrganisation(uid: $learnerUid);
		$signals = $this->collectSignals(learnerUid: $learnerUid);

		$ranked = $this->scoreCandidates(
			courses: $signals['courses'],
			enrolments: $signals['enrolments'],
			xapiStatements: $signals['xapiStatements'],
			goals: $signals['goals'],
			gapIds: $signals['gapIds'],
			now: $now
		);

		$killSwitchEngaged = (
			$organisation !== ''
			&& $this->scheduleService->isOrganisationEngaged(organisation: $organisation) === true
		);

		[$recommendations, $explanationMode, $modelUsed] = $this->explain(
			recommendations: $ranked['recommendations'],
			organisation: $organisation,
			killSwitchEngaged: $killSwitchEngaged
		);

		$staleAt = $now->add(new DateInterval('PT' . self::TTL_HOURS . 'H'));
		$viewedAt = null;
		$existingUuid = null;
		if ($existing !== null) {
			$viewedAt = ($existing->getObject()['viewedAt'] ?? null);
			$existingUuid = (string)$existing->getUuid();
		}

		$payload = [
			'learnerId' => $learnerUid,
			'sourceApp' => self::SCHOLIQ_APP_ID,
			'tenantId' => $organisation,
			'status' => 'fresh',
			'generatedAt' => $now->format('c'),
			'staleAt' => $staleAt->format('c'),
			'signalsUsed' => $signals['signalsUsed'],
			'candidateCount' => $ranked['candidateCount'],
			'recommendations' => $recommendations,
			'explanationMode' => $explanationMode,
			'modelUsed' => $modelUsed,
			'viewedAt' => $viewedAt,
		];

		return $this->persist(payload: $payload, existingUuid: $existingUuid);
	}//end regenerate()

	/**
	 * Read every Scholiq signal source for one learner (each independently
	 * degraded, per readSignal()) and derive the `signalsUsed` summary + the
	 * flattened OPEN-goal / competency-gap-id lists the scorer needs.
	 *
	 * @param string $learnerUid The learner's NC user id.
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
	private function collectSignals(string $learnerUid): array {
		$enrolments = $this->readSignal(
			schema: self::SCHEMA_ENROLMENT,
			filters: ['learnerId' => $learnerUid],
			limit: 200
		) ?? [];
		// Deliberately UNfiltered by lifecycle: scoreCandidates() needs every course
		// the learner is already enrolled in (which may be archived) to resolve
		// curriculum-path/mandatory-renewal signals from it, not just published
		// candidates. Candidate ELIGIBILITY (published only) is enforced inside
		// scoreCandidates(), not at the query layer.
		$courses = $this->readSignal(schema: self::SCHEMA_COURSE, filters: [], limit: 500) ?? [];
		$xapiStatements = $this->readSignal(
			schema: self::SCHEMA_XAPI_STATEMENT,
			filters: ['verified_actor_id' => $learnerUid],
			limit: 500
		) ?? [];
		$plans = $this->readSignal(
			schema: self::SCHEMA_LEARNING_PLAN,
			filters: ['learnerId' => $learnerUid],
			limit: 200
		) ?? [];
		// Optional, speculative (schema does not exist in Scholiq at this revision;
		// a Throwable from an unknown schema degrades identically to a read failure).
		$competencyRaw = $this->readSignal(
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

	}//end collectSignals()

	/**
	 * Persist the computed payload via ObjectService (single write-path); on a
	 * write failure, log and return the in-memory payload rather than raising —
	 * the caller already has a fully-computed, correctly-explained result even
	 * when the cache write itself fails.
	 *
	 * @param array<string, mixed> $payload The CourseRecommendation payload.
	 * @param string|null $existingUuid The uuid to update, or null to create.
	 *
	 * @return array<string, mixed> The persisted (or in-memory) payload.
	 */
	private function persist(array $payload, ?string $existingUuid): array {
		try {
			$saved = $this->objectService->saveObject(
				object: $payload,
				register: self::HERMIQ_REGISTER,
				schema: self::SCHEMA_COURSE_RECOMMENDATION,
				uuid: $existingUuid
			);
			$result = $saved->getObject();
			$result['uuid'] = (string)$saved->getUuid();
			return $result;
		} catch (Throwable $e) {
			$this->logger->error(
				'[CourseRecommendationEngine] Could not persist the recommendation set: ' . $e->getMessage(),
				['exception' => $e]
			);
			return $payload;
		}

	}//end persist()

	/**
	 * Pure, deterministic candidate filter + weighted score — no I/O, unit-testable
	 * directly. Same inputs always produce the same `rank`/`score`/`matchedSignals`
	 * (spec.md "Re-running the deterministic stage on the same data yields the same
	 * ranking").
	 *
	 * Candidate set = published Course objects (already filtered by the caller's
	 * read query) minus courses the learner has an active/completed Enrolment for.
	 * A candidate with zero matched signals is excluded — spec.md "A recommendation
	 * is never returned without an explanation" requires at least one matched
	 * signal, so a signal-less candidate cannot be validly explained.
	 *
	 * @param array<int, array<string, mixed>> $courses Course payloads (each carries `_uuid`).
	 * @param array<int, array<string, mixed>> $enrolments The learner's Enrolment payloads.
	 * @param array<int, array<string, mixed>> $xapiStatements The learner's XapiStatement payloads.
	 * @param array<int, array<string, mixed>> $goals The learner's OPEN LearningPlan goals.
	 * @param array<int, string> $gapIds Competency ids representing an open gap.
	 * @param DateTimeImmutable $now The current time (recency window anchor).
	 *
	 * @return array{candidateCount: int, recommendations: array<int, array<string, mixed>>}
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)  One deterministic scoring pass over five
	 *   independent signals — splitting would scatter the single source of truth for the ranking.
	 * @SuppressWarnings(PHPMD.NPathComplexity)       See above.
	 * @SuppressWarnings(PHPMD.ExcessiveMethodLength) See above.
	 *
	 * @spec openspec/changes/ai-course-recommendations/tasks.md#task-2-6
	 * @spec openspec/changes/ai-course-recommendations/specs/course-recommendations/spec.md#requirement-ranking-is-deterministic-and-reproducible-explanation-phrasing-is-optional-and-never-changes-it
	 */
	public function scoreCandidates(
		array $courses,
		array $enrolments,
		array $xapiStatements,
		array $goals,
		array $gapIds,
		DateTimeImmutable $now,
	): array {
		$coursesByUuid = [];
		foreach ($courses as $course) {
			$uuid = (string)($course['_uuid'] ?? '');
			if ($uuid !== '') {
				$coursesByUuid[$uuid] = $course;
			}
		}

		$excludedCourseIds = [];
		$mandatoryDoneIds = [];
		foreach ($enrolments as $enrolment) {
			$courseId = (string)($enrolment['courseId'] ?? '');
			$lifecycle = (string)($enrolment['lifecycle'] ?? '');
			if ($courseId === '') {
				continue;
			}

			if (in_array($lifecycle, ['active', 'completed'], true) === true) {
				$excludedCourseIds[$courseId] = true;
			}

			if ($lifecycle === 'completed' && ($enrolment['mandatory'] ?? false) === true) {
				$mandatoryDoneIds[$courseId] = true;
			}
		}

		// Curriculum-path continuation: programmes/parents the learner already continues.
		$continuedProgrammes = [];
		$continuedParentIds = [];
		foreach (array_keys($excludedCourseIds) as $enrolledUuid) {
			$continuedParentIds[$enrolledUuid] = true;
			$enrolledCourse = $coursesByUuid[$enrolledUuid] ?? null;
			if ($enrolledCourse === null) {
				continue;
			}

			foreach ((array)($enrolledCourse['programmeIds'] ?? []) as $programmeId) {
				$continuedProgrammes[(string)$programmeId] = true;
			}
		}

		// Mandatory-renewal proximity: renewal slugs of completed mandatory courses.
		$renewalSlugs = [];
		foreach (array_keys($mandatoryDoneIds) as $mandatoryUuid) {
			$slug = (string)($coursesByUuid[$mandatoryUuid]['renewalCourseSlug'] ?? '');
			if ($slug !== '') {
				$renewalSlugs[$slug] = true;
			}
		}

		// Engagement recency: course ids with xAPI activity inside the window.
		$recencyThreshold = $now->sub(new DateInterval('P' . self::ENGAGEMENT_RECENCY_WINDOW_DAYS . 'D'));
		$recentCourseIds = [];
		foreach ($xapiStatements as $statement) {
			$courseId = (string)($statement['courseId'] ?? '');
			$timestamp = $statement['timestamp'] ?? null;
			if ($courseId === '' || is_string($timestamp) === false || $timestamp === '') {
				continue;
			}

			try {
				$statementTime = new DateTimeImmutable($timestamp);
			} catch (Throwable $e) {
				continue;
			}

			if ($statementTime >= $recencyThreshold) {
				$recentCourseIds[$courseId] = true;
			}
		}

		// Sibling map: courses sharing the same non-empty parentCourseId.
		$siblingsByParent = [];
		foreach ($coursesByUuid as $uuid => $course) {
			$parentId = (string)($course['parentCourseId'] ?? '');
			if ($parentId === '') {
				continue;
			}

			$siblingsByParent[$parentId][] = $uuid;
		}

		// Goal alignment: haystacks built from every OPEN goal's domain + description.
		$goalHaystacks = [];
		foreach ($goals as $goal) {
			$text = strtolower(
				trim((string)($goal['domain'] ?? '') . ' ' . (string)($goal['description'] ?? ''))
			);
			if ($text !== '') {
				$goalHaystacks[] = $text;
			}
		}

		$gapCompetencyIds = array_fill_keys(array_map('strval', $gapIds), true);

		$candidateCount = 0;
		$scored = [];

		foreach ($coursesByUuid as $uuid => $course) {
			if ((string)($course['lifecycle'] ?? '') !== 'published') {
				continue;
			}

			if (isset($excludedCourseIds[$uuid]) === true) {
				continue;
			}

			$candidateCount++;
			$signals = [];

			// Goal alignment (array_filter's default callback already drops empty tags).
			$tags = array_filter(array_map('strtolower', array_map('strval', (array)($course['tags'] ?? []))));
			foreach ($tags as $tag) {
				foreach ($goalHaystacks as $haystack) {
					if (str_contains($haystack, $tag) === true) {
						$signals[] = self::SIGNAL_GOAL_ALIGNMENT;
						break 2;
					}
				}
			}

			// Curriculum-path continuation.
			$parentId = (string)($course['parentCourseId'] ?? '');
			$programmeIds = array_map('strval', (array)($course['programmeIds'] ?? []));
			$continuesPath = ($parentId !== '' && isset($continuedParentIds[$parentId]) === true);
			if ($continuesPath === false) {
				foreach ($programmeIds as $programmeId) {
					if (isset($continuedProgrammes[$programmeId]) === true) {
						$continuesPath = true;
						break;
					}
				}
			}

			if ($continuesPath === true) {
				$signals[] = self::SIGNAL_CURRICULUM_PATH;
			}

			// Mandatory-renewal proximity.
			$code = (string)($course['code'] ?? '');
			if ($code !== '' && isset($renewalSlugs[$code]) === true) {
				$signals[] = self::SIGNAL_MANDATORY_RENEWAL;
			}

			// Engagement recency (prerequisite = own parent; sibling = shares that parent).
			$engaged = ($parentId !== '' && isset($recentCourseIds[$parentId]) === true);
			if ($engaged === false) {
				foreach (($siblingsByParent[$parentId] ?? []) as $siblingUuid) {
					if ($siblingUuid !== $uuid && isset($recentCourseIds[$siblingUuid]) === true) {
						$engaged = true;
						break;
					}
				}
			}

			if ($engaged === true) {
				$signals[] = self::SIGNAL_ENGAGEMENT_RECENCY;
			}

			// Competency-gap closure (optional; contributes nothing when absent).
			if ($gapCompetencyIds !== []) {
				foreach ((array)($course['competencyIds'] ?? []) as $competencyId) {
					if (isset($gapCompetencyIds[(string)$competencyId]) === true) {
						$signals[] = self::SIGNAL_COMPETENCY_GAP;
						break;
					}
				}
			}

			if ($signals === []) {
				// No evidence → no explanation possible; excluded (spec.md Requirement 4).
				continue;
			}

			$signals = array_values(array_unique($signals));
			usort(
				$signals,
				static fn (string $a, string $b): int => array_search($a, self::SIGNAL_ORDER, true) <=> array_search($b, self::SIGNAL_ORDER, true)
			);

			$score = 0.0;
			foreach ($signals as $signal) {
				// Every $signal here is one of the SIGNAL_* constants this method
				// itself appended above, so the key always exists in SIGNAL_WEIGHTS.
				$score += self::SIGNAL_WEIGHTS[$signal];
			}

			$scored[] = [
				'courseId' => $uuid,
				'courseCode' => $code,
				'courseName' => (string)($course['name'] ?? ''),
				'score' => $score,
				'matchedSignals' => $signals,
			];
		}//end foreach

		// Deterministic order: score desc, then course code asc (stable tie-break).
		usort(
			$scored,
			static function (array $a, array $b): int {
				if ($a['score'] === $b['score']) {
					return $a['courseCode'] <=> $b['courseCode'];
				}

				return $b['score'] <=> $a['score'];
			}
		);

		$rank = 1;
		foreach ($scored as &$entry) {
			$entry['rank'] = $rank;
			$rank++;
		}

		unset($entry);

		return ['candidateCount' => $candidateCount, 'recommendations' => $scored];
	}//end scoreCandidates()

	/**
	 * Optional explanation phrasing (Stage 2) — never alters rank/score/matchedSignals
	 * or the candidate set; only ever sets/overwrites the `explanation` string.
	 *
	 * The tenant kill-switch and a `ProviderUnavailableException` (or any other LLM
	 * failure) are treated identically: fall back to the deterministic template.
	 * The LLM is resolved ONCE per call (not per-candidate) so a genuinely
	 * unavailable provider does not retry N times; a per-candidate call failure
	 * (e.g. a later candidate) still degrades that ONE candidate to its template
	 * without affecting the others already phrased.
	 *
	 * @param array<int, array<string, mixed>> $recommendations The ranked, deterministic candidates.
	 * @param string $organisation The caller's organisation (model-policy scope).
	 * @param bool $killSwitchEngaged Whether the tenant kill-switch is engaged.
	 *
	 * @return array{0: array<int, array<string, mixed>>, 1: string, 2: string|null}
	 *                                                                               [recommendations-with-explanation, explanationMode, modelUsed]
	 *
	 * @spec openspec/changes/ai-course-recommendations/tasks.md#task-2-7
	 * @spec openspec/changes/ai-course-recommendations/specs/course-recommendations/spec.md#requirement-a-recommendation-is-never-returned-without-an-explanation
	 */
	private function explain(array $recommendations, string $organisation, bool $killSwitchEngaged): array {
		$driver = null;
		$explanationMode = 'template';
		$modelUsed = null;

		if ($killSwitchEngaged === false) {
			$driver = $this->resolveLlmDriver(organisation: $organisation);
		}

		foreach ($recommendations as $i => $candidate) {
			$explanation = null;

			if ($driver !== null && $i < self::TOP_N_FOR_EXPLANATION) {
				$explanation = $this->tryLlmExplanation(candidate: $candidate);
				if ($explanation !== null) {
					$explanationMode = 'llm-assisted';
					$modelUsed = $driver->provider . ':' . $driver->model;
				}
			}

			if ($explanation === null) {
				$explanation = $this->buildTemplateExplanation(candidate: $candidate);
			}

			$recommendations[$i]['explanation'] = $explanation;
		}

		return [$recommendations, $explanationMode, $modelUsed];
	}//end explain()

	/**
	 * Resolve the currently-configured chat driver, or null when unavailable —
	 * resolved ONCE per explain() call (not per-candidate) so a genuinely
	 * unavailable provider does not retry N times.
	 *
	 * @param string $organisation The caller's organisation (model-policy scope), or ''.
	 *
	 * @return ChatDriver|null The resolved driver, or null when unavailable.
	 */
	private function resolveLlmDriver(string $organisation): ?ChatDriver {
		$organisationScope = null;
		if ($organisation !== '') {
			$organisationScope = $organisation;
		}

		try {
			$llmConfig = $this->providerFactory->getLlmConfig();
			return $this->providerFactory->createChatDriver(llmConfig: $llmConfig, organisation: $organisationScope);
		} catch (Throwable $e) {
			$this->logger->info(
				'[CourseRecommendationEngine] LLM provider unavailable; using deterministic '
				. 'template explanations: ' . $e->getMessage()
			);
			return null;
		}

	}//end resolveLlmDriver()

	/**
	 * Attempt the LLM-phrased explanation for ONE candidate; a per-candidate
	 * failure degrades only that candidate to its template, without affecting
	 * candidates already phrased.
	 *
	 * @param array<string, mixed> $candidate One scored candidate.
	 *
	 * @return string|null The phrased explanation, or null on failure/empty output.
	 */
	private function tryLlmExplanation(array $candidate): ?string {
		try {
			$text = trim($this->providerFactory->generateText(prompt: $this->buildExplanationPrompt(candidate: $candidate)));
			if ($text !== '') {
				return $text;
			}

			return null;
		} catch (Throwable $e) {
			$this->logger->info(
				'[CourseRecommendationEngine] LLM phrasing failed for one candidate; using the '
				. 'deterministic template for it: ' . $e->getMessage()
			);
			return null;
		}

	}//end tryLlmExplanation()

	/**
	 * Build the LLM explanation prompt for ONE candidate — scoped strictly to that
	 * candidate's own `matchedSignals`; never includes the full candidate list or
	 * any other learner's data (spec.md "LLM phrasing does not change which
	 * courses are recommended").
	 *
	 * @param array<string, mixed> $candidate One scored candidate.
	 *
	 * @return string The prompt.
	 */
	private function buildExplanationPrompt(array $candidate): string {
		$labels = array_map(fn (string $signal): string => $this->signalLabel(signal: $signal), (array)($candidate['matchedSignals'] ?? []));

		return 'Write one or two short, plain-language sentences (no markdown, no lists) explaining to a '
			. 'learner why the course "' . ($candidate['courseName'] ?? '') . '" (' . ($candidate['courseCode'] ?? '') . ') '
			. 'is being recommended, based strictly on these matched signals and nothing else: '
			. implode('; ', $labels) . '. Do not invent facts beyond these signals. Do not mention any other course.';

	}//end buildExplanationPrompt()

	/**
	 * Build the deterministic, template-built explanation for one candidate,
	 * grounded strictly in its own `matchedSignals` — never absent (spec.md "A
	 * recommendation is never returned without an explanation").
	 *
	 * @param array<string, mixed> $candidate One scored candidate.
	 *
	 * @return string The explanation.
	 */
	private function buildTemplateExplanation(array $candidate): string {
		$labels = array_map(fn (string $signal): string => $this->signalLabel(signal: $signal), (array)($candidate['matchedSignals'] ?? []));
		$courseName = (string)($candidate['courseName'] ?? 'This course');
		if ($labels === []) {
			// Defensive only — scoreCandidates() already excludes signal-less candidates.
			return $courseName . ' is recommended.';
		}

		return $courseName . ' is recommended because it ' . implode('; and it ', $labels) . '.';
	}//end buildTemplateExplanation()

	/**
	 * Human-readable label for one named signal (used by both explanation paths).
	 *
	 * @param string $signal One of the SIGNAL_* constants.
	 *
	 * @return string The label.
	 */
	private function signalLabel(string $signal): string {
		return match ($signal) {
			self::SIGNAL_GOAL_ALIGNMENT => 'aligns with one of your open learning-plan goals',
			self::SIGNAL_CURRICULUM_PATH => 'continues a curriculum path you are already enrolled in',
			self::SIGNAL_MANDATORY_RENEWAL => 'is the renewal training for a mandatory course you completed',
			self::SIGNAL_ENGAGEMENT_RECENCY => 'follows on from courses you have recently been active in',
			self::SIGNAL_COMPETENCY_GAP => 'closes a documented competency gap',
			default => 'matches your learning profile',
		};

	}//end signalLabel()

	/**
	 * Read one Scholiq signal schema, independently degrading to null on ANY
	 * failure — a missing/unknown schema (the not-yet-shipped
	 * `competency-attainment`) and a transient OpenRegister error are treated
	 * identically (spec.md "Cross-app signal reads degrade gracefully").
	 *
	 * @param string $schema Scholiq schema slug.
	 * @param array<string, mixed> $filters OpenRegister filter map.
	 * @param int $limit Max objects to read.
	 *
	 * @return array<int, array<string, mixed>>|null The object payloads (each carries
	 *                                               `_uuid`), or null on failure.
	 *
	 * @spec openspec/changes/ai-course-recommendations/tasks.md#task-2-5
	 */
	private function readSignal(string $schema, array $filters, int $limit): ?array {
		try {
			$objects = $this->objectService
				->setRegister(self::SCHOLIQ_REGISTER)
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

	/**
	 * Find the learner's existing persisted CourseRecommendation, if any.
	 *
	 * @param string $learnerUid The learner's NC user id.
	 *
	 * @return ObjectEntity|null The object, or null when absent.
	 */
	private function findExistingRecommendation(string $learnerUid): ?ObjectEntity {
		$objects = $this->objectService
			->setRegister(self::HERMIQ_REGISTER)
			->setSchema(self::SCHEMA_COURSE_RECOMMENDATION)
			->findAll(config: ['filters' => ['learnerId' => $learnerUid], 'limit' => 1]);

		foreach ($objects as $object) {
			if ($object instanceof ObjectEntity) {
				return $object;
			}
		}

		return null;
	}//end findExistingRecommendation()

	/**
	 * Resolve the caller's own organisation (for the kill-switch check + model
	 * policy scope) — reuses `OrganisationMapper::findByUserId()`, the same
	 * primitive `DashboardController::provideKillSwitchCapability()` already
	 * uses. A read failure degrades to "no organisation" (kill-switch check
	 * no-ops; the AiFeature gate remains the primary fail-closed control).
	 *
	 * @param string $uid The Nextcloud user id.
	 *
	 * @return string The organisation UUID, or an empty string.
	 */
	private function resolveOrganisation(string $uid): string {
		try {
			$organisations = $this->organisationMapper->findByUserId($uid);
		} catch (Throwable $e) {
			$this->logger->warning(
				'[CourseRecommendationEngine] Could not resolve an organisation for "' . $uid . '": ' . $e->getMessage()
			);
			return '';
		}

		foreach ($organisations as $organisation) {
			$uuid = (string)$organisation->getUuid();
			if ($uuid !== '') {
				return $uuid;
			}
		}

		return '';
	}//end resolveOrganisation()

	/**
	 * The in-memory "unavailable" result for a fully-gated request — never
	 * persisted (zero Scholiq/LLM footprint per the AiFeature-disabled /
	 * Scholiq-absent gates).
	 *
	 * @param string $learnerUid The learner's NC user id.
	 *
	 * @return array<string, mixed> The unavailable payload.
	 */
	private function unavailableResult(string $learnerUid): array {
		return [
			'learnerId' => $learnerUid,
			'sourceApp' => self::SCHOLIQ_APP_ID,
			'tenantId' => '',
			'status' => 'unavailable',
			'generatedAt' => null,
			'staleAt' => null,
			'signalsUsed' => [
				'enrolmentCount' => 0,
				'completedCourseCount' => 0,
				'xapiStatementCount' => 0,
				'goalCount' => 0,
				'competencyDataAvailable' => false,
			],
			'candidateCount' => 0,
			'recommendations' => [],
			'explanationMode' => 'template',
			'modelUsed' => null,
			'viewedAt' => null,
		];

	}//end unavailableResult()
}//end class

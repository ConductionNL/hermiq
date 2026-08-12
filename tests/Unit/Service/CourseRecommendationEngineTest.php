<?php

/**
 * Unit tests for CourseRecommendationEngine (ai-course-recommendations).
 *
 * Proves the change's central design invariant: ranking is a DETERMINISTIC
 * weighted-signal score, and an optional LLM call may ONLY phrase the explanation
 * of an already-fixed candidate — it can never re-rank, add, or remove courses.
 * Also covers every graceful-degrade seam (feature disabled/absent, Scholiq
 * absent, a single signal read failure, the optional competency-gap signal
 * absent/present, the LLM provider unavailable, the tenant kill-switch) and the
 * fail-closed AiFeature gate (zero Scholiq/LLM footprint until DPO-enabled, and
 * re-checked on every call — not just once per TTL window).
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
 * @spec openspec/changes/ai-course-recommendations/tasks.md#task-6-1
 * @spec openspec/changes/ai-course-recommendations/specs/course-recommendations/spec.md
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Hermiq\Service\AiFeatureService;
use OCA\Hermiq\Service\CourseRecommendationEngine;
use OCA\Hermiq\Service\Llm\ChatDriver;
use OCA\Hermiq\Service\Llm\ProviderFactory;
use OCA\Hermiq\Service\Llm\ProviderUnavailableException;
use OCA\Hermiq\Service\ScheduleService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Service\ObjectService;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for the ai-course-recommendations deterministic ranking engine.
 *
 * @spec openspec/changes/ai-course-recommendations/specs/course-recommendations/spec.md
 */
class CourseRecommendationEngineTest extends TestCase {

	/**
	 * A stateful ObjectService test double: setSchema() records the active schema
	 * so findAll()/saveObject() can behave differently per schema within a single
	 * engine call — mirrors BudgetServiceTest's proven pattern (a plain PHPUnit
	 * mock cannot express per-schema fluent-chain behavior without brittle
	 * consecutive-call ordering).
	 *
	 * @param array<string, array<int, ObjectEntity>> $bySchema Schema slug → objects findAll() returns.
	 * @param array<int, string> $throwFor Schema slugs whose findAll() throws.
	 * @param array<int, array<string, mixed>> &$saved Captures every saveObject() payload.
	 * @param array<int, string> &$schemaCallLog Every schema findAll() was invoked for.
	 *
	 * @return ObjectService
	 */
	private function objectService(
		array $bySchema,
		array $throwFor,
		array &$saved,
		array &$schemaCallLog,
	): ObjectService {
		return new class($bySchema, $throwFor, $saved, $schemaCallLog) extends ObjectService {
			private ?string $schema = null;

			/**
			 * @param array<string, array<int, ObjectEntity>> $bySchema Schema slug → objects.
			 * @param array<int, string> $throwFor Schema slugs that throw.
			 * @param array<int, array<string, mixed>> $saved Captured saveObject() payloads.
			 * @param array<int, string> $schemaCallLog Every schema findAll() was called for.
			 */
			public function __construct(
				private array $bySchema,
				private array $throwFor,
				private array &$saved,
				private array &$schemaCallLog,
			) {
			}

			public function setRegister(mixed $register): static {
				return $this;
			}

			public function setSchema(mixed $schema): static {
				$this->schema = (string)$schema;
				return $this;
			}

			public function findAll(array $config = [], bool $_rbac = true, bool $_multitenancy = true): array {
				$this->schemaCallLog[] = (string)$this->schema;
				if (in_array($this->schema, $this->throwFor, true) === true) {
					throw new RuntimeException('simulated read failure for ' . $this->schema);
				}

				return ($this->bySchema[$this->schema] ?? []);
			}

			public function saveObject(
				array|ObjectEntity $object,
				?array $extend = [],
				mixed $register = null,
				mixed $schema = null,
				?string $uuid = null,
				bool $_rbac = true,
				bool $_multitenancy = true,
				bool $silent = false,
				?array $uploadedFiles = null,
				?\OCP\IUser $currentUser = null,
				// openregister#2211 (insert-only saves) added this. A double that
				// drifts from the real signature is a FATAL, not a failed
				// assertion: PHP refuses to declare the class and the whole
				// suite dies before it runs.
				bool $failIfExists = false,
			): ObjectEntity {
				$payload = is_array($object) ? $object : $object->getObject();
				$this->saved[] = $payload;
				$entity = new ObjectEntity();
				$entity->setUuid($uuid ?? 'new-recommendation');
				$entity->setObject($payload);
				return $entity;
			}
		};

	}//end objectService()

	/**
	 * An ObjectEntity fixture.
	 *
	 * @param string $uuid The entity uuid.
	 * @param array<string, mixed> $data The object payload.
	 *
	 * @return ObjectEntity
	 */
	private function entity(string $uuid, array $data): ObjectEntity {
		$e = new ObjectEntity();
		$e->setUuid($uuid);
		$e->setObject($data);
		return $e;
	}//end entity()

	/**
	 * The standard five-signal fixture: one candidate course per named signal
	 * (goal-alignment, curriculum-path, mandatory-renewal, engagement-recency,
	 * competency-gap), plus an already-enrolled course that must be excluded.
	 *
	 * @param DateTimeImmutable $now The reference "now" for the recency signal.
	 *
	 * @return array<string, array<int, ObjectEntity>>
	 */
	private function fiveSignalBySchema(DateTimeImmutable $now): array {
		$recentTimestamp = $now->modify('-1 day')->format('c');

		return [
			'course' => [
				// Goal-alignment: tag "security" matches the open goal below.
				$this->entity('course-a', ['code' => 'ADV-1', 'name' => 'Advanced Security', 'tags' => ['security'], 'lifecycle' => 'published']),
				// Curriculum-path: shares programme "prog-1" with the enrolled course-c.
				$this->entity('course-b', ['code' => 'MOD-2', 'name' => 'Programme Module 2', 'programmeIds' => ['prog-1'], 'lifecycle' => 'published']),
				// Enrolled (active) — excluded from candidates, but its programmeIds seed curriculum-path.
				$this->entity('course-c', ['code' => 'MOD-1', 'name' => 'Programme Module 1', 'programmeIds' => ['prog-1'], 'lifecycle' => 'published']),
				// Mandatory-renewal: is the renewal target of the completed mandatory course-e.
				$this->entity('course-d', ['code' => 'NIS2-RENEW', 'name' => 'NIS2 Renewal', 'lifecycle' => 'published']),
				// Completed mandatory (archived — must still resolve for the renewal lookup).
				$this->entity('course-e', ['code' => 'NIS2-INIT', 'name' => 'NIS2 Initial', 'renewalCourseSlug' => 'NIS2-RENEW', 'lifecycle' => 'archived']),
				// Engagement-recency: its own parentCourseId ("course-g") has recent xAPI activity.
				$this->entity('course-f', ['code' => 'FOLLOWUP-1', 'name' => 'Follow-up Module', 'parentCourseId' => 'course-g', 'lifecycle' => 'published']),
				// Competency-gap: closes the documented gap "comp-1".
				$this->entity('course-h', ['code' => 'COMP-1', 'name' => 'Competency Closer', 'competencyIds' => ['comp-1'], 'lifecycle' => 'published']),
			],
			'enrolment' => [
				$this->entity('enr-1', ['learnerId' => 'alice', 'courseId' => 'course-c', 'lifecycle' => 'active', 'mandatory' => false]),
				$this->entity('enr-2', ['learnerId' => 'alice', 'courseId' => 'course-e', 'lifecycle' => 'completed', 'mandatory' => true]),
			],
			'xapi-statement' => [
				$this->entity('xapi-1', ['verified_actor_id' => 'alice', 'courseId' => 'course-g', 'timestamp' => $recentTimestamp]),
			],
			'learning-plan' => [
				$this->entity(
					'plan-1',
					[
						'learnerId' => 'alice',
						'goals' => [
							['goalId' => 'g1', 'description' => 'improve security skills', 'domain' => 'security', 'status' => 'open'],
						],
					]
				),
			],
			'competency-attainment' => [
				$this->entity('gap-1', ['competencyId' => 'comp-1']),
			],
		];

	}//end fiveSignalBySchema()

	/**
	 * Build the engine with the given collaborators (sane defaults for anything
	 * a test does not care about).
	 *
	 * @param ObjectService $objectService The (stateful) ObjectService double.
	 * @param ObjectEntity|null $feature The AiFeature lookup result.
	 * @param bool $scholiqInstalled Whether Scholiq is installed.
	 * @param bool $killSwitchEngaged Whether the tenant kill-switch is engaged.
	 * @param ProviderFactory|null $providerFactory A specific LLM provider double, or a plain mock.
	 * @param array<int, Organisation> $organisations The learner's resolved organisations.
	 *
	 * @return CourseRecommendationEngine
	 */
	private function engine(
		ObjectService $objectService,
		?ObjectEntity $feature,
		bool $scholiqInstalled = true,
		bool $killSwitchEngaged = false,
		?ProviderFactory $providerFactory = null,
		array $organisations = [],
	): CourseRecommendationEngine {
		$aiFeatureService = $this->createMock(AiFeatureService::class);
		$aiFeatureService->method('findBySlug')->willReturn($feature);

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->willReturn($scholiqInstalled);

		$scheduleService = $this->createMock(ScheduleService::class);
		$scheduleService->method('isOrganisationEngaged')->willReturn($killSwitchEngaged);

		$organisationMapper = $this->createMock(OrganisationMapper::class);
		$organisationMapper->method('findByUserId')->willReturn($organisations);

		return new CourseRecommendationEngine(
			$objectService,
			$appManager,
			$aiFeatureService,
			$scheduleService,
			$providerFactory ?? $this->createMock(ProviderFactory::class),
			$organisationMapper,
			$this->createMock(LoggerInterface::class)
		);

	}//end engine()

	/**
	 * An enabled AiFeature fixture.
	 *
	 * @return ObjectEntity
	 */
	private function enabledFeature(): ObjectEntity {
		return $this->entity('feat-1', ['slug' => 'course-recommendations', 'lifecycle' => 'enabled']);
	}//end enabledFeature()

	/**
	 * An org fixture with the given uuid.
	 *
	 * @param string $uuid The organisation uuid.
	 *
	 * @return Organisation
	 */
	private function organisation(string $uuid): Organisation {
		// Real entity, not a mock: the real Organisation resolves getUuid()
		// via Entity magic, unmockable under a server tree.
		$org = new Organisation();
		$org->setUuid($uuid);
		return $org;
	}//end organisation()

	/**
	 * A disabled/missing AiFeature must short-circuit to `unavailable` with ZERO
	 * Scholiq reads and ZERO LLM calls — the gate has zero data-access footprint,
	 * not merely a hidden UI (spec.md "Recommendations are unavailable before DPO
	 * acknowledgement").
	 *
	 * @return void
	 *
	 * @spec openspec/changes/ai-course-recommendations/specs/course-recommendations/spec.md#scenario-recommendations-are-unavailable-before-dpo-acknowledgement
	 */
	public function testFeatureDisabledShortCircuitsWithZeroScholiqOrLlmFootprint(): void {
		$saved = [];
		$schemaCallLog = [];
		$objectService = $this->objectService(
			bySchema: $this->fiveSignalBySchema(new DateTimeImmutable('now', new DateTimeZone('UTC'))),
			throwFor: [],
			saved: $saved,
			schemaCallLog: $schemaCallLog
		);

		$providerFactory = $this->createMock(ProviderFactory::class);
		$providerFactory->expects($this->never())->method('generateText');
		$providerFactory->expects($this->never())->method('createChatDriver');

		$disabledFeature = $this->entity('feat-1', ['slug' => 'course-recommendations', 'lifecycle' => 'disabled']);
		$engine = $this->engine(objectService: $objectService, feature: $disabledFeature, providerFactory: $providerFactory);

		$result = $engine->getOrRegenerate(learnerUid: 'alice');

		$this->assertSame('unavailable', $result['status']);
		$this->assertSame([], $result['recommendations']);
		$this->assertSame([], $schemaCallLog, 'A disabled feature must make zero ObjectService findAll() calls.');
		$this->assertSame([], $saved, 'A disabled feature must never persist anything.');

	}//end testFeatureDisabledShortCircuitsWithZeroScholiqOrLlmFootprint()

	/**
	 * A NULL feature (seed step has not run yet) degrades identically to disabled.
	 *
	 * @return void
	 */
	public function testMissingFeatureAlsoDegradesToUnavailable(): void {
		$saved = [];
		$schemaCallLog = [];
		$objectService = $this->objectService(bySchema: [], throwFor: [], saved: $saved, schemaCallLog: $schemaCallLog);

		$engine = $this->engine(objectService: $objectService, feature: null);

		$result = $engine->getOrRegenerate(learnerUid: 'alice');

		$this->assertSame('unavailable', $result['status']);
		$this->assertSame([], $schemaCallLog);

	}//end testMissingFeatureAlsoDegradesToUnavailable()

	/**
	 * Scholiq not installed degrades to `unavailable` without an exception and
	 * without any Scholiq/hermiq ObjectService read (spec.md "Scholiq not installed").
	 *
	 * @return void
	 *
	 * @spec openspec/changes/ai-course-recommendations/specs/course-recommendations/spec.md#scenario-scholiq-not-installed
	 */
	public function testScholiqNotInstalledDegradesToUnavailable(): void {
		$saved = [];
		$schemaCallLog = [];
		$objectService = $this->objectService(bySchema: [], throwFor: [], saved: $saved, schemaCallLog: $schemaCallLog);

		$engine = $this->engine(objectService: $objectService, feature: $this->enabledFeature(), scholiqInstalled: false);

		$result = $engine->getOrRegenerate(learnerUid: 'alice');

		$this->assertSame('unavailable', $result['status']);
		$this->assertSame([], $schemaCallLog);

	}//end testScholiqNotInstalledDegradesToUnavailable()

	/**
	 * The deterministic scoring stage produces the SAME rank/score/matchedSignals
	 * when run twice on the same input — the central "deterministic, reproducible"
	 * invariant, exercised as a pure function with no I/O at all.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/ai-course-recommendations/specs/course-recommendations/spec.md#scenario-re-running-the-deterministic-stage-on-the-same-data-yields-the-same-ranking
	 */
	public function testDeterministicScoringIsIdempotent(): void {
		$now = new DateTimeImmutable('2026-07-13T12:00:00+00:00');
		$bySchema = $this->fiveSignalBySchema($now);

		$courses = array_map(fn (ObjectEntity $e) => array_merge($e->getObject(), ['_uuid' => $e->getUuid()]), $bySchema['course']);
		$enrolments = array_map(fn (ObjectEntity $e) => $e->getObject(), $bySchema['enrolment']);
		$xapiStatements = array_map(fn (ObjectEntity $e) => $e->getObject(), $bySchema['xapi-statement']);
		$goals = $bySchema['learning-plan'][0]->getObject()['goals'];
		$gapIds = ['comp-1'];

		$unused1 = [];
		$unused2 = [];
		$engine = $this->engine(
			objectService: $this->objectService(bySchema: [], throwFor: [], saved: $unused1, schemaCallLog: $unused2),
			feature: $this->enabledFeature()
		);

		$first = $engine->scoreCandidates($courses, $enrolments, $xapiStatements, $goals, $gapIds, $now);
		$second = $engine->scoreCandidates($courses, $enrolments, $xapiStatements, $goals, $gapIds, $now);

		$this->assertSame($first, $second);

		// And the ranking is exactly the five non-enrolled candidates, ordered by
		// weight (mandatory-renewal 50 > goal-alignment 40 > curriculum-path 30 >
		// competency-gap 25 > engagement-recency 20), course-c excluded (active enrolment).
		$ids = array_column($first['recommendations'], 'courseId');
		$this->assertSame(['course-d', 'course-a', 'course-b', 'course-h', 'course-f'], $ids);
		$this->assertNotContains('course-c', $ids, 'An actively-enrolled course must never be a candidate.');

		$byId = array_combine($ids, $first['recommendations']);
		$this->assertSame([CourseRecommendationEngine::SIGNAL_MANDATORY_RENEWAL], $byId['course-d']['matchedSignals']);
		$this->assertSame([CourseRecommendationEngine::SIGNAL_GOAL_ALIGNMENT], $byId['course-a']['matchedSignals']);
		$this->assertSame([CourseRecommendationEngine::SIGNAL_CURRICULUM_PATH], $byId['course-b']['matchedSignals']);
		$this->assertSame([CourseRecommendationEngine::SIGNAL_COMPETENCY_GAP], $byId['course-h']['matchedSignals']);
		$this->assertSame([CourseRecommendationEngine::SIGNAL_ENGAGEMENT_RECENCY], $byId['course-f']['matchedSignals']);

	}//end testDeterministicScoringIsIdempotent()

	/**
	 * When the optional competency-attainment signal is absent (unknown schema —
	 * treated as ANY read failure), the ranking still computes from the remaining
	 * four signals, `competencyDataAvailable` is false, and no candidate claims a
	 * competency-gap match.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/ai-course-recommendations/specs/course-recommendations/spec.md#scenario-optional-competency-gap-signal-is-absent
	 */
	public function testOptionalCompetencyGapSignalAbsentDegradesGracefully(): void {
		$now = new DateTimeImmutable('2026-07-13T12:00:00+00:00');
		$bySchema = $this->fiveSignalBySchema($now);
		unset($bySchema['competency-attainment']);

		$saved = [];
		$schemaCallLog = [];
		$objectService = $this->objectService(
			bySchema: $bySchema,
			throwFor: ['competency-attainment'],
			saved: $saved,
			schemaCallLog: $schemaCallLog
		);

		$engine = $this->engine(objectService: $objectService, feature: $this->enabledFeature(), organisations: [$this->organisation('org-1')]);

		$result = $engine->getOrRegenerate(learnerUid: 'alice');

		$this->assertSame('fresh', $result['status']);
		$this->assertFalse($result['signalsUsed']['competencyDataAvailable']);

		foreach ($result['recommendations'] as $recommendation) {
			$this->assertNotContains(CourseRecommendationEngine::SIGNAL_COMPETENCY_GAP, $recommendation['matchedSignals']);
		}

		// course-h (whose ONLY signal is competency-gap) must not appear at all —
		// it has zero matched signals without the gap data, so it is excluded.
		$ids = array_column($result['recommendations'], 'courseId');
		$this->assertNotContains('course-h', $ids);

	}//end testOptionalCompetencyGapSignalAbsentDegradesGracefully()

	/**
	 * A single failing signal read (XapiStatement) is logged and does not abort
	 * the computation — the ranking is still produced from the remaining signals.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/ai-course-recommendations/specs/course-recommendations/spec.md#scenario-a-single-signal-read-fails-without-failing-the-whole-computation
	 */
	public function testSingleFailingSignalReadDoesNotAbortTheComputation(): void {
		$now = new DateTimeImmutable('2026-07-13T12:00:00+00:00');
		$bySchema = $this->fiveSignalBySchema($now);

		$saved = [];
		$schemaCallLog = [];
		$objectService = $this->objectService(
			bySchema: $bySchema,
			throwFor: ['xapi-statement'],
			saved: $saved,
			schemaCallLog: $schemaCallLog
		);

		$engine = $this->engine(objectService: $objectService, feature: $this->enabledFeature(), organisations: [$this->organisation('org-1')]);

		$result = $engine->getOrRegenerate(learnerUid: 'alice');

		$this->assertSame('fresh', $result['status']);
		$this->assertSame(0, $result['signalsUsed']['xapiStatementCount']);

		$ids = array_column($result['recommendations'], 'courseId');
		// The engagement-recency-only candidate (course-f) has zero matched signals
		// without xAPI data and is excluded; the other four signals still fire.
		$this->assertNotContains('course-f', $ids);
		$this->assertContains('course-a', $ids);
		$this->assertContains('course-d', $ids);

	}//end testSingleFailingSignalReadDoesNotAbortTheComputation()

	/**
	 * The tenant kill-switch skips ONLY the optional LLM phrasing step — the
	 * deterministic ranking still runs and every recommendation still carries a
	 * (template) explanation (design.md "Ranking approach" Stage 2 guardrail /
	 * spec.md Requirement 4 "Tenant kill-switch engaged skips the LLM call
	 * entirely").
	 *
	 * @return void
	 *
	 * @spec openspec/changes/ai-course-recommendations/specs/course-recommendations/spec.md#scenario-tenant-kill-switch-engaged-skips-the-llm-call-entirely
	 */
	public function testKillSwitchEngagedSkipsOnlyTheLlmStep(): void {
		$now = new DateTimeImmutable('2026-07-13T12:00:00+00:00');
		$bySchema = $this->fiveSignalBySchema($now);

		$saved = [];
		$schemaCallLog = [];
		$objectService = $this->objectService(bySchema: $bySchema, throwFor: [], saved: $saved, schemaCallLog: $schemaCallLog);

		$providerFactory = $this->createMock(ProviderFactory::class);
		$providerFactory->expects($this->never())->method('getLlmConfig');
		$providerFactory->expects($this->never())->method('createChatDriver');
		$providerFactory->expects($this->never())->method('generateText');

		$engine = $this->engine(
			objectService: $objectService,
			feature: $this->enabledFeature(),
			killSwitchEngaged: true,
			providerFactory: $providerFactory,
			organisations: [$this->organisation('org-1')]
		);

		$result = $engine->getOrRegenerate(learnerUid: 'alice');

		$this->assertSame('fresh', $result['status']);
		$this->assertSame('template', $result['explanationMode']);
		$this->assertNull($result['modelUsed']);
		$this->assertNotEmpty($result['recommendations'], 'Ranking must still run when only the kill-switch is engaged.');

		foreach ($result['recommendations'] as $recommendation) {
			$this->assertNotSame('', $recommendation['explanation']);
		}

	}//end testKillSwitchEngagedSkipsOnlyTheLlmStep()

	/**
	 * When no LLM provider is configured (`ProviderUnavailableException`), every
	 * recommendation still gets a non-empty, deterministic template explanation
	 * and `explanationMode` is `template` — the explanation is never absent.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/ai-course-recommendations/specs/course-recommendations/spec.md#scenario-llm-provider-unavailable-falls-back-to-a-deterministic-explanation
	 */
	public function testLlmProviderUnavailableFallsBackToTemplateExplanations(): void {
		$now = new DateTimeImmutable('2026-07-13T12:00:00+00:00');
		$bySchema = $this->fiveSignalBySchema($now);

		$saved = [];
		$schemaCallLog = [];
		$objectService = $this->objectService(bySchema: $bySchema, throwFor: [], saved: $saved, schemaCallLog: $schemaCallLog);

		$providerFactory = $this->createMock(ProviderFactory::class);
		$providerFactory->method('getLlmConfig')->willThrowException(new ProviderUnavailableException('no provider configured'));
		$providerFactory->expects($this->never())->method('generateText');

		$engine = $this->engine(
			objectService: $objectService,
			feature: $this->enabledFeature(),
			providerFactory: $providerFactory,
			organisations: [$this->organisation('org-1')]
		);

		$result = $engine->getOrRegenerate(learnerUid: 'alice');

		$this->assertSame('template', $result['explanationMode']);
		$this->assertNull($result['modelUsed']);
		$this->assertNotEmpty($result['recommendations']);
		foreach ($result['recommendations'] as $recommendation) {
			$this->assertNotSame('', $recommendation['explanation']);
			$this->assertNotEmpty($recommendation['matchedSignals']);
		}

	}//end testLlmProviderUnavailableFallsBackToTemplateExplanations()

	/**
	 * The LLM step, when available, phrases each candidate's explanation using a
	 * prompt scoped STRICTLY to that one candidate's own name/code/matchedSignals
	 * — never the full candidate list, and it never alters rank/score/courseId
	 * (spec.md "LLM phrasing does not change which courses are recommended").
	 *
	 * @return void
	 *
	 * @spec openspec/changes/ai-course-recommendations/specs/course-recommendations/spec.md#scenario-llm-phrasing-does-not-change-which-courses-are-recommended
	 */
	public function testLlmPromptIsScopedToASingleCandidateAndNeverAltersRanking(): void {
		$now = new DateTimeImmutable('2026-07-13T12:00:00+00:00');
		$bySchema = $this->fiveSignalBySchema($now);

		$saved = [];
		$schemaCallLog = [];
		$objectService = $this->objectService(bySchema: $bySchema, throwFor: [], saved: $saved, schemaCallLog: $schemaCallLog);

		$allCourseNames = ['Advanced Security', 'Programme Module 2', 'NIS2 Renewal', 'Competency Closer', 'Follow-up Module'];

		$prompts = [];
		$providerFactory = $this->createMock(ProviderFactory::class);
		$providerFactory->method('getLlmConfig')->willReturn([]);
		$providerFactory->method('createChatDriver')->willReturn(new ChatDriver(provider: 'openai', chat: null, model: 'gpt-4o-mini'));
		$providerFactory->method('generateText')->willReturnCallback(
			function (string $prompt) use (&$prompts): string {
				$prompts[] = $prompt;
				return 'A short, plain-language explanation.';
			}
		);

		$engine = $this->engine(
			objectService: $objectService,
			feature: $this->enabledFeature(),
			providerFactory: $providerFactory,
			organisations: [$this->organisation('org-1')]
		);

		$unrankedIds = array_column(
			$engine->scoreCandidates(
				array_map(fn (ObjectEntity $e) => array_merge($e->getObject(), ['_uuid' => $e->getUuid()]), $bySchema['course']),
				array_map(fn (ObjectEntity $e) => $e->getObject(), $bySchema['enrolment']),
				array_map(fn (ObjectEntity $e) => $e->getObject(), $bySchema['xapi-statement']),
				$bySchema['learning-plan'][0]->getObject()['goals'],
				['comp-1'],
				$now
			)['recommendations'],
			'courseId'
		);

		$result = $engine->getOrRegenerate(learnerUid: 'alice');

		$this->assertSame('llm-assisted', $result['explanationMode']);
		$this->assertSame('openai:gpt-4o-mini', $result['modelUsed']);

		// Same candidate set/order as the pure deterministic call — the LLM step
		// never re-ranked, added, or removed anything.
		$this->assertSame($unrankedIds, array_column($result['recommendations'], 'courseId'));

		$this->assertCount(5, $prompts, 'All five ranked candidates fall within TOP_N_FOR_EXPLANATION.');
		foreach ($result['recommendations'] as $i => $recommendation) {
			$ownName = $recommendation['courseName'];
			$otherNames = array_diff($allCourseNames, [$ownName]);
			$this->assertStringContainsString($ownName, $prompts[$i], 'Prompt must reference its own candidate.');
			foreach ($otherNames as $otherName) {
				$this->assertStringNotContainsString(
					$otherName,
					$prompts[$i],
					'Prompt must never leak another candidate\'s name.'
				);
			}
		}

	}//end testLlmPromptIsScopedToASingleCandidateAndNeverAltersRanking()

	/**
	 * The AiFeature gate is re-checked on EVERY call — a previously-fresh, still
	 * within-TTL cached recommendation must stop being served the moment the
	 * feature is disabled, not only after the 24h TTL expires (fail-closed
	 * correctness beyond the literal TTL mechanism).
	 *
	 * @return void
	 */
	public function testDisablingTheFeatureStopsServingAPreviouslyFreshCachedResult(): void {
		$now = new DateTimeImmutable('2026-07-13T12:00:00+00:00');
		$bySchema = $this->fiveSignalBySchema($now);
		$bySchema['courserecommendation'] = [
			$this->entity(
				'rec-1',
				[
					'learnerId' => 'alice',
					'status' => 'fresh',
					'staleAt' => $now->modify('+23 hours')->format('c'),
					'recommendations' => [['courseId' => 'course-a', 'explanation' => 'stale-but-fresh cached']],
				]
			),
		];

		$saved = [];
		$schemaCallLog = [];
		$objectService = $this->objectService(bySchema: $bySchema, throwFor: [], saved: $saved, schemaCallLog: $schemaCallLog);

		$disabledFeature = $this->entity('feat-1', ['slug' => 'course-recommendations', 'lifecycle' => 'disabled']);
		$engine = $this->engine(objectService: $objectService, feature: $disabledFeature);

		$result = $engine->getOrRegenerate(learnerUid: 'alice');

		$this->assertSame('unavailable', $result['status'], 'A disabled feature must win over a fresh cache entry.');
		$this->assertNotContains('courserecommendation', $schemaCallLog, 'The cache must never even be consulted once the gate fails.');

	}//end testDisablingTheFeatureStopsServingAPreviouslyFreshCachedResult()
}//end class

<?php

/**
 * Unit tests for CourseRecommendationEngine's cache reuse, regeneration of a
 * stale set, write-failure fallback, per-candidate LLM degradation and the
 * organisation-lookup failure path.
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
 * @spec openspec/changes/archive/2026-07-16-ai-course-recommendations/tasks.md#task-2-8
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service;

use OCA\Hermiq\Service\AiFeature\AiFeatureLookup;
use OCA\Hermiq\Service\Course\RecommendationRegeneration;
use OCA\Hermiq\Service\CourseCandidateScorer;
use OCA\Hermiq\Service\CourseEvidenceIndexer;
use OCA\Hermiq\Service\CourseRecommendationEngine;
use OCA\Hermiq\Service\LearnerSignalReader;
use OCA\Hermiq\Service\LearnerSignalRegister;
use OCA\Hermiq\Service\LearnerSignalScope;
use OCA\Hermiq\Service\Llm\ChatDriver;
use OCA\Hermiq\Service\Llm\ProviderFactory;
use OCA\Hermiq\Service\ScheduleService;
use OCA\Hermiq\Support\FleetAppId;
use OCA\Hermiq\Tests\Unit\Support\FakeSlugResolver;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Service\ObjectService;
use OCP\App\IAppManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for the CourseRecommendationEngine paths around its stored set.
 *
 * @spec openspec/changes/archive/2026-07-16-ai-course-recommendations/tasks.md#task-2-8
 */
#[CoversClass(CourseRecommendationEngine::class)]
#[UsesClass(CourseCandidateScorer::class)]
#[UsesClass(CourseEvidenceIndexer::class)]
#[UsesClass(LearnerSignalReader::class)]
#[UsesClass(LearnerSignalRegister::class)]
#[UsesClass(LearnerSignalScope::class)]
#[UsesClass(RecommendationRegeneration::class)]
#[UsesClass(ChatDriver::class)]
#[UsesClass(FleetAppId::class)]
class CourseRecommendationEngineCacheTest extends TestCase {

	/**
	 * Mock logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * Mock provider factory.
	 *
	 * @var ProviderFactory&MockObject
	 */
	private ProviderFactory $providerFactory;

	/**
	 * Mock organisation mapper.
	 *
	 * @var OrganisationMapper&MockObject
	 */
	private OrganisationMapper $organisationMapper;

	/**
	 * Mock schedule service (kill-switch boundary).
	 *
	 * @var ScheduleService&MockObject
	 */
	private ScheduleService $scheduleService;

	/**
	 * Every saveObject() call: [payload, uuid].
	 *
	 * @var array<int, array{0: array<string, mixed>, 1: ?string}>
	 */
	private array $saved = [];

	/**
	 * Wire the mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->providerFactory = $this->createMock(ProviderFactory::class);
		$this->organisationMapper = $this->createMock(OrganisationMapper::class);
		$this->organisationMapper->method('findByUserId')->willReturn([]);
		$this->scheduleService = $this->createMock(ScheduleService::class);
		$this->saved = [];
	}//end setUp()

	/**
	 * An object entity with the given payload.
	 *
	 * @param string $uuid The uuid.
	 * @param array<string, mixed> $data The payload.
	 *
	 * @return ObjectEntity
	 */
	private function entity(string $uuid, array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setObject($data);
		return $entity;
	}//end entity()

	/**
	 * An ObjectService mock whose findAll() answers per active schema and whose
	 * saveObject() records the payload (or throws when asked to).
	 *
	 * @param array<string, array<int, mixed>> $bySchema Schema slug → rows.
	 * @param bool $saveThrows Whether saveObject() fails.
	 *
	 * @return ObjectService&MockObject
	 */
	private function objectService(array $bySchema, bool $saveThrows = false): ObjectService {
		$schema = '';
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('setRegister')->willReturnSelf();
		$objectService->method('setSchema')->willReturnCallback(
			function (mixed $slug) use (&$schema, $objectService): ObjectService {
				$schema = (string)$slug;
				return $objectService;
			}
		);
		$objectService->method('findAll')->willReturnCallback(
			function () use (&$schema, $bySchema): array {
				return ($bySchema[$schema] ?? []);
			}
		);
		$objectService->method('saveObject')->willReturnCallback(
			function (array|ObjectEntity $object, ?array $extend = [], mixed $register = null, mixed $schema = null, ?string $uuid = null) use ($saveThrows): ObjectEntity {
				if ($saveThrows === true) {
					throw new RuntimeException('disk full');
				}

				$this->saved[] = [$object, $uuid];
				return $this->entity(($uuid ?? 'new-rec'), $object);
			}
		);

		return $objectService;
	}//end objectService()

	/**
	 * Build the engine around the given ObjectService.
	 *
	 * @param ObjectService $objectService The ObjectService double.
	 *
	 * @return CourseRecommendationEngine
	 */
	private function engine(ObjectService $objectService): CourseRecommendationEngine {
		$aiFeatures = $this->createMock(AiFeatureLookup::class);
		$aiFeatures->method('findBySlug')->willReturn(
			$this->entity('feat-1', ['slug' => 'course-recommendations', 'lifecycle' => 'enabled'])
		);
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->willReturn(true);

		return new CourseRecommendationEngine(
			$objectService,
			new LearnerSignalRegister(new FakeSlugResolver(['learniq']), $appManager, $this->logger),
			new LearnerSignalReader($objectService, $this->logger),
			$aiFeatures,
			$this->scheduleService,
			$this->providerFactory,
			$this->organisationMapper,
			$this->logger
		);
	}//end engine()

	/**
	 * Two candidate courses, each matching the open "security" goal.
	 *
	 * @return array<string, array<int, ObjectEntity>>
	 */
	private function twoCandidates(): array {
		return [
			'course' => [
				$this->entity('course-a', ['code' => 'SEC-1', 'name' => 'Security One', 'tags' => ['security'], 'lifecycle' => 'published']),
				$this->entity('course-b', ['code' => 'SEC-2', 'name' => 'Security Two', 'tags' => ['security'], 'lifecycle' => 'published']),
			],
			'learning-plan' => [
				$this->entity('plan-1', [
					'learnerId' => 'alice',
					'goals' => [['goalId' => 'g1', 'description' => 'security', 'domain' => 'security', 'status' => 'open']],
				]),
			],
		];
	}//end twoCandidates()

	/**
	 * A fresh, unexpired stored set is returned as-is (with its uuid), no regeneration
	 * and no write.
	 *
	 * @return void
	 */
	public function testFreshStoredSetIsReturnedWithoutRegenerating(): void {
		$stored = $this->entity('rec-1', [
			'learnerId' => 'alice',
			'status' => 'fresh',
			'staleAt' => '2999-01-01T00:00:00+00:00',
			'recommendations' => [['courseId' => 'course-z']],
		]);
		$objectService = $this->objectService(['courserecommendation' => ['not-an-entity', $stored]]);
		$this->organisationMapper->expects($this->never())->method('findByUserId');

		$result = $this->engine($objectService)->getOrRegenerate('alice');

		$this->assertSame('rec-1', $result['uuid']);
		$this->assertSame([['courseId' => 'course-z']], $result['recommendations']);
		$this->assertSame([], $this->saved);
	}//end testFreshStoredSetIsReturnedWithoutRegenerating()

	/**
	 * An expired stored set is regenerated onto the same uuid and keeps its viewedAt.
	 *
	 * @return void
	 */
	public function testExpiredStoredSetIsRegeneratedInPlaceKeepingViewedAt(): void {
		$stored = $this->entity('rec-2', [
			'learnerId' => 'alice',
			'status' => 'fresh',
			'staleAt' => '2000-01-01T00:00:00+00:00',
			'viewedAt' => '2026-01-02T03:04:05+00:00',
		]);
		$objectService = $this->objectService(['courserecommendation' => [$stored]] + $this->twoCandidates());
		$this->providerFactory->method('createChatDriver')->willThrowException(new RuntimeException('no provider'));

		$result = $this->engine($objectService)->getOrRegenerate('alice');

		$this->assertCount(1, $this->saved);
		$this->assertSame('rec-2', $this->saved[0][1]);
		$this->assertSame('2026-01-02T03:04:05+00:00', $this->saved[0][0]['viewedAt']);
		$this->assertSame('rec-2', $result['uuid']);
		$this->assertSame('fresh', $result['status']);
		$this->assertCount(2, $result['recommendations']);
	}//end testExpiredStoredSetIsRegeneratedInPlaceKeepingViewedAt()

	/**
	 * A failed write is logged and the computed payload is still returned (no uuid).
	 *
	 * @return void
	 */
	public function testWriteFailureReturnsTheInMemoryPayload(): void {
		$objectService = $this->objectService($this->twoCandidates(), true);
		$this->providerFactory->method('createChatDriver')->willThrowException(new RuntimeException('no provider'));
		$this->logger->expects($this->once())->method('error')->with(
			$this->stringContains('Could not persist the recommendation set: disk full')
		);

		$result = $this->engine($objectService)->getOrRegenerate('alice');

		$this->assertArrayNotHasKey('uuid', $result);
		$this->assertSame('alice', $result['learnerId']);
		$this->assertSame('template', $result['explanationMode']);
		$this->assertCount(2, $result['recommendations']);
	}//end testWriteFailureReturnsTheInMemoryPayload()

	/**
	 * An empty LLM answer and an LLM exception each degrade only their own candidate
	 * to the deterministic template; the set stays in template mode.
	 *
	 * @return void
	 */
	public function testEmptyAndFailingLlmAnswersFallBackToTemplate(): void {
		$objectService = $this->objectService($this->twoCandidates());
		$this->providerFactory->method('getLlmConfig')->willReturn([]);
		$this->providerFactory->method('createChatDriver')->willReturn(new ChatDriver('openai', null, 'gpt-x'));
		$this->providerFactory->expects($this->exactly(2))->method('generateText')
			->willReturnOnConsecutiveCalls('   ', $this->throwException(new RuntimeException('timeout')));
		$this->logger->expects($this->once())->method('info')->with(
			$this->stringContains('LLM phrasing failed for one candidate; using the deterministic template for it: timeout')
		);

		$result = $this->engine($objectService)->getOrRegenerate('alice');

		$this->assertSame('template', $result['explanationMode']);
		$this->assertNull($result['modelUsed']);
		foreach ($result['recommendations'] as $recommendation) {
			$this->assertStringContainsString(' is recommended because it ', $recommendation['explanation']);
		}
	}//end testEmptyAndFailingLlmAnswersFallBackToTemplate()

	/**
	 * A failing organisation lookup degrades to "no organisation": logged, the
	 * kill-switch is never consulted and the tenant stays empty.
	 *
	 * @return void
	 */
	public function testOrganisationLookupFailureDegradesToNoOrganisation(): void {
		$this->organisationMapper = $this->createMock(OrganisationMapper::class);
		$this->organisationMapper->method('findByUserId')->willThrowException(new RuntimeException('db down'));
		$this->scheduleService->expects($this->never())->method('isOrganisationEngaged');
		$this->providerFactory->method('createChatDriver')->willThrowException(new RuntimeException('no provider'));
		$this->logger->expects($this->once())->method('warning')->with(
			'[CourseRecommendationEngine] Could not resolve an organisation for "alice": db down'
		);

		$result = $this->engine($this->objectService($this->twoCandidates()))->getOrRegenerate('alice');

		$this->assertSame('', $result['tenantId']);
	}//end testOrganisationLookupFailureDegradesToNoOrganisation()
}//end class

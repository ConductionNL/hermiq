<?php

/**
 * Unit tests for SkillLearningsCaptureService's degraded paths: no run identity,
 * no (matching) trace, an unreadable trace, unknown skills, an oversized trace,
 * and usage accounting when the schedule is gone or the audit write fails.
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
 * @spec openspec/specs/skill-learnings/spec.md#requirement-a-post-run-capture-pass-appends-dated-atomic-candidates-per-exercised-skill
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service;

use OCA\Hermiq\Service\BudgetService;
use OCA\Hermiq\Service\Llm\ProviderFactory;
use OCA\Hermiq\Service\Redaction\AssignmentRedactor;
use OCA\Hermiq\Service\Redaction\CredentialRedactor;
use OCA\Hermiq\Service\Redaction\FormBodyRedactor;
use OCA\Hermiq\Service\Redaction\PhoneRedactor;
use OCA\Hermiq\Service\Redaction\VendorKeyRedactor;
use OCA\Hermiq\Service\RedactionService;
use OCA\Hermiq\Service\SkillLearnings\CaptureSource;
use OCA\Hermiq\Service\SkillLearnings\CaptureUsage;
use OCA\Hermiq\Service\SkillLearnings\ExtractionInput;
use OCA\Hermiq\Service\SkillLearningsCaptureService;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for the SkillLearningsCaptureService degraded paths.
 *
 * @spec openspec/specs/skill-learnings/spec.md#requirement-a-post-run-capture-pass-appends-dated-atomic-candidates-per-exercised-skill
 */
#[CoversClass(SkillLearningsCaptureService::class)]
#[UsesClass(CaptureSource::class)]
#[UsesClass(CaptureUsage::class)]
#[UsesClass(ExtractionInput::class)]
#[UsesClass(RedactionService::class)]
#[UsesClass(AssignmentRedactor::class)]
#[UsesClass(CredentialRedactor::class)]
#[UsesClass(FormBodyRedactor::class)]
#[UsesClass(PhoneRedactor::class)]
#[UsesClass(VendorKeyRedactor::class)]
class SkillLearningsCaptureEdgesTest extends TestCase {

	/**
	 * The run id under capture.
	 *
	 * @var string
	 */
	private const RUN_ID = 'run-1';

	/**
	 * The schedule the run belongs to.
	 *
	 * @var string
	 */
	private const SCHEDULE_UUID = 'sched-1';

	/**
	 * Mock ObjectService.
	 *
	 * @var ObjectService&MockObject
	 */
	private ObjectService $objectService;

	/**
	 * Mock audit trail mapper.
	 *
	 * @var AuditTrailMapper&MockObject
	 */
	private AuditTrailMapper $auditTrailMapper;

	/**
	 * Mock provider factory.
	 *
	 * @var ProviderFactory&MockObject
	 */
	private ProviderFactory $providerFactory;

	/**
	 * Mock logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * The service under test.
	 *
	 * @var SkillLearningsCaptureService
	 */
	private SkillLearningsCaptureService $service;

	/**
	 * Wire the boundaries.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objectService = $this->createMock(ObjectService::class);
		$this->auditTrailMapper = $this->createMock(AuditTrailMapper::class);
		$this->providerFactory = $this->createMock(ProviderFactory::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$budget = $this->createMock(BudgetService::class);
		$budget->method('isBlocked')->willReturn(false);
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturn('yes');

		$this->service = new SkillLearningsCaptureService(
			$this->objectService,
			$this->auditTrailMapper,
			$this->providerFactory,
			$budget,
			new RedactionService($config),
			$this->logger
		);
	}//end setUp()

	/**
	 * A run audit entry for the given run id.
	 *
	 * @param string $runId The run id recorded on the entry.
	 * @param string $summary The run summary.
	 *
	 * @return AuditTrail
	 */
	private function traceEntry(string $runId, string $summary = 'Did the thing.'): AuditTrail {
		$entry = new AuditTrail();
		$entry->setAction('run');
		$entry->setChanged(['runId' => $runId, 'status' => 'ok', 'summary' => $summary, 'steps' => []]);
		return $entry;
	}//end traceEntry()

	/**
	 * A stored active skill.
	 *
	 * @param string $uuid The skill uuid.
	 *
	 * @return ObjectEntity
	 */
	private function skill(string $uuid): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setObject([
			'name' => 'tender-summary',
			'description' => 'Summarise a tender.',
			'body' => "# Tender\n\nSteps.",
			'state' => 'active',
			'files' => 'not-a-list',
		]);
		return $entity;
	}//end skill()

	/**
	 * A capture job payload.
	 *
	 * @param array<string, mixed> $overrides Overrides.
	 *
	 * @return array<string, mixed>
	 */
	private function args(array $overrides = []): array {
		return array_merge(
			['runId' => self::RUN_ID, 'scheduleUuid' => self::SCHEDULE_UUID, 'agentId' => 'agent-1', 'skillIds' => ['skill-1']],
			$overrides
		);
	}//end args()

	/**
	 * Wire one LLM observation for the skill so the capture consumes tokens.
	 *
	 * @return void
	 */
	private function wireOneObservation(): void {
		$this->providerFactory->method('generateText')->willReturn(
			(string)json_encode(['observations' => [['section' => 'domain', 'text' => 'Deadlines are CET.']], 'confirmations' => []])
		);
	}//end wireOneObservation()

	/**
	 * A job without a run id, or with a non-list / empty skill set, reads nothing.
	 *
	 * @return void
	 */
	public function testMissingRunIdentityOrSkillsReadsNothing(): void {
		$this->auditTrailMapper->expects($this->never())->method('findAll');
		$this->objectService->expects($this->never())->method('find');

		$this->service->captureForRun(['runId' => '', 'skillIds' => ['skill-1']]);
		$this->service->captureForRun($this->args(['skillIds' => 'skill-1']));
		$this->service->captureForRun($this->args(['skillIds' => []]));
	}//end testMissingRunIdentityOrSkillsReadsNothing()

	/**
	 * Audit entries for other runs are skipped; with no matching entry nothing is extracted.
	 *
	 * @return void
	 */
	public function testNoMatchingTraceExtractsNothing(): void {
		$this->auditTrailMapper->expects($this->once())->method('findAll')
			->with($this->anything(), $this->anything(), ['object_uuid' => self::SCHEDULE_UUID, 'action' => 'run'])
			->willReturn([$this->traceEntry('another-run')]);
		$this->objectService->expects($this->never())->method('find');
		$this->providerFactory->expects($this->never())->method('generateText');
		$this->logger->expects($this->once())->method('info')
			->with('Hermiq learnings capture: no persisted trace for run run-1 — nothing to extract.');

		$this->service->captureForRun($this->args());
	}//end testNoMatchingTraceExtractsNothing()

	/**
	 * Without a schedule there is no trace to read at all.
	 *
	 * @return void
	 */
	public function testNoScheduleMeansNoTrace(): void {
		$this->auditTrailMapper->expects($this->never())->method('findAll');
		$this->logger->expects($this->once())->method('info')
			->with($this->stringContains('no persisted trace for run run-1'));

		$this->service->captureForRun($this->args(['scheduleUuid' => '  ']));
	}//end testNoScheduleMeansNoTrace()

	/**
	 * An unreadable audit trail is logged as a warning and treated as no trace.
	 *
	 * @return void
	 */
	public function testUnreadableTraceIsAWarning(): void {
		$this->auditTrailMapper->method('findAll')->willThrowException(new RuntimeException('db gone'));
		$this->logger->expects($this->once())->method('warning')
			->with('Hermiq learnings capture could not read run entries for schedule sched-1: db gone', $this->arrayHasKey('exception'));
		$this->logger->expects($this->once())->method('info')
			->with($this->stringContains('no persisted trace'));
		$this->objectService->expects($this->never())->method('find');

		$this->service->captureForRun($this->args());
	}//end testUnreadableTraceIsAWarning()

	/**
	 * Non-string and blank skill ids are skipped; an unknown skill is logged and skipped,
	 * so no LLM call and no usage entry happen.
	 *
	 * @return void
	 */
	public function testUnknownAndMalformedSkillIdsAreSkipped(): void {
		$this->auditTrailMapper->method('findAll')->willReturn([$this->traceEntry(self::RUN_ID)]);
		$this->objectService->expects($this->once())->method('find')
			->with('missing', $this->anything(), $this->anything(), 'hermiq', 'agentskill')
			->willReturn(null);
		$this->providerFactory->expects($this->never())->method('generateText');
		$this->auditTrailMapper->expects($this->never())->method('recordObjectEvent');
		$this->logger->expects($this->once())->method('info')
			->with('Hermiq learnings capture: skill missing not found — skipped.');

		$this->service->captureForRun($this->args(['skillIds' => [42, '   ', 'missing']]));
	}//end testUnknownAndMalformedSkillIdsAreSkipped()

	/**
	 * An oversized trace is cut to 8000 characters before it reaches the extraction prompt.
	 *
	 * @return void
	 */
	public function testOversizedTraceIsTruncatedBeforeExtraction(): void {
		$long = str_repeat('x', 20000);
		$this->auditTrailMapper->method('findAll')->willReturn([$this->traceEntry(self::RUN_ID, $long)]);
		$this->objectService->method('find')->willReturnCallback(
			fn (string $id): ?ObjectEntity => ($id === 'skill-1') ? $this->skill('skill-1') : null
		);
		$prompt = '';
		$this->providerFactory->method('generateText')->willReturnCallback(
			function (string $text) use (&$prompt): string {
				$prompt = $text;
				return '{"observations":[],"confirmations":[]}';
			}
		);

		$this->service->captureForRun($this->args());

		$this->assertStringContainsString(str_repeat('x', 7000), $prompt);
		$this->assertStringNotContainsString(str_repeat('x', 8000), $prompt);
	}//end testOversizedTraceIsTruncatedBeforeExtraction()

	/**
	 * When the schedule disappears before usage is recorded, the usage is logged as
	 * not recorded and no audit event is written.
	 *
	 * @return void
	 */
	public function testUsageForAVanishedScheduleIsNotRecorded(): void {
		$this->auditTrailMapper->method('findAll')->willReturn([$this->traceEntry(self::RUN_ID)]);
		$this->objectService->method('find')->willReturnCallback(
			fn (string $id): ?ObjectEntity => ($id === 'skill-1') ? $this->skill('skill-1') : null
		);
		$this->wireOneObservation();
		$this->auditTrailMapper->expects($this->never())->method('recordObjectEvent');
		$this->logger->expects($this->once())->method('info')
			->with('Hermiq learnings capture: schedule sched-1 gone — capture usage for run run-1 not recorded.');

		$this->service->captureForRun($this->args());
	}//end testUsageForAVanishedScheduleIsNotRecorded()

	/**
	 * A failing usage write is logged as a warning and never thrown.
	 *
	 * @return void
	 */
	public function testFailingUsageWriteIsAWarning(): void {
		$schedule = new ObjectEntity();
		$schedule->setUuid(self::SCHEDULE_UUID);
		$schedule->setObject([]);
		$this->auditTrailMapper->method('findAll')->willReturn([$this->traceEntry(self::RUN_ID)]);
		$this->objectService->method('find')->willReturnCallback(
			fn (string $id): ?ObjectEntity => match ($id) {
				'skill-1' => $this->skill('skill-1'),
				self::SCHEDULE_UUID => $schedule,
				default => null,
			}
		);
		$this->wireOneObservation();
		$this->auditTrailMapper->expects($this->once())->method('recordObjectEvent')
			->willThrowException(new RuntimeException('audit full'));
		$this->logger->expects($this->once())->method('warning')
			->with('Hermiq learnings capture could not record usage for run run-1: audit full', $this->arrayHasKey('exception'));

		$this->service->captureForRun($this->args());
	}//end testFailingUsageWriteIsAWarning()
}//end class

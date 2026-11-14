<?php

/**
 * Unit tests for ScheduleService failure and offboarding paths.
 *
 * Covers: a tick that cannot load its due schedules returns quietly, a
 * schedule whose dispatch throws is re-fetched and marked `error` (and left
 * alone when the re-fetch finds nothing), and pauseForUser() disables every
 * enabled schedule the departing user owns or acts as, flags each affected
 * agent for reassignment and audits each pause, while every write failure
 * along the way degrades to a warning instead of aborting the pass.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service;

use OCA\Hermiq\Service\Agent\AgentAvailability;
use OCA\Hermiq\Service\AgentVersionService;
use OCA\Hermiq\Service\ApprovalService;
use OCA\Hermiq\Service\BudgetService;
use OCA\Hermiq\Service\DeliveryService;
use OCA\Hermiq\Service\Engine\DelegationContext;
use OCA\Hermiq\Service\Engine\Engine;
use OCA\Hermiq\Service\GuardrailPolicyService;
use OCA\Hermiq\Service\Redaction\AssignmentRedactor;
use OCA\Hermiq\Service\Redaction\CredentialRedactor;
use OCA\Hermiq\Service\Redaction\VendorKeyRedactor;
use OCA\Hermiq\Service\RedactionService;
use OCA\Hermiq\Service\Schedule\DispatchOptions;
use OCA\Hermiq\Service\Schedule\ScheduleRecordNormaliser;
use OCA\Hermiq\Service\ScheduleService;
use OCA\Hermiq\Service\SkillVersionService;
use OCA\Hermiq\Service\TenantKillSwitch;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrail\ObjectAuditEvent;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Mcp\Db\AgentMapper;
use OCA\OpenRegister\Mcp\Db\ConversationMapper;
use OCA\OpenRegister\Mcp\Service\ChatService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\BackgroundJob\IJobList;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for ScheduleService failure handling and offboarding pause.
 */
#[CoversClass(ScheduleService::class)]
#[UsesClass(TenantKillSwitch::class)]
#[UsesClass(AgentAvailability::class)]
#[UsesClass(ScheduleRecordNormaliser::class)]
#[UsesClass(RedactionService::class)]
#[UsesClass(DelegationContext::class)]
#[UsesClass(DispatchOptions::class)]
#[UsesClass(AssignmentRedactor::class)]
#[UsesClass(CredentialRedactor::class)]
#[UsesClass(VendorKeyRedactor::class)]
class ScheduleServiceFailurePathsTest extends TestCase {

	/**
	 * Mock ObjectService.
	 *
	 * @var ObjectService&MockObject
	 */
	private ObjectService $objectService;

	/**
	 * Mock logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * Mock budget gate.
	 *
	 * @var BudgetService&MockObject
	 */
	private BudgetService $budgetService;

	/**
	 * Mock audit trail mapper.
	 *
	 * @var AuditTrailMapper&MockObject
	 */
	private AuditTrailMapper $auditTrailMapper;

	/**
	 * The schema last selected on the ObjectService.
	 *
	 * @var string
	 */
	private string $schema = '';

	/**
	 * Every saveObject() call: [schema, uuid, object].
	 *
	 * @var array<int, array{0: string, 1: string, 2: array<string, mixed>}>
	 */
	private array $saves = [];

	/**
	 * Wire fresh mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->objectService = $this->createMock(ObjectService::class);
		$this->objectService->method('setRegister')->willReturnSelf();
		$this->objectService->method('setSchema')->willReturnCallback(
			function (string $schema): ObjectService {
				$this->schema = $schema;
				return $this->objectService;
			}
		);
		$this->objectService->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend, string $register, string $schema, string $uuid): ObjectEntity {
				$this->saves[] = [$schema, $uuid, $object];
				return new ObjectEntity();
			}
		);

		$this->logger           = $this->createMock(LoggerInterface::class);
		$this->budgetService    = $this->createMock(BudgetService::class);
		$this->auditTrailMapper = $this->createMock(AuditTrailMapper::class);
	}//end setUp()

	/**
	 * Build the service under test.
	 *
	 * @return ScheduleService
	 */
	private function service(): ScheduleService {
		$config = $this->createMock(IConfig::class);

		return new ScheduleService(
			objectService: $this->objectService,
			agentMapper: $this->createMock(AgentMapper::class),
			conversationMapper: $this->createMock(ConversationMapper::class),
			chatService: $this->createMock(ChatService::class),
			userSession: $this->createMock(IUserSession::class),
			userManager: $this->createMock(IUserManager::class),
			config: $config,
			logger: $this->logger,
			deliveryService: $this->createMock(DeliveryService::class),
			auditTrailMapper: $this->auditTrailMapper,
			redactionService: new RedactionService($config),
			approvalService: $this->createMock(ApprovalService::class),
			appConfig: $this->createMock(IAppConfig::class),
			engine: $this->createMock(Engine::class),
			budgetService: $this->budgetService,
			guardrailPolicyService: $this->createMock(GuardrailPolicyService::class),
			agentVersionService: $this->createMock(AgentVersionService::class),
			skillVersionService: $this->createMock(SkillVersionService::class),
			delegationContext: new DelegationContext(),
			jobList: $this->createMock(IJobList::class),
		);
	}//end service()

	/**
	 * Build an ObjectEntity.
	 *
	 * @param string               $uuid    The uuid.
	 * @param array<string, mixed> $payload The object body.
	 * @param string|null          $owner   The owner uid.
	 *
	 * @return ObjectEntity
	 */
	private function entity(string $uuid, array $payload, ?string $owner = null): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setOwner($owner);
		$entity->setObject($payload);
		return $entity;
	}//end entity()

	/**
	 * A due schedule with no agent (so the switched-off gate has nothing to read).
	 *
	 * @return ObjectEntity
	 */
	private function dueSchedule(): ObjectEntity {
		return $this->entity(
			'sched-1',
			[
				'enabled' => true,
				'nextRun' => '2000-01-01T00:00:00+00:00',
				'prompt'  => 'brief',
			],
			'alice'
		);
	}//end dueSchedule()

	/**
	 * A tick whose due-schedule read fails logs an error and dispatches nothing.
	 *
	 * @return void
	 */
	public function testRunReturnsQuietlyWhenDueSchedulesCannotBeLoaded(): void {
		$this->objectService->method('findAll')->willThrowException(new RuntimeException('db gone'));
		$this->budgetService->expects($this->never())->method('isBlocked');
		$this->logger->expects($this->once())->method('error')->with(
			$this->equalTo('Hermiq dispatcher could not load due schedules: db gone'),
			$this->callback(static fn (array $ctx): bool => $ctx['exception'] instanceof RuntimeException)
		);

		$this->service()->run();

		$this->assertSame([], $this->saves);
	}//end testRunReturnsQuietlyWhenDueSchedulesCannotBeLoaded()

	/**
	 * A dispatch that throws is recorded on the freshly re-fetched schedule as an error.
	 *
	 * @return void
	 */
	public function testRunRecordsADispatchFailureOnTheRefetchedSchedule(): void {
		$this->objectService->method('findAll')->willReturnCallback(
			fn (): array => ($this->schema === 'schedule') ? [$this->dueSchedule(), 'not-an-entity'] : []
		);
		$this->budgetService->method('isBlocked')->willThrowException(new RuntimeException('budget store down'));

		$fresh = $this->entity('sched-1', ['enabled' => true, 'prompt' => 'brief', 'lastStatus' => 'success']);
		$this->objectService->expects($this->once())->method('find')
			->with('sched-1', [], false, 'hermiq', 'schedule', false, false)
			->willReturn($fresh);

		$this->logger->expects($this->once())->method('warning')
			->with($this->equalTo('Hermiq schedule sched-1 failed: budget store down'));
		$this->logger->expects($this->never())->method('error');

		$this->service()->run();

		$this->assertCount(1, $this->saves);
		[$schema, $uuid, $object] = $this->saves[0];
		$this->assertSame('schedule', $schema);
		$this->assertSame('sched-1', $uuid);
		$this->assertSame('error', $object['lastStatus']);
		$this->assertSame('budget store down', $object['lastError']);
		$this->assertSame('brief', $object['prompt']);
	}//end testRunRecordsADispatchFailureOnTheRefetchedSchedule()

	/**
	 * When the failed schedule cannot be re-fetched, nothing is persisted.
	 *
	 * @return void
	 */
	public function testRunSkipsRecordingWhenTheFailedScheduleIsGone(): void {
		$this->objectService->method('findAll')->willReturnCallback(
			fn (): array => ($this->schema === 'schedule') ? [$this->dueSchedule()] : []
		);
		$this->budgetService->method('isBlocked')->willThrowException(new RuntimeException('boom'));
		$this->objectService->method('find')->willReturn(null);
		$this->logger->expects($this->once())->method('error')
			->with($this->equalTo('Hermiq could not re-fetch schedule sched-1 to record failure; skipping.'));

		$this->service()->run();

		$this->assertSame([], $this->saves);
	}//end testRunSkipsRecordingWhenTheFailedScheduleIsGone()

	/**
	 * A failure while recording the failure is logged, never rethrown.
	 *
	 * @return void
	 */
	public function testRunLogsWhenRecordingTheFailureItselfFails(): void {
		$this->objectService->method('findAll')->willReturnCallback(
			fn (): array => ($this->schema === 'schedule') ? [$this->dueSchedule()] : []
		);
		$this->budgetService->method('isBlocked')->willThrowException(new RuntimeException('boom'));
		$this->objectService->method('find')->willThrowException(new RuntimeException('read failed'));
		$this->logger->expects($this->once())->method('error')
			->with($this->equalTo('Hermiq could not record schedule failure: read failed'));

		$this->service()->run();

		$this->assertSame([], $this->saves);
	}//end testRunLogsWhenRecordingTheFailureItselfFails()

	/**
	 * A blank uid pauses nothing and reads nothing.
	 *
	 * @return void
	 */
	public function testPauseForUserIgnoresABlankUid(): void {
		$this->objectService->expects($this->never())->method('findAll');

		$this->assertSame(0, $this->service()->pauseForUser('   '));
	}//end testPauseForUserIgnoresABlankUid()

	/**
	 * An unreadable schedule list pauses nothing and logs the user.
	 *
	 * @return void
	 */
	public function testPauseForUserReturnsZeroWhenSchedulesCannotBeLoaded(): void {
		$this->objectService->method('findAll')->willThrowException(new RuntimeException('down'));
		$this->logger->expects($this->once())->method('error')
			->with($this->equalTo('Hermiq offboarding pause could not load schedules for user bob: down'));

		$this->assertSame(0, $this->service()->pauseForUser(' bob '));
		$this->assertSame([], $this->saves);
	}//end testPauseForUserReturnsZeroWhenSchedulesCannotBeLoaded()

	/**
	 * Owned and acted-as schedules are paused and audited; their agents are flagged once each.
	 *
	 * @return void
	 */
	public function testPauseForUserPausesOwnedAndActedAsSchedulesAndFlagsTheirAgents(): void {
		$schedules = [
			'garbage',
			$this->entity('owned', ['enabled' => true, 'agentId' => 'agent-a'], 'bob'),
			$this->entity('acted', ['enabled' => true, 'agentId' => 'agent-b'], 'carol'),
			$this->entity('acted-off', ['enabled' => false, 'agentId' => 'agent-b'], 'carol'),
			$this->entity('foreign', ['enabled' => true, 'agentId' => 'agent-c'], 'carol'),
			$this->entity('agentless', ['enabled' => true], 'dave'),
		];
		$this->objectService->expects($this->once())->method('findAll')
			->with(['limit' => 1000], false, false)
			->willReturn($schedules);

		$agents = [
			'agent-a' => $this->entity('agent-a-uuid', ['actingUser' => 'zed', 'name' => 'A']),
			'agent-b' => $this->entity('agent-b-uuid', ['actingUser' => ' bob ', 'name' => 'B']),
			'agent-c' => $this->entity('agent-c-uuid', ['actingUser' => '', 'name' => 'C']),
		];
		$finds = [];
		$this->objectService->method('find')->willReturnCallback(
			function (string $id) use ($agents, &$finds): ?ObjectEntity {
				$finds[] = $id;
				return $agents[$id] ?? null;
			}
		);

		$audits = [];
		$this->auditTrailMapper->method('recordObjectEvent')->willReturnCallback(
			function (ObjectAuditEvent $event) use (&$audits): AuditTrail {
				$audits[] = [$event->object->getUuid(), $event->action, $event->context];
				return new AuditTrail();
			}
		);

		$paused = $this->service()->pauseForUser('bob');

		$this->assertSame(2, $paused);

		$scheduleSaves = array_values(array_filter($this->saves, static fn (array $s): bool => $s[0] === 'schedule'));
		$this->assertSame(['owned', 'acted'], array_column($scheduleSaves, 1));
		foreach ($scheduleSaves as [, , $object]) {
			$this->assertFalse($object['enabled']);
			$this->assertSame('paused_offboarding', $object['lastStatus']);
		}

		$agentSaves = array_values(array_filter($this->saves, static fn (array $s): bool => $s[0] === 'agent'));
		$this->assertSame(['agent-a-uuid', 'agent-b-uuid'], array_column($agentSaves, 1));
		foreach ($agentSaves as [, , $object]) {
			$this->assertTrue($object['reassignmentFlag']);
		}

		$this->assertSame(
			[
				['owned', 'run', ['status' => 'paused_offboarding', 'offboardedUser' => 'bob']],
				['acted', 'run', ['status' => 'paused_offboarding', 'offboardedUser' => 'bob']],
			],
			$audits
		);

		// The acting-user lookup is cached per agent: agent-b is read once for
		// matching (shared by two schedules) and once more to flag it.
		$this->assertSame(['agent-b', 'agent-c', 'agent-a', 'agent-b'], $finds);
	}//end testPauseForUserPausesOwnedAndActedAsSchedulesAndFlagsTheirAgents()

	/**
	 * Audit and flag write failures degrade to warnings; the pause still counts.
	 *
	 * @return void
	 */
	public function testPauseForUserSurvivesAuditAndFlagFailures(): void {
		$this->objectService->method('findAll')->willReturn(
			[
				$this->entity('owned', ['enabled' => true, 'agentId' => 'agent-a'], 'bob'),
				$this->entity('gone-agent', ['enabled' => true, 'agentId' => 'agent-x'], 'bob'),
			]
		);
		$this->objectService->method('find')->willReturnCallback(
			function (string $id): ?ObjectEntity {
				if ($id === 'agent-a') {
					throw new RuntimeException('agent read failed');
				}

				return null;
			}
		);
		$this->auditTrailMapper->method('recordObjectEvent')->willThrowException(new RuntimeException('audit down'));

		$warnings = [];
		$this->logger->method('warning')->willReturnCallback(
			static function (string $message) use (&$warnings): void {
				$warnings[] = $message;
			}
		);

		$this->assertSame(2, $this->service()->pauseForUser('bob'));

		$this->assertSame(
			[
				'Hermiq could not write offboarding-pause audit for schedule owned: audit down',
				'Hermiq could not write offboarding-pause audit for schedule gone-agent: audit down',
				'Hermiq could not flag agent agent-a for reassignment: agent read failed',
			],
			$warnings
		);
		$this->assertSame(['owned', 'gone-agent'], array_column($this->saves, 1));
	}//end testPauseForUserSurvivesAuditAndFlagFailures()
}//end class

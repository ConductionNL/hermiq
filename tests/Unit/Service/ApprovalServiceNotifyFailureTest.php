<?php

/**
 * Unit tests for ApprovalService's never-fatal side effects: a reviewer
 * notification that throws, a task back-link write that fails, and a decision
 * audit that cannot be written, plus the reviewer fallbacks each creation path
 * applies when no owner is known.
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
 * @spec openspec/specs/agent-approval/spec.md
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service;

use OCA\Hermiq\Service\Approval\ApprovalTaskBridge;
use OCA\Hermiq\Service\Approval\ApprovalDecision;
use OCA\Hermiq\Service\ApprovalService;
use OCA\Hermiq\Service\Approval\ApprovalTarget;
use OCA\Hermiq\Service\DeliveryService;
use OCA\Hermiq\Service\Redaction\AssignmentRedactor;
use OCA\Hermiq\Service\Redaction\CredentialRedactor;
use OCA\Hermiq\Service\Redaction\FormBodyRedactor;
use OCA\Hermiq\Service\Redaction\PhoneRedactor;
use OCA\Hermiq\Service\Redaction\VendorKeyRedactor;
use OCA\Hermiq\Service\RedactionService;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for ApprovalService's best-effort side effects.
 *
 * @spec openspec/specs/agent-approval/spec.md
 */
#[CoversClass(ApprovalService::class)]
#[UsesClass(ApprovalTarget::class)]
#[UsesClass(ApprovalDecision::class)]
#[UsesClass(RedactionService::class)]
#[UsesClass(AssignmentRedactor::class)]
#[UsesClass(CredentialRedactor::class)]
#[UsesClass(FormBodyRedactor::class)]
#[UsesClass(PhoneRedactor::class)]
#[UsesClass(VendorKeyRedactor::class)]
class ApprovalServiceNotifyFailureTest extends TestCase {

	/**
	 * Mock ObjectService.
	 *
	 * @var ObjectService&MockObject
	 */
	private ObjectService $objectService;

	/**
	 * Mock delivery service (notification boundary).
	 *
	 * @var DeliveryService&MockObject
	 */
	private DeliveryService $deliveryService;

	/**
	 * Mock audit trail mapper.
	 *
	 * @var AuditTrailMapper&MockObject
	 */
	private AuditTrailMapper $auditTrailMapper;

	/**
	 * Mock task bridge.
	 *
	 * @var ApprovalTaskBridge&MockObject
	 */
	private ApprovalTaskBridge $taskBridge;

	/**
	 * Mock group manager.
	 *
	 * @var IGroupManager&MockObject
	 */
	private IGroupManager $groupManager;

	/**
	 * Mock logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * Every saveObject() call: [payload, uuid].
	 *
	 * @var array<int, array{0: array<string, mixed>, 1: ?string}>
	 */
	private array $saved = [];

	/**
	 * Wire the boundaries.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objectService = $this->createMock(ObjectService::class);
		$this->objectService->method('setRegister')->willReturnSelf();
		$this->objectService->method('setSchema')->willReturnSelf();
		$this->deliveryService = $this->createMock(DeliveryService::class);
		$this->auditTrailMapper = $this->createMock(AuditTrailMapper::class);
		$this->taskBridge = $this->createMock(ApprovalTaskBridge::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->saved = [];
	}//end setUp()

	/**
	 * Build the service; saveObject() records and echoes, or throws on the given call.
	 *
	 * @param int|null $throwOnSave 1-based saveObject() call that throws, or null.
	 *
	 * @return ApprovalService
	 */
	private function service(?int $throwOnSave = null): ApprovalService {
		$this->objectService->method('saveObject')->willReturnCallback(
			function (array|ObjectEntity $object, ?array $extend = [], mixed $register = null, mixed $schema = null, ?string $uuid = null) use ($throwOnSave): ObjectEntity {
				$this->saved[] = [$object, $uuid];
				if ($throwOnSave === count($this->saved)) {
					throw new RuntimeException('write refused');
				}

				$entity = new ObjectEntity();
				$entity->setUuid($uuid ?? 'appr-1');
				$entity->setObject($object);
				return $entity;
			}
		);
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturn('yes');

		return new ApprovalService(
			$this->objectService,
			$this->createMock(IUserSession::class),
			$this->createMock(IUserManager::class),
			$this->groupManager,
			$this->deliveryService,
			$this->auditTrailMapper,
			new RedactionService($config),
			$this->createMock(ContainerInterface::class),
			$this->logger,
			$this->taskBridge
		);
	}//end service()

	/**
	 * A schedule without a uuid creates nothing.
	 *
	 * @return void
	 */
	public function testScheduleWithoutUuidIsIgnored(): void {
		$this->objectService->expects($this->never())->method('findAll');

		$schedule = new ObjectEntity();
		$schedule->setObject(['agentId' => 'a']);
		$this->service()->ensurePendingApproval($schedule);

		$this->assertSame([], $this->saved);
	}//end testScheduleWithoutUuidIsIgnored()

	/**
	 * A throwing schedule notification is logged; the approval is still persisted.
	 *
	 * @return void
	 */
	public function testScheduleNotificationFailureIsLogged(): void {
		$this->objectService->method('findAll')->willReturn(['not-an-entity']);
		$this->deliveryService->method('deliverApprovalRequest')->willThrowException(new RuntimeException('smtp down'));
		$this->logger->expects($this->once())->method('warning')
			->with('Hermiq could not notify reviewer for approval appr-1: smtp down', $this->arrayHasKey('exception'));

		$schedule = new ObjectEntity();
		$schedule->setUuid('sched-1');
		$schedule->setOwner('alice');
		$schedule->setObject(['agentId' => 'agent-1', 'prompt' => 'Go']);
		$this->service()->ensurePendingApproval($schedule);

		$this->assertCount(1, $this->saved);
		$this->assertSame('schedule', $this->saved[0][0]['sourceType']);
		$this->assertSame('sched-1', $this->saved[0][0]['scheduleId']);
	}//end testScheduleNotificationFailureIsLogged()

	/**
	 * A flow-run approval without an owner routes to the admin group; a throwing
	 * notification is logged and the approval still returned.
	 *
	 * @return void
	 */
	public function testFlowRunWithoutOwnerRoutesToAdminsAndSurvivesNotifyFailure(): void {
		$this->objectService->method('findAll')->willReturn(['junk']);
		$this->groupManager->expects($this->once())->method('get')->with('admin')->willReturn(null);
		$this->deliveryService->expects($this->once())->method('deliverApprovalRequestForFlowRun')
			->with($this->anything(), [])
			->willThrowException(new RuntimeException('no notifier'));
		$this->logger->expects($this->once())->method('warning')
			->with('Hermiq could not notify reviewer for flow-run approval appr-1: no notifier', $this->anything());

		$approval = $this->service()->ensurePendingApprovalForFlowRun(['correlationId' => 'c-1', 'agent' => 'agent-1'], '');

		$this->assertSame('appr-1', $approval->getUuid());
		$this->assertSame('admin', $this->saved[0][0]['reviewer']);
		$this->assertSame('group', $this->saved[0][0]['reviewerType']);
		$this->assertSame('flow', $this->saved[0][0]['sourceType']);
	}//end testFlowRunWithoutOwnerRoutesToAdminsAndSurvivesNotifyFailure()

	/**
	 * A webhook-run approval coerces an unknown reviewer type to "user"; a throwing
	 * notification is logged and the approval still returned.
	 *
	 * @return void
	 */
	public function testWebhookRunCoercesReviewerTypeAndSurvivesNotifyFailure(): void {
		$this->objectService->method('findAll')->willReturn([]);
		$this->deliveryService->expects($this->once())->method('deliverApprovalRequestForWebhookRun')
			->with($this->anything(), ['dora'])
			->willThrowException(new RuntimeException('bus closed'));
		$this->logger->expects($this->once())->method('warning')
			->with('Hermiq could not notify reviewer for webhook-run approval appr-1: bus closed', $this->anything());

		$approval = $this->service()->ensurePendingApprovalForWebhookRun(
			['correlationId' => 'w-1', 'agentId' => 'agent-2', 'reviewer' => ' dora ', 'reviewerType' => 'robot'],
			'owner-1'
		);

		$this->assertSame('appr-1', $approval->getUuid());
		$this->assertSame('dora', $this->saved[0][0]['reviewer']);
		$this->assertSame('user', $this->saved[0][0]['reviewerType']);
	}//end testWebhookRunCoercesReviewerTypeAndSurvivesNotifyFailure()

	/**
	 * A tool-invocation approval for an unknown (empty) agent routes to admins, and a
	 * throwing notification is logged.
	 *
	 * @return void
	 */
	public function testToolInvocationWithoutAgentRoutesToAdminsAndSurvivesNotifyFailure(): void {
		$this->objectService->expects($this->never())->method('findAll');
		$this->objectService->expects($this->never())->method('find');
		$this->deliveryService->method('deliverApprovalRequestForToolInvocation')
			->willThrowException(new RuntimeException('down'));
		$this->logger->expects($this->once())->method('warning')
			->with('Hermiq could not notify reviewer for tool-invocation approval appr-1: down', $this->anything());

		$approval = $this->service()->ensurePendingApprovalForToolInvocation('', 'files.delete', ['path' => '/a']);

		$this->assertSame('appr-1', $approval->getUuid());
		$this->assertSame('admin', $this->saved[0][0]['reviewer']);
		$this->assertSame('tool', $this->saved[0][0]['sourceType']);
	}//end testToolInvocationWithoutAgentRoutesToAdminsAndSurvivesNotifyFailure()

	/**
	 * When the mirror task exists but its back-link cannot be written, the warning is
	 * logged and the first-saved approval (without taskUuid) is returned.
	 *
	 * @return void
	 */
	public function testFailedTaskBackLinkReturnsTheUnlinkedApproval(): void {
		$this->objectService->method('findAll')->willReturn([]);
		$this->taskBridge->expects($this->once())->method('ensureTaskFor')->willReturn('task-9');
		$this->logger->expects($this->once())->method('warning')
			->with('Hermiq could not write taskUuid task-9 back onto approval appr-1: write refused', $this->anything());

		$approval = $this->service(2)->ensurePendingApprovalForWebhookRun(['agentId' => 'agent-2'], 'owner-1');

		$this->assertCount(2, $this->saved);
		$this->assertSame('task-9', $this->saved[1][0]['taskUuid']);
		$this->assertSame('appr-1', $this->saved[1][1]);
		$this->assertArrayNotHasKey('taskUuid', $approval->getObject());
	}//end testFailedTaskBackLinkReturnsTheUnlinkedApproval()

	/**
	 * A denial whose audit write fails is still persisted and released; the failure is
	 * only logged.
	 *
	 * @return void
	 */
	public function testDenyWithFailingAuditStillPersistsAndReleases(): void {
		$this->auditTrailMapper->method('recordObjectEvent')->willThrowException(new RuntimeException('audit full'));
		$this->taskBridge->expects($this->once())->method('releaseFor')
			->with($this->isInstanceOf(ObjectEntity::class), 'denied', 'erin');
		$this->logger->expects($this->once())->method('warning')
			->with('Hermiq could not write decision audit for approval appr-7: audit full', $this->anything());

		$approval = new ObjectEntity();
		$approval->setUuid('appr-7');
		$approval->setObject(['status' => 'pending', 'sourceType' => 'tool']);
		$this->service()->deny($approval, 'erin', 'not now');

		$this->assertCount(1, $this->saved);
		$this->assertSame('appr-7', $this->saved[0][1]);
		$this->assertSame('denied', $this->saved[0][0]['status']);
		$this->assertSame('not now', $this->saved[0][0]['reason']);
	}//end testDenyWithFailingAuditStillPersistsAndReleases()
}//end class

<?php

/**
 * Unit tests for ApprovalService's confirm-classified tool-call approvals.
 *
 * Covers: raising one pending `toolcall` Approval per correlation key (routed to
 * the agent owner, else the admin group), its redacted arguments and
 * names-only prompt, a failed reviewer notification never failing the gate,
 * finding an approved, unconsumed, in-TTL approval, marking one consumed, and
 * loading an Approval by UUID.
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
 *
 * @spec openspec/specs/agent-guardrails/spec.md#requirement-a-confirm-classified-tool-call-reuses-the-existing-human-approval-gate
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Hermiq\Service\Approval\ApprovalTaskBridge;
use OCA\Hermiq\Service\ApprovalService;
use OCA\Hermiq\Service\DeliveryResult;
use OCA\Hermiq\Service\DeliveryService;
use OCA\Hermiq\Service\RedactionService;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tool-call approval tests on the real ApprovalService.
 *
 * @spec openspec/specs/agent-guardrails/spec.md#requirement-a-confirm-classified-tool-call-reuses-the-existing-human-approval-gate
 */
class ApprovalServiceToolCallTest extends TestCase {

	/**
	 * Mock ObjectService.
	 *
	 * @var ObjectService&MockObject
	 */
	private ObjectService&MockObject $objectService;

	/**
	 * Mock IGroupManager.
	 *
	 * @var IGroupManager&MockObject
	 */
	private IGroupManager&MockObject $groupManager;

	/**
	 * Mock DeliveryService.
	 *
	 * @var DeliveryService&MockObject
	 */
	private DeliveryService&MockObject $deliveryService;

	/**
	 * Mock LoggerInterface.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Payloads and uuids handed to saveObject, in order.
	 *
	 * @var array<int, array{object: array<string, mixed>, uuid: string|null}>
	 */
	private array $saved = [];

	/**
	 * Wire fresh mocks before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->saved = [];
		$this->objectService = $this->createMock(ObjectService::class);
		$this->objectService->method('setRegister')->willReturnSelf();
		$this->objectService->method('setSchema')->willReturnSelf();
		$this->objectService->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend = [], mixed $register = null, mixed $schema = null, ?string $uuid = null): ObjectEntity {
				unset($extend, $register, $schema);
				$this->saved[] = ['object' => $object, 'uuid' => $uuid];
				$entity = new ObjectEntity();
				$entity->setUuid($uuid ?? 'appr-new');
				$entity->setObject($object);
				return $entity;
			}
		);

		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->deliveryService = $this->createMock(DeliveryService::class);
		$this->logger = $this->createMock(LoggerInterface::class);

	}//end setUp()

	/**
	 * Build the service over the current mocks.
	 *
	 * @return ApprovalService
	 */
	private function service(): ApprovalService {
		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('get')->willReturn($this->createMock(IUser::class));
		$bridge = $this->createMock(ApprovalTaskBridge::class);
		$bridge->method('ensureTaskFor')->willReturn(null);

		return new ApprovalService(
			$this->objectService,
			$this->createMock(IUserSession::class),
			$userManager,
			$this->groupManager,
			$this->deliveryService,
			$this->createMock(AuditTrailMapper::class),
			new RedactionService($this->createMock(IConfig::class)),
			$this->createMock(ContainerInterface::class),
			$this->logger,
			$bridge,
		);

	}//end service()

	/**
	 * A stored toolcall Approval.
	 *
	 * @param string               $uuid    The approval uuid.
	 * @param array<string, mixed> $payload Payload overrides.
	 *
	 * @return ObjectEntity
	 */
	private function toolCallApproval(string $uuid, array $payload): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setOwner('dave');
		$entity->setObject(
			array_merge(
				[
					'sourceType' => 'toolcall',
					'correlationId' => 'corr-1',
					'status' => 'approved',
					'consumedAt' => null,
				],
				$payload
			)
		);

		return $entity;

	}//end toolCallApproval()

	/**
	 * An ISO timestamp the given number of seconds from now.
	 *
	 * @param int $offset Seconds relative to now (negative = past).
	 *
	 * @return string
	 */
	private function at(int $offset): string {
		return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify(sprintf('%+d seconds', $offset))->format('c');

	}//end at()

	/**
	 * A new tool call raises one pending toolcall Approval routed to the agent owner.
	 *
	 * @return void
	 */
	public function testEnsurePendingApprovalForToolCallCreatesAndNotifiesTheOwner(): void {
		$this->objectService->method('findAll')->willReturn([]);
		$agent = new ObjectEntity();
		$agent->setUuid('agent-1');
		$agent->setOwner('dave');
		$this->objectService->method('find')->willReturn($agent);
		$this->deliveryService->expects($this->once())
			->method('deliverApprovalRequestForToolInvocation')
			->with($this->isInstanceOf(ObjectEntity::class), ['dave'])
			->willReturn(new DeliveryResult(delivered: true, channel: 'notification', fellBack: false, warning: null));

		$approval = $this->service()->ensurePendingApprovalForToolCall(
			agentId: 'agent-1',
			toolId: 'pipelinq.lead.delete',
			arguments: ['id' => '7', 'reason' => 'duplicate'],
			correlationId: 'corr-1'
		);

		$this->assertSame('appr-new', $approval->getUuid());
		$this->assertCount(1, $this->saved);
		$payload = $this->saved[0]['object'];
		$this->assertNull($this->saved[0]['uuid']);
		$this->assertSame('pending', $payload['status']);
		$this->assertSame('toolcall', $payload['sourceType']);
		$this->assertSame('corr-1', $payload['correlationId']);
		$this->assertSame('agent-1', $payload['agentId']);
		$this->assertSame('pipelinq.lead.delete', $payload['toolId']);
		$this->assertSame(['id' => '7', 'reason' => 'duplicate'], $payload['toolArguments']);
		$this->assertSame('arguments: id, reason', $payload['prompt']);
		$this->assertSame('dave', $payload['reviewer']);
		$this->assertSame('user', $payload['reviewerType']);
		$this->assertNull($payload['consumedAt']);
		$this->assertNull($payload['decidedBy']);

	}//end testEnsurePendingApprovalForToolCallCreatesAndNotifiesTheOwner()

	/**
	 * A pending approval for the same correlation key is returned as is.
	 *
	 * @return void
	 */
	public function testEnsurePendingApprovalForToolCallIsIdempotent(): void {
		$existing = $this->toolCallApproval('appr-1', ['status' => 'pending']);
		$this->objectService->method('findAll')->willReturn(
			[
				$this->toolCallApproval('appr-0', ['status' => 'denied']),
				$this->toolCallApproval('appr-x', ['status' => 'pending', 'correlationId' => 'corr-other']),
				$existing,
			]
		);
		$this->objectService->expects($this->never())->method('saveObject');
		$this->deliveryService->expects($this->never())->method('deliverApprovalRequestForToolInvocation');

		$result = $this->service()->ensurePendingApprovalForToolCall('agent-1', 'pipelinq.lead.delete', [], 'corr-1');

		$this->assertSame($existing, $result);

	}//end testEnsurePendingApprovalForToolCallIsIdempotent()

	/**
	 * Without an agent owner the admin group reviews, every member is notified,
	 * and a failed notification is logged without failing the gate.
	 *
	 * @return void
	 */
	public function testEnsurePendingApprovalForToolCallFallsBackToTheAdminGroup(): void {
		$this->objectService->method('findAll')->willReturn([]);
		$this->objectService->method('find')->willReturn(null);
		$admin = $this->createMock(IUser::class);
		$admin->method('getUID')->willReturn('root');
		$group = $this->createMock(IGroup::class);
		$group->method('getUsers')->willReturn([$admin]);
		$this->groupManager->method('get')->with('admin')->willReturn($group);
		$this->deliveryService->expects($this->once())
			->method('deliverApprovalRequestForToolInvocation')
			->with($this->anything(), ['root'])
			->willThrowException(new RuntimeException('talk down'));
		$this->logger->expects($this->once())->method('warning');

		$approval = $this->service()->ensurePendingApprovalForToolCall('agent-1', 'pipelinq.lead.delete', [], 'corr-1');

		$this->assertSame('appr-new', $approval->getUuid());
		$this->assertSame('admin', $this->saved[0]['object']['reviewer']);
		$this->assertSame('group', $this->saved[0]['object']['reviewerType']);
		$this->assertSame('(no arguments)', $this->saved[0]['object']['prompt']);

	}//end testEnsurePendingApprovalForToolCallFallsBackToTheAdminGroup()

	/**
	 * Only an approved, unconsumed approval decided within the TTL is returned.
	 *
	 * @return void
	 */
	public function testFindApprovedUnconsumedToolCallApprovalHonoursConsumptionAndTtl(): void {
		$valid = $this->toolCallApproval('appr-ok', ['decidedAt' => $this->at(-300)]);
		$this->objectService->method('findAll')->willReturn(
			[
				$this->toolCallApproval('appr-pending', ['status' => 'pending', 'decidedAt' => $this->at(-60)]),
				$this->toolCallApproval('appr-used', ['decidedAt' => $this->at(-60), 'consumedAt' => $this->at(-30)]),
				$this->toolCallApproval('appr-old', ['decidedAt' => $this->at(-(ApprovalService::TOOLCALL_APPROVAL_TTL_SECONDS + 60))]),
				$this->toolCallApproval('appr-undated', []),
				$this->toolCallApproval('appr-garbled', ['decidedAt' => 'not a date']),
				$this->toolCallApproval('appr-future', ['decidedAt' => $this->at(600)]),
				$this->toolCallApproval('appr-other', ['decidedAt' => $this->at(-60), 'correlationId' => 'corr-other']),
				$valid,
			]
		);

		$this->assertSame($valid, $this->service()->findApprovedUnconsumedToolCallApproval(correlationId: 'corr-1'));

	}//end testFindApprovedUnconsumedToolCallApprovalHonoursConsumptionAndTtl()

	/**
	 * Nothing usable — or an empty key — finds nothing, and an empty key never queries.
	 *
	 * @return void
	 */
	public function testFindApprovedUnconsumedToolCallApprovalReturnsNullWhenNoneQualifies(): void {
		$this->objectService->expects($this->once())->method('findAll')->willReturn(
			[
				$this->toolCallApproval('appr-used', ['decidedAt' => $this->at(-60), 'consumedAt' => $this->at(-30)]),
			]
		);
		$service = $this->service();

		$this->assertNull($service->findApprovedUnconsumedToolCallApproval(correlationId: 'corr-1'));
		$this->assertNull($service->findApprovedUnconsumedToolCallApproval(correlationId: ''));

	}//end testFindApprovedUnconsumedToolCallApprovalReturnsNullWhenNoneQualifies()

	/**
	 * Marking consumed re-saves the same approval with a consumedAt stamp.
	 *
	 * @return void
	 */
	public function testMarkToolCallApprovalConsumedStampsConsumedAt(): void {
		$approval = $this->toolCallApproval('appr-ok', ['decidedAt' => $this->at(-60)]);

		$this->service()->markToolCallApprovalConsumed(approval: $approval);

		$this->assertCount(1, $this->saved);
		$this->assertSame('appr-ok', $this->saved[0]['uuid']);
		$this->assertSame('approved', $this->saved[0]['object']['status']);
		$this->assertNotSame('', (string)$this->saved[0]['object']['consumedAt']);
		$this->assertNotFalse(DateTimeImmutable::createFromFormat(DATE_ATOM, $this->saved[0]['object']['consumedAt']));

	}//end testMarkToolCallApprovalConsumedStampsConsumedAt()

	/**
	 * loadApproval returns the stored entity, null when absent, and skips the lookup
	 * for an empty uuid.
	 *
	 * @return void
	 */
	public function testLoadApprovalReturnsTheEntityOrNull(): void {
		$stored = $this->toolCallApproval('appr-1', []);
		$this->objectService->expects($this->exactly(2))->method('find')->willReturnOnConsecutiveCalls($stored, null);
		$service = $this->service();

		$this->assertSame($stored, $service->loadApproval(uuid: 'appr-1'));
		$this->assertNull($service->loadApproval(uuid: 'appr-missing'));
		$this->assertNull($service->loadApproval(uuid: ''));

	}//end testLoadApprovalReturnsTheEntityOrNull()
}//end class

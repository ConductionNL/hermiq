<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\Hermiq\Tests\Unit\Service\Handoff
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/hermiq
 *
 * @spec openspec/changes/flows-hand-off-and-wait/specs/agent-handoffs/spec.md#requirement-a-finished-handoff-returns-its-result-as-a-new-turn-in-the-same-conversation-req-handoff-002
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Handoff;

use OCA\Hermiq\BackgroundJob\HandoffResultJob;
use OCA\Hermiq\Kernel\Contracts\MessageHistoryHandlerInterface;
use OCA\Hermiq\Service\Handoff\HandoffDelivery;
use OCA\Hermiq\Service\ScheduleService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * A closed handoff comes back as one governed turn, or is kept when a gate holds the agent.
 *
 * @covers \OCA\Hermiq\Service\Handoff\HandoffDelivery
 * @covers \OCA\Hermiq\BackgroundJob\HandoffResultJob
 */
class HandoffDeliveryTest extends TestCase {

	/**
	 * Stored objects by uuid.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $store = [];

	/**
	 * OpenRegister's object service.
	 *
	 * @var ObjectService&MockObject
	 */
	private ObjectService&MockObject $objects;

	/**
	 * The governed run path and its gates.
	 *
	 * @var ScheduleService&MockObject
	 */
	private ScheduleService&MockObject $schedules;

	/**
	 * The conversation's message store.
	 *
	 * @var MessageHistoryHandlerInterface&MockObject
	 */
	private MessageHistoryHandlerInterface&MockObject $messages;

	/**
	 * The notification manager.
	 *
	 * @var INotificationManager&MockObject
	 */
	private INotificationManager&MockObject $notifications;

	/**
	 * The subjects of the notifications sent.
	 *
	 * @var array<int, array{0: string, 1: string, 2: array<string, mixed>}>
	 */
	private array $sent = [];

	/**
	 * The service under test.
	 *
	 * @var HandoffDelivery
	 */
	private HandoffDelivery $delivery;

	/**
	 * Build the delivery over an in-memory store with a conversation of organisation org-1.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->objects = $this->createMock(ObjectService::class);
		$this->objects->method('find')->willReturnCallback(function (string $id): ?ObjectEntity {
			if (isset($this->store[$id]) === false) {
				return null;
			}

			$entity = $this->createMock(ObjectEntity::class);
			$entity->method('getObject')->willReturn($this->store[$id]);
			$entity->method('getOrganisation')->willReturn(($id === 'conv-1') ? 'org-1' : null);

			return $entity;
		});
		$this->store['conv-1'] = ['userId' => 'anne'];

		$this->schedules = $this->createMock(ScheduleService::class);
		$this->messages = $this->createMock(MessageHistoryHandlerInterface::class);

		$this->notifications = $this->createMock(INotificationManager::class);
		$this->notifications->method('createNotification')->willReturnCallback(function (): INotification {
			$notification = $this->createMock(INotification::class);
			$notification->method('setApp')->willReturnSelf();
			$notification->method('setDateTime')->willReturnSelf();
			$notification->method('setObject')->willReturnSelf();
			$user = '';
			$notification->method('setUser')->willReturnCallback(function (string $uid) use (&$user, $notification) {
				$user = $uid;
				return $notification;
			});
			$notification->method('setSubject')->willReturnCallback(function (string $subject, array $parameters) use (&$user, $notification) {
				$this->sent[] = [$user, $subject, $parameters];
				return $notification;
			});

			return $notification;
		});

		$this->delivery = new HandoffDelivery(
			objectService: $this->objects,
			schedules: $this->schedules,
			messages: $this->messages,
			notifications: $this->notifications,
			logger: $this->createMock(LoggerInterface::class)
		);
	}//end setUp()

	/**
	 * A handoff in the given state.
	 *
	 * @param string               $status The status.
	 * @param array<string, mixed> $extra  Fields to override.
	 *
	 * @return array<string, mixed>
	 */
	private function handoff(string $status, array $extra = []): array {
		return $extra + [
			'kind'           => 'flow',
			'agentId'        => 'agent-1',
			'conversationId' => 'conv-1',
			'requestedBy'    => 'anne',
			'label'          => 'Vergunningcheck',
			'target'         => 'flow-vergunningcheck',
			'externalRef'    => 'run-42',
			'status'         => $status,
			'result'         => ['vergunningVereist' => true],
			'requestedAt'    => '2026-11-13T14:02:00+00:00',
			'deadline'       => '2026-11-14T14:02:00+00:00',
			'closedAt'       => '2026-11-13T15:10:00+00:00',
		];
	}//end handoff()

	/**
	 * A done handoff runs one governed turn as its owner in its conversation, with the result delimited, and notifies the owner.
	 *
	 * @return void
	 */
	public function testADoneHandoffRunsOneTurnAndNotifiesTheOwner(): void {
		$this->store['h-1'] = $this->handoff(status: 'done');
		$this->schedules->expects($this->once())->method('gateFor')->with('org-1', 'agent-1')->willReturn(null);
		$this->schedules->expects($this->once())->method('runAgentAsOwner')
			->with(
				'anne',
				'agent-1',
				"Result of 'Vergunningcheck', started 14:02:\n" . HandoffDelivery::UNTRUSTED_BEGIN . "\n{\n    \"vergunningVereist\": true\n}\n" . HandoffDelivery::UNTRUSTED_END,
				'org-1',
				false,
				false,
				null,
				null,
				[],
				'conv-1'
			)
			->willReturn('Er is een vergunning nodig.');
		$this->messages->expects($this->never())->method('storeMessage');

		$this->assertSame('delivered', $this->delivery->deliver(handoffId: 'h-1'));
		$this->assertSame([['anne', 'handoff_result', ['name' => 'Vergunningcheck', 'sessionId' => 'conv-1']]], $this->sent);
	}//end testADoneHandoffRunsOneTurnAndNotifiesTheOwner()

	/**
	 * A failed handoff still comes back, worded as work that could not be carried out.
	 *
	 * @return void
	 */
	public function testAFailedHandoffSaysTheWorkCouldNotBeCarriedOut(): void {
		$failed = $this->handoff(status: 'failed', extra: ['result' => ['error' => 'Flow stopped']]);

		$prompt = $this->delivery->prompt(handoff: $failed);

		$this->assertStringStartsWith("'Vergunningcheck', started 14:02, could not be carried out:\n" . HandoffDelivery::UNTRUSTED_BEGIN, $prompt);
		$this->assertStringContainsString('"error": "Flow stopped"', $prompt);
		$this->assertStringEndsWith(HandoffDelivery::UNTRUSTED_END, $prompt);
	}//end testAFailedHandoffSaysTheWorkCouldNotBeCarriedOut()

	/**
	 * A switched-off agent, an engaged kill switch or a reached budget keeps the result and runs nothing.
	 *
	 * @return void
	 *
	 * @dataProvider gates
	 */
	public function testAGateKeepsTheResultAndRunsNoTurn(string $gate): void {
		$this->store['h-1'] = $this->handoff(status: 'done');
		$this->schedules->method('gateFor')->willReturn($gate);
		$this->schedules->expects($this->never())->method('runAgentAsOwner');
		$this->objects->expects($this->never())->method('saveObject');

		$this->assertSame($gate, $this->delivery->deliver(handoffId: 'h-1'));
		$this->assertSame([], $this->sent);
	}//end testAGateKeepsTheResultAndRunsNoTurn()

	/**
	 * The gates a scheduled run passes.
	 *
	 * @return array<string, array{string}>
	 */
	public static function gates(): array {
		return [
			'agent switched off' => ['skipped_agent_off'],
			'kill switch'        => ['skipped_killswitch'],
			'budget cap'         => ['skipped_budget'],
		];
	}//end gates()

	/**
	 * A turn that fails is reported, and nobody is told a result arrived.
	 *
	 * @return void
	 */
	public function testAFailingTurnSendsNoNotification(): void {
		$this->store['h-1'] = $this->handoff(status: 'done');
		$this->schedules->method('gateFor')->willReturn(null);
		$this->schedules->method('runAgentAsOwner')->willThrowException(new RuntimeException('Run-as user does not exist'));

		$this->assertSame('failed', $this->delivery->deliver(handoffId: 'h-1'));
		$this->assertSame([], $this->sent);
	}//end testAFailingTurnSendsNoNotification()

	/**
	 * An expired handoff posts one system note with the hours it waited, and runs no turn.
	 *
	 * @return void
	 */
	public function testAnExpiredHandoffPostsANote(): void {
		$this->store['h-1'] = $this->handoff(status: 'expired', extra: ['label' => 'Subsidieaanvraag beoordelen', 'result' => null]);
		$this->schedules->expects($this->never())->method('gateFor');
		$this->schedules->expects($this->never())->method('runAgentAsOwner');
		$this->messages->expects($this->once())->method('storeMessage')
			->with('conv-1', 'system', "No result from 'Subsidieaanvraag beoordelen' within 24 hours.");

		$this->assertSame('noted_expired', $this->delivery->deliver(handoffId: 'h-1'));
	}//end testAnExpiredHandoffPostsANote()

	/**
	 * A handoff that is gone or still waiting is skipped.
	 *
	 * @return void
	 */
	public function testAMissingOrWaitingHandoffIsSkipped(): void {
		$this->store['h-2'] = $this->handoff(status: 'waiting');
		$this->schedules->expects($this->never())->method('runAgentAsOwner');
		$this->messages->expects($this->never())->method('storeMessage');

		$this->assertSame('skipped', $this->delivery->deliver(handoffId: 'gone'));
		$this->assertSame('skipped', $this->delivery->deliver(handoffId: 'h-2'));
		$this->assertSame('skipped', $this->delivery->deliver(handoffId: ''));
	}//end testAMissingOrWaitingHandoffIsSkipped()

	/**
	 * The job hands its handoff id to the delivery, and an argument without one delivers nothing.
	 *
	 * @return void
	 */
	public function testTheJobDeliversTheHandoffItNames(): void {
		$delivery = $this->createMock(HandoffDelivery::class);
		$delivery->expects($this->exactly(2))->method('deliver')
			->willReturnCallback(function (string $handoffId): string {
				static $calls = 0;
				$calls++;
				$this->assertSame(($calls === 1) ? 'h-1' : '', $handoffId);
				return 'skipped';
			});
		$job = new HandoffResultJob(time: $this->createMock(ITimeFactory::class), delivery: $delivery);

		$run = new \ReflectionMethod($job, 'run');
		$run->invoke($job, ['handoffId' => 'h-1']);
		$run->invoke($job, 'not-an-array');
	}//end testTheJobDeliversTheHandoffItNames()
}//end class

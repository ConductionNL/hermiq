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
 * @spec openspec/changes/flows-hand-off-and-wait/specs/agent-handoffs/spec.md#requirement-work-an-agent-starts-and-waits-for-is-recorded-as-a-handoff-req-handoff-001
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Handoff;

use DateTimeImmutable;
use InvalidArgumentException;
use OCA\Hermiq\BackgroundJob\HandoffResultJob;
use OCA\Hermiq\Service\Handoff\HandoffService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The handoff record: what it opens with, and its one-way trip out of `waiting`.
 *
 * @covers \OCA\Hermiq\Service\Handoff\HandoffService
 */
class HandoffServiceTest extends TestCase {

	/**
	 * The fixed moment every test runs at.
	 */
	private const NOW = '2026-11-13T14:02:00+00:00';

	/**
	 * OpenRegister's object service.
	 *
	 * @var ObjectService&MockObject
	 */
	private ObjectService&MockObject $objects;

	/**
	 * The clock.
	 *
	 * @var ITimeFactory&MockObject
	 */
	private ITimeFactory&MockObject $clock;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The background job list the delivery is queued on.
	 *
	 * @var IJobList&MockObject
	 */
	private IJobList&MockObject $jobs;

	/**
	 * The service under test.
	 *
	 * @var HandoffService
	 */
	private HandoffService $service;

	/**
	 * Build the service on a fixed clock.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->objects = $this->createMock(ObjectService::class);
		$this->clock = $this->createMock(ITimeFactory::class);
		$this->clock->method('now')->willReturnCallback(fn (): DateTimeImmutable => new DateTimeImmutable(self::NOW));
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->jobs = $this->createMock(IJobList::class);
		$this->service = new HandoffService(objectService: $this->objects, timeFactory: $this->clock, logger: $this->logger, jobList: $this->jobs);
	}//end setUp()

	/**
	 * A new handoff has its conversation, owner and kind, is waiting, and expires in 24 hours by default.
	 *
	 * @return void
	 */
	public function testANewHandoffWaitsWithADayToAnswer(): void {
		$stored = null;
		$this->objects->expects($this->once())->method('saveObject')
			->willReturnCallback(function (array $object, ?array $extend, string $register, string $schema) use (&$stored): ObjectEntity {
				$stored = ['object' => $object, 'register' => $register, 'schema' => $schema];
				return $this->entity(uuid: 'h-1', data: $object);
			});

		$handoff = $this->service->open(handoff: $this->request());

		$this->assertSame('hermiq', $stored['register']);
		$this->assertSame('agenthandoff', $stored['schema']);
		$this->assertSame(
			[
				'kind'           => 'flow',
				'agentId'        => 'agent-1',
				'conversationId' => 'conv-1',
				'requestedBy'    => 'anne',
				'label'          => 'Vergunningcheck',
				'target'         => 'flow-vergunningcheck',
				'externalRef'    => 'run-42',
				'status'         => 'waiting',
				'result'         => null,
				'requestedAt'    => '2026-11-13T14:02:00+00:00',
				'deadline'       => '2026-11-14T14:02:00+00:00',
				'closedAt'       => null,
			],
			$stored['object']
		);
		$this->assertSame('h-1', $handoff['id']);
		$this->assertSame('waiting', $handoff['status']);
	}//end testANewHandoffWaitsWithADayToAnswer()

	/**
	 * A deadline asked for is kept up to 7 days and capped there; nonsense falls back to a day.
	 *
	 * @return void
	 */
	public function testTheDeadlineIsCappedAtSevenDays(): void {
		$deadlines = [];
		$this->objects->method('saveObject')
			->willReturnCallback(function (array $object) use (&$deadlines): ObjectEntity {
				$deadlines[] = $object['deadline'];
				return $this->entity(uuid: 'h', data: $object);
			});

		foreach ([2, 168, 500, 0, -3, '12'] as $hours) {
			$this->service->open(handoff: ['deadlineHours' => $hours] + $this->request());
		}

		$this->assertSame(
			[
				'2026-11-13T16:02:00+00:00',
				'2026-11-20T14:02:00+00:00',
				'2026-11-20T14:02:00+00:00',
				'2026-11-14T14:02:00+00:00',
				'2026-11-14T14:02:00+00:00',
				'2026-11-14T14:02:00+00:00',
			],
			$deadlines
		);
	}//end testTheDeadlineIsCappedAtSevenDays()

	/**
	 * Without its conversation, owner, kind or any other required field nothing is stored.
	 *
	 * @return void
	 */
	public function testAnIncompleteHandoffIsRefused(): void {
		$this->objects->expects($this->never())->method('saveObject');

		foreach (['kind', 'agentId', 'conversationId', 'requestedBy', 'label', 'target'] as $field) {
			$request = $this->request();
			$request[$field] = ' ';
			try {
				$this->service->open(handoff: $request);
				$this->fail('Opened without ' . $field);
			} catch (InvalidArgumentException $e) {
				$this->assertSame('A handoff needs a ' . $field . '.', $e->getMessage());
			}
		}
	}//end testAnIncompleteHandoffIsRefused()

	/**
	 * An unknown kind is refused.
	 *
	 * @return void
	 */
	public function testAnUnknownKindIsRefused(): void {
		$this->objects->expects($this->never())->method('saveObject');
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('A handoff cannot wait on "email".');

		$this->service->open(handoff: ['kind' => 'email'] + $this->request());
	}//end testAnUnknownKindIsRefused()

	/**
	 * Closing a waiting handoff stores the result, the status and when, with every other field carried forward.
	 *
	 * @return void
	 */
	public function testAWaitingHandoffClosesWithItsResult(): void {
		$this->objects->method('find')->willReturn($this->entity(uuid: 'h-1', data: $this->waitingHandoff()));
		$this->objects->expects($this->once())->method('saveObject')
			->with(
				['status' => 'done', 'result' => ['vergunningVereist' => true], 'closedAt' => '2026-11-13T14:02:00+00:00'] + $this->waitingHandoff(),
				[],
				'hermiq',
				'agenthandoff',
				'h-1',
				false,
				false
			)
			->willReturn($this->entity(uuid: 'h-1', data: []));
		$this->jobs->expects($this->once())->method('add')->with(HandoffResultJob::class, ['handoffId' => 'h-1']);

		$this->assertTrue($this->service->close(handoffId: 'h-1', status: 'done', result: ['vergunningVereist' => true]));
	}//end testAWaitingHandoffClosesWithItsResult()

	/**
	 * A result that is not a JSON object is kept under value, so the object-typed property stores it.
	 *
	 * @param mixed                $result The result the work returned.
	 * @param array<string, mixed> $stored The result as it is saved.
	 *
	 * @return void
	 *
	 * @dataProvider nonObjectResults
	 */
	public function testANonObjectResultIsKeptUnderValue(mixed $result, array $stored): void {
		$this->objects->method('find')->willReturn($this->entity(uuid: 'h-1', data: $this->waitingHandoff()));
		$this->objects->expects($this->once())->method('saveObject')
			->with(
				['status' => 'done', 'result' => $stored, 'closedAt' => '2026-11-13T14:02:00+00:00'] + $this->waitingHandoff(),
				[],
				'hermiq',
				'agenthandoff',
				'h-1',
				false,
				false
			)
			->willReturn($this->entity(uuid: 'h-1', data: []));

		$this->assertTrue($this->service->close(handoffId: 'h-1', status: 'done', result: $result));
	}//end testANonObjectResultIsKeptUnderValue()

	/**
	 * Results that are not a JSON object, and how they are stored.
	 *
	 * @return array<string, array{mixed, array<string, mixed>}>
	 */
	public static function nonObjectResults(): array {
		return [
			'a list of flow items' => [[['id' => 1], ['id' => 2]], ['value' => [['id' => 1], ['id' => 2]]]],
			'a single value'       => ['vergunning vereist', ['value' => 'vergunning vereist']],
		];
	}//end nonObjectResults()

	/**
	 * A second close is ignored: the first answer stands.
	 *
	 * @return void
	 */
	public function testASecondCloseIsIgnored(): void {
		$closed = ['status' => 'done', 'result' => ['first' => true], 'closedAt' => '2026-11-13T13:00:00+00:00'] + $this->waitingHandoff();
		$this->objects->method('find')->willReturn($this->entity(uuid: 'h-1', data: $closed));
		$this->objects->expects($this->never())->method('saveObject');
		$this->jobs->expects($this->never())->method('add');
		$this->logger->expects($this->once())->method('info')
			->with('[HandoffService] A handoff that is no longer waiting was left as it is', ['handoffId' => 'h-1', 'status' => 'done']);

		$this->assertFalse($this->service->close(handoffId: 'h-1', status: 'failed', result: ['second' => true]));
	}//end testASecondCloseIsIgnored()

	/**
	 * A close for a handoff that does not exist, or with a status a close may not set, changes nothing.
	 *
	 * @return void
	 */
	public function testAMissingHandoffOrABadStatusChangesNothing(): void {
		$this->objects->method('find')->willReturn(null);
		$this->objects->expects($this->never())->method('saveObject');

		$this->assertFalse($this->service->close(handoffId: 'gone', status: 'done', result: null));
		$this->assertFalse($this->service->close(handoffId: '', status: 'done', result: null));

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('A handoff cannot be closed as "expired".');
		$this->service->close(handoffId: 'h-1', status: 'expired', result: null);
	}//end testAMissingHandoffOrABadStatusChangesNothing()

	/**
	 * A waiting handoff past its deadline expires; one still in time stays waiting.
	 *
	 * @return void
	 */
	public function testOnlyAPastDeadlineExpires(): void {
		$late = ['deadline' => '2026-11-13T14:01:59+00:00'] + $this->waitingHandoff();
		$inTime = ['deadline' => '2026-11-13T14:02:01+00:00'] + $this->waitingHandoff();
		$this->objects->method('find')->willReturnCallback(
			fn (string $id): ObjectEntity => $this->entity(uuid: $id, data: ($id === 'late') ? $late : $inTime)
		);
		$this->objects->expects($this->once())->method('saveObject')
			->with(
				['status' => 'expired', 'closedAt' => '2026-11-13T14:02:00+00:00'] + $late,
				[],
				'hermiq',
				'agenthandoff',
				'late',
				false,
				false
			)
			->willReturn($this->entity(uuid: 'late', data: []));
		$this->jobs->expects($this->once())->method('add')->with(HandoffResultJob::class, ['handoffId' => 'late']);

		$this->assertFalse($this->service->expireIfDue(handoffId: 'in-time'));
		$this->assertTrue($this->service->expireIfDue(handoffId: 'late'));
	}//end testOnlyAPastDeadlineExpires()

	/**
	 * A waiting handoff without a readable deadline expires rather than waiting forever.
	 *
	 * @return void
	 */
	public function testAHandoffWithoutAReadableDeadlineExpires(): void {
		$broken = ['deadline' => 'tomorrow-ish'] + $this->waitingHandoff();
		$this->objects->method('find')->willReturn($this->entity(uuid: 'h-1', data: $broken));
		$this->objects->expects($this->once())->method('saveObject')->willReturn($this->entity(uuid: 'h-1', data: []));

		$this->assertTrue($this->service->expireIfDue(handoffId: 'h-1'));
	}//end testAHandoffWithoutAReadableDeadlineExpires()

	/**
	 * The waiting handoff of a run is found by its kind and external reference; a closed or other one is not.
	 *
	 * @return void
	 */
	public function testTheWaitingHandoffOfARunIsFound(): void {
		$this->objects->method('setRegister')->willReturnSelf();
		$this->objects->method('setSchema')->willReturnSelf();
		$this->objects->expects($this->once())->method('findAll')
			->with(['filters' => ['kind' => 'flow', 'externalRef' => 'run-42', 'status' => 'waiting'], 'limit' => 10], false, false)
			->willReturn([
				$this->entity(uuid: 'closed', data: ['status' => 'done'] + $this->waitingHandoff()),
				$this->entity(uuid: 'other-run', data: ['externalRef' => 'run-7'] + $this->waitingHandoff()),
				$this->entity(uuid: 'h-1', data: $this->waitingHandoff()),
			]);

		$this->assertSame('h-1', $this->service->waitingFor(kind: 'flow', externalRef: 'run-42'));
		$this->assertNull($this->service->waitingFor(kind: 'flow', externalRef: ''));
	}//end testTheWaitingHandoffOfARunIsFound()

	/**
	 * A webhook handoff keeps the token's hash it was opened with; find() reads a handoff in any state.
	 *
	 * @return void
	 */
	public function testAWebhookHandoffKeepsItsTokenHashAndIsFound(): void {
		$saved = [];
		$this->objects->method('saveObject')->willReturnCallback(function (array $object) use (&$saved): ObjectEntity {
			$saved = $object;
			return $this->entity(uuid: 'h-9', data: $object);
		});
		$this->service->open(handoff: ['kind' => 'webhook', 'resultTokenHash' => 'abc123'] + $this->waitingHandoff());
		$this->assertSame('abc123', $saved['resultTokenHash']);

		$this->objects->method('find')->willReturnCallback(
			fn (string $id): ?ObjectEntity => ($id === 'h-9') ? $this->entity(uuid: 'h-9', data: ['status' => 'done'] + $saved) : null
		);
		$this->assertSame('done', $this->service->find(handoffId: 'h-9')['status']);
		$this->assertNull($this->service->find(handoffId: 'gone'));
		$this->assertNull($this->service->find(handoffId: ''));
	}//end testAWebhookHandoffKeepsItsTokenHashAndIsFound()

	/**
	 * A closed handoff does not expire later.
	 *
	 * @return void
	 */
	public function testAClosedHandoffDoesNotExpire(): void {
		$done = ['status' => 'done', 'deadline' => '2026-11-01T00:00:00+00:00'] + $this->waitingHandoff();
		$this->objects->method('find')->willReturn($this->entity(uuid: 'h-1', data: $done));
		$this->objects->expects($this->never())->method('saveObject');

		$this->assertFalse($this->service->expireIfDue(handoffId: 'h-1'));
	}//end testAClosedHandoffDoesNotExpire()

	/**
	 * What a tool asks for when it opens a handoff.
	 *
	 * @return array<string, mixed>
	 */
	private function request(): array {
		return [
			'kind'           => 'flow',
			'agentId'        => 'agent-1',
			'conversationId' => 'conv-1',
			'requestedBy'    => 'anne',
			'label'          => 'Vergunningcheck',
			'target'         => 'flow-vergunningcheck',
			'externalRef'    => 'run-42',
		];
	}//end request()

	/**
	 * A stored handoff that is still waiting.
	 *
	 * @return array<string, mixed>
	 */
	private function waitingHandoff(): array {
		return [
			'kind'           => 'flow',
			'agentId'        => 'agent-1',
			'conversationId' => 'conv-1',
			'requestedBy'    => 'anne',
			'label'          => 'Vergunningcheck',
			'target'         => 'flow-vergunningcheck',
			'externalRef'    => 'run-42',
			'status'         => 'waiting',
			'result'         => null,
			'requestedAt'    => '2026-11-13T13:00:00+00:00',
			'deadline'       => '2026-11-14T13:00:00+00:00',
			'closedAt'       => null,
		];
	}//end waitingHandoff()

	/**
	 * An OpenRegister object double carrying a uuid and its data.
	 *
	 * @param string               $uuid The uuid.
	 * @param array<string, mixed> $data The object data.
	 *
	 * @return ObjectEntity&MockObject
	 */
	private function entity(string $uuid, array $data): ObjectEntity {
		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('getUuid')->willReturn($uuid);
		$entity->method('getObject')->willReturn($data);

		return $entity;
	}//end entity()
}//end class

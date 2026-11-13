<?php

declare(strict_types=1);

/**
 * TalkRoomBinding behaviour tests: resolving the session bound to a room,
 * binding a session to a room, and opening a session for a room.
 *
 * The roster sync has its own file (TalkRoomBindingSyncTest).
 *
 * @category Tests
 * @package  OCA\Hermiq\Tests\Unit\Service\Talk
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

namespace OCA\Hermiq\Tests\Unit\Service\Talk;

use OCA\Hermiq\Service\Talk\TalkRoomBinding;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Hermiq\Service\Talk\TalkRoomBinding
 */
class TalkRoomBindingTest extends TestCase {

	private ObjectService&MockObject $objectService;

	private LoggerInterface&MockObject $logger;

	private TalkRoomBinding $binding;

	/**
	 * Arguments of the last saveObject call.
	 *
	 * @var array<int, mixed>
	 */
	private array $saved = [];

	protected function setUp(): void {
		$this->objectService = $this->createMock(ObjectService::class);
		$this->objectService->method('setRegister')->willReturnSelf();
		$this->objectService->method('setSchema')->willReturnSelf();
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->binding = new TalkRoomBinding($this->objectService, $this->logger);

	}//end setUp()

	/**
	 * A session object.
	 *
	 * @param array<string, mixed> $payload The object payload.
	 *
	 * @return ObjectEntity
	 */
	private function session(array $payload): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('session-1');
		$entity->setObject($payload);

		return $entity;

	}//end session()

	/**
	 * Make saveObject record its arguments and return `$result`.
	 *
	 * @param ObjectEntity $result What saveObject returns.
	 *
	 * @return void
	 */
	private function saveRecording(ObjectEntity $result): void {
		$this->objectService->expects($this->once())->method('saveObject')->willReturnCallback(
			function (...$args) use ($result): ObjectEntity {
				$this->saved = $args;
				return $result;
			}
		);

	}//end saveRecording()

	public function testAnEmptyTokenResolvesNothingWithoutAQuery(): void {
		$this->objectService->expects($this->never())->method('findAll');

		$this->assertNull($this->binding->findByRoomToken(''));
		$this->assertFalse($this->binding->isCreatedRoom(''));

	}//end testAnEmptyTokenResolvesNothingWithoutAQuery()

	public function testTheFirstEntityIsReturnedAndNonEntitiesAreSkipped(): void {
		$session = $this->session([]);
		$this->objectService->method('findAll')->willReturn([['uuid' => 'array-row'], $session]);

		$this->assertSame($session, $this->binding->findByRoomToken('tok'));

	}//end testTheFirstEntityIsReturnedAndNonEntitiesAreSkipped()

	public function testALookupFailureIsLoggedAndResolvesNothing(): void {
		$this->objectService->method('findAll')->willThrowException(new RuntimeException('db down'));
		$this->logger->expects($this->once())->method('warning')->with(
			'[TalkRoomBinding] Could not resolve the conversation bound to the room',
			$this->callback(fn (array $context): bool => $context['roomToken'] === 'tok' && $context['error'] === 'db down')
		);

		$this->assertNull($this->binding->findByRoomToken('tok'));

	}//end testALookupFailureIsLoggedAndResolvesNothing()

	public function testOnlyARoomHermiqCreatedCountsAsCreated(): void {
		$this->objectService->method('findAll')->willReturnOnConsecutiveCalls(
			[$this->session(['talkRoomOrigin' => 'created'])],
			[$this->session(['talkRoomOrigin' => 'bound'])],
			[$this->session([])],
			[]
		);

		$this->assertTrue($this->binding->isCreatedRoom('tok'));
		$this->assertFalse($this->binding->isCreatedRoom('tok'));
		$this->assertFalse($this->binding->isCreatedRoom('tok'));
		$this->assertFalse($this->binding->isCreatedRoom('tok'));

	}//end testOnlyARoomHermiqCreatedCountsAsCreated()

	public function testBindWritesTheTokenAndCarriesThePayloadForward(): void {
		$this->saveRecording(new ObjectEntity());

		$this->assertTrue($this->binding->bind($this->session(['title' => 'Plan', 'userId' => 'alice']), 'tok'));
		$this->assertSame(
			[['id' => 'session-1', 'title' => 'Plan', 'userId' => 'alice', 'talkRoomToken' => 'tok'], 'hermiq', 'agentsession', 'session-1'],
			[$this->saved[0], $this->saved[2], $this->saved[3], $this->saved[4]]
		);

	}//end testBindWritesTheTokenAndCarriesThePayloadForward()

	public function testBindIsANoOpForTheSameTokenAndRefusesAnEmptyOne(): void {
		$this->objectService->expects($this->never())->method('saveObject');

		$this->assertTrue($this->binding->bind($this->session(['talkRoomToken' => 'tok']), 'tok'));
		$this->assertFalse($this->binding->bind($this->session([]), ''));

	}//end testBindIsANoOpForTheSameTokenAndRefusesAnEmptyOne()

	public function testAFailedBindIsLoggedAndReportsFalse(): void {
		$this->objectService->method('saveObject')->willThrowException(new RuntimeException('save failed'));
		$this->logger->expects($this->once())->method('warning')->with(
			'[TalkRoomBinding] Could not bind the conversation to the room (delivery is unaffected)',
			$this->callback(
				fn (array $context): bool => $context['roomToken'] === 'tok' && $context['conversation'] === 'session-1' && $context['error'] === 'save failed'
			)
		);

		$this->assertFalse($this->binding->bind($this->session([]), 'tok'));

	}//end testAFailedBindIsLoggedAndReportsFalse()

	public function testBindByUuidLoadsTheSessionThenBindsIt(): void {
		$this->objectService->expects($this->once())->method('find')
			->with('session-1', [], false, 'hermiq', 'agentsession')
			->willReturn($this->session(['title' => 'Plan']));
		$this->saveRecording(new ObjectEntity());

		$this->assertTrue($this->binding->bindByUuid('session-1', 'tok'));
		$this->assertSame('tok', $this->saved[0]['talkRoomToken']);

	}//end testBindByUuidLoadsTheSessionThenBindsIt()

	public function testBindByUuidRefusesMissingInputAndAnUnknownSession(): void {
		$this->objectService->expects($this->once())->method('find')->willReturn(null);
		$this->objectService->expects($this->never())->method('saveObject');

		$this->assertFalse($this->binding->bindByUuid('', 'tok'));
		$this->assertFalse($this->binding->bindByUuid('session-1', ''));
		$this->assertFalse($this->binding->bindByUuid('session-1', 'tok'));

	}//end testBindByUuidRefusesMissingInputAndAnUnknownSession()

	public function testAFailedLoadIsLoggedAndReportsFalse(): void {
		$this->objectService->method('find')->willThrowException(new RuntimeException('not found'));
		$this->logger->expects($this->once())->method('warning')->with(
			'[TalkRoomBinding] Could not load the conversation to bind (delivery is unaffected)',
			$this->callback(fn (array $context): bool => $context['conversation'] === 'session-1' && $context['error'] === 'not found')
		);

		$this->assertFalse($this->binding->bindByUuid('session-1', 'tok'));

	}//end testAFailedLoadIsLoggedAndReportsFalse()

	public function testCreateBoundOpensASessionWithACleanRoster(): void {
		$created = new ObjectEntity();
		$created->setUuid('new-1');
		$this->saveRecording($created);
		$this->logger->expects($this->once())->method('info')->with(
			'[TalkRoomBinding] Opened a conversation bound to a Talk room',
			$this->callback(
				fn (array $context): bool => $context['roomToken'] === 'tok' && $context['conversation'] === 'new-1' && $context['participants'] === 2
			)
		);

		$this->assertSame($created, $this->binding->createBound('tok', 'agent-1', 'alice', ['bob', 'alice', '', 7, 'carol']));
		$this->assertSame(
			[
				['title' => 'Talk conversation', 'userId' => 'alice', 'agentId' => 'agent-1', 'talkRoomToken' => 'tok', 'participants' => ['bob', 'carol']],
				'hermiq',
				'agentsession',
			],
			[$this->saved[0], $this->saved[2], $this->saved[3]]
		);

	}//end testCreateBoundOpensASessionWithACleanRoster()

	public function testAFailedCreateIsLoggedAsAnErrorAndYieldsNull(): void {
		$this->objectService->method('saveObject')->willThrowException(new RuntimeException('schema missing'));
		$this->logger->expects($this->once())->method('error')->with(
			'[TalkRoomBinding] Could not open a conversation for the room',
			$this->callback(fn (array $context): bool => $context['roomToken'] === 'tok' && $context['error'] === 'schema missing')
		);

		$this->assertNull($this->binding->createBound('tok', 'agent-1', 'alice'));

	}//end testAFailedCreateIsLoggedAsAnErrorAndYieldsNull()
}//end class

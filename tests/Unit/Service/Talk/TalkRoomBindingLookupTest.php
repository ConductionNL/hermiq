<?php

declare(strict_types=1);

/**
 * TalkRoomBinding room lookup edges: an empty token never queries, rows that
 * are not objects are skipped, and a failing lookup is logged and resolves
 * nothing.
 *
 * The roster sync and the newest-session lookup are in TalkRoomBindingSyncTest;
 * the binding writes are in TalkRoomBindingTest.
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
class TalkRoomBindingLookupTest extends TestCase {

	private ObjectService&MockObject $objectService;

	private LoggerInterface&MockObject $logger;

	private TalkRoomBinding $binding;

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
}//end class

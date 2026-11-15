<?php

/**
 * Hermiq TalkApprovalBinding unit tests.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\Talk
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

namespace OCA\Hermiq\Tests\Unit\Service\Talk;

use OCA\Hermiq\Service\Talk\TalkApprovalBinding;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Binding an approval to the Talk message that carries it.
 *
 * @covers \OCA\Hermiq\Service\Talk\TalkApprovalBinding
 */
final class TalkApprovalBindingTest extends TestCase {

	/**
	 * @var ObjectService&MockObject
	 */
	private ObjectService $objectService;

	/**
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * @var TalkApprovalBinding
	 */
	private TalkApprovalBinding $binding;

	/**
	 * Build the binding over mocked boundaries.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objectService = $this->createMock(ObjectService::class);
		$this->logger        = $this->createMock(LoggerInterface::class);
		$this->binding       = new TalkApprovalBinding(objectService: $this->objectService, logger: $this->logger);
	}//end setUp()

	/**
	 * An approval entity with the given uuid and data.
	 *
	 * @param string $uuid The uuid.
	 * @param array<string, mixed> $data The object data.
	 *
	 * @return ObjectEntity The entity.
	 */
	private function approval(string $uuid, array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setObject($data);

		return $entity;
	}//end approval()

	/**
	 * Binding saves every existing field plus the room token and message id.
	 *
	 * @return void
	 */
	public function testBindCarriesEveryFieldForwardAndAddsTheMessage(): void {
		$captured = null;
		$this->objectService->expects(self::once())
			->method('saveObject')
			->willReturnCallback(
				static function (...$args) use (&$captured) {
					$captured = $args;
					return new ObjectEntity();
				}
			);
		$this->logger->expects(self::never())->method('warning');

		$result = $this->binding->bind(
			approval: $this->approval('a-1', ['status' => 'pending', 'tool' => 'dossiq.case.update']),
			roomToken: 'room1',
			messageId: '42'
		);

		self::assertTrue($result);
		self::assertSame(
			['id' => 'a-1', 'status' => 'pending', 'tool' => 'dossiq.case.update', 'talkRoomToken' => 'room1', 'talkMessageId' => '42'],
			$captured[0]
		);
		self::assertSame('hermiq', $captured[2]);
		self::assertSame('approval', $captured[3]);
		self::assertSame('a-1', $captured[4]);
	}//end testBindCarriesEveryFieldForwardAndAddsTheMessage()

	/**
	 * Incomplete inputs are refused with a warning and nothing is saved.
	 *
	 * @return void
	 */
	public function testBindRefusesIncompleteInputs(): void {
		$this->objectService->expects(self::never())->method('saveObject');
		$this->logger->expects(self::exactly(3))->method('warning');

		self::assertFalse($this->binding->bind(approval: $this->approval('', ['a' => 1]), roomToken: 'room1', messageId: '42'));
		self::assertFalse($this->binding->bind(approval: $this->approval('a-1', []), roomToken: '', messageId: '42'));
		self::assertFalse($this->binding->bind(approval: $this->approval('a-1', []), roomToken: 'room1', messageId: ''));
	}//end testBindRefusesIncompleteInputs()

	/**
	 * A failing save is reported as false and logged, not thrown.
	 *
	 * @return void
	 */
	public function testBindReportsAFailedSaveAsFalse(): void {
		$this->objectService->method('saveObject')->willThrowException(new RuntimeException('db down'));
		$this->logger->expects(self::once())
			->method('warning')
			->with(
				self::stringContains('Could not bind the approval'),
				self::callback(static fn (array $ctx): bool => $ctx['approval'] === 'a-1' && $ctx['error'] === 'db down')
			);

		self::assertFalse($this->binding->bind(approval: $this->approval('a-1', []), roomToken: 'room1', messageId: '42'));
	}//end testBindReportsAFailedSaveAsFalse()

	/**
	 * The newest approval carrying the message id is found.
	 *
	 * @return void
	 */
	public function testFindByMessageIdReturnsTheFirstEntityMatch(): void {
		$match = $this->approval('a-2', []);
		$this->objectService->expects(self::once())->method('setRegister')->with('hermiq')->willReturnSelf();
		$this->objectService->expects(self::once())->method('setSchema')->with('approval')->willReturnSelf();
		$this->objectService->expects(self::once())
			->method('findAll')
			->with(['filters' => ['talkMessageId' => '42'], 'sort' => ['created' => 'DESC'], 'limit' => 1])
			->willReturn(['not-an-entity', $match]);

		self::assertSame($match, $this->binding->findByMessageId('42'));
	}//end testFindByMessageIdReturnsTheFirstEntityMatch()

	/**
	 * No match, an empty id or a failing lookup all give null.
	 *
	 * @return void
	 */
	public function testFindByMessageIdReturnsNullWhenNothingResolves(): void {
		self::assertNull($this->binding->findByMessageId(''));

		$this->objectService->method('setRegister')->willReturnSelf();
		$this->objectService->method('setSchema')->willReturnSelf();
		$this->objectService->method('findAll')->willReturnOnConsecutiveCalls([], self::throwException(new RuntimeException('boom')));
		$this->logger->expects(self::once())->method('warning')->with(self::stringContains('Could not resolve the approval'));

		self::assertNull($this->binding->findByMessageId('42'));
		self::assertNull($this->binding->findByMessageId('43'));
	}//end testFindByMessageIdReturnsNullWhenNothingResolves()

	/**
	 * Provenance is written onto the stored approval, keeping its fields.
	 *
	 * @return void
	 */
	public function testRecordDecidedViaSavesTheProvenance(): void {
		$this->objectService->expects(self::once())
			->method('find')
			->willReturnCallback(
				function (...$args) {
					self::assertSame('a-3', $args[0]);
					self::assertSame('hermiq', $args[3]);
					self::assertSame('approval', $args[4]);
					return $this->approval('a-3', ['status' => 'approved']);
				}
			);
		$this->objectService->expects(self::once())
			->method('saveObject')
			->willReturnCallback(
				static function (...$args) {
					self::assertSame(['id' => 'a-3', 'status' => 'approved', 'decidedVia' => 'talk-reaction'], $args[0]);
					self::assertSame('a-3', $args[4]);
					return new ObjectEntity();
				}
			);

		$this->logger->expects(self::never())->method('warning');

		$this->binding->recordDecidedVia(approvalUuid: 'a-3', via: 'talk-reaction');
	}//end testRecordDecidedViaSavesTheProvenance()

	/**
	 * A missing approval saves nothing; a failing lookup is logged, never thrown.
	 *
	 * @return void
	 */
	public function testRecordDecidedViaNeverFails(): void {
		$this->objectService->method('find')
			->willReturnOnConsecutiveCalls(null, self::throwException(new RuntimeException('gone')));
		$this->objectService->expects(self::never())->method('saveObject');
		$this->logger->expects(self::once())->method('warning')->with(self::stringContains('Could not record decision provenance'));

		$this->binding->recordDecidedVia(approvalUuid: 'a-4', via: 'ui');
		$this->binding->recordDecidedVia(approvalUuid: 'a-5', via: 'ui');
	}//end testRecordDecidedViaNeverFails()
}//end class

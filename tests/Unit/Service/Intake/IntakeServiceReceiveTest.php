<?php

/**
 * Hermiq IntakeService receive unit tests: which stored conversation a message joins.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\Intake
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

namespace OCA\Hermiq\Tests\Unit\Service\Intake;

use DateTimeImmutable;
use OCA\Hermiq\Service\Intake\IntakeService;
use OCA\Hermiq\Service\Intake\IntakeSettings;
use OCA\Hermiq\Service\Intake\IntakeToolGrant;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for receive(): joining the open conversation of the same person and subject.
 *
 * @covers \OCA\Hermiq\Service\Intake\IntakeService
 * @uses   \OCA\Hermiq\Service\Intake\IntakeMessage
 */
class IntakeServiceReceiveTest extends TestCase {

	/**
	 * The fixed clock.
	 *
	 * @var string
	 */
	private const NOW = '2026-05-01T10:00:00+00:00';

	/**
	 * The object service double.
	 *
	 * @var ObjectService&MockObject
	 */
	private ObjectService&MockObject $objects;

	/**
	 * The logger double.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Saved payloads as [data, uuid].
	 *
	 * @var list<array{0: array<string, mixed>, 1: ?string}>
	 */
	private array $saved = [];

	/**
	 * The findAll configs the service passed.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $queries = [];

	/**
	 * Wire the doubles; findAll answers with $stored, or throws when $stored is an exception.
	 *
	 * @param array<int, mixed>|RuntimeException $stored What the conversation lookup returns.
	 *
	 * @return IntakeService The service.
	 */
	private function service(array|RuntimeException $stored): IntakeService {
		$this->saved = [];
		$this->queries = [];
		$this->objects = $this->createMock(ObjectService::class);
		$this->objects->method('setRegister')->willReturnSelf();
		$this->objects->method('setSchema')->willReturnSelf();
		$this->objects->method('findAll')->willReturnCallback(
			function (array $config = []) use ($stored): array {
				$this->queries[] = $config;
				if ($stored instanceof RuntimeException) {
					throw $stored;
				}

				return $stored;
			}
		);
		$this->objects->method('saveObject')->willReturnCallback(
			function (array|ObjectEntity $object, ?array $extend = [], mixed $register = null, mixed $schema = null, ?string $uuid = null): ObjectEntity {
				$this->saved[] = [(array)$object, $uuid];
				return $this->entity(($uuid ?? 'new-1'), (array)$object);
			}
		);

		$this->logger = $this->createMock(LoggerInterface::class);

		$settings = $this->createMock(IntakeSettings::class);
		$settings->method('abstentionThreshold')->willReturn(0.8);

		return new IntakeService(
			objectService: $this->objects,
			grant: $this->createMock(IntakeToolGrant::class),
			settings: $settings,
			auditTrailMapper: $this->createMock(AuditTrailMapper::class),
			logger: $this->logger
		);
	}//end service()

	/**
	 * A stored conversation.
	 *
	 * @param string               $uuid The uuid.
	 * @param array<string, mixed> $data The payload.
	 *
	 * @return ObjectEntity The entity.
	 */
	private function entity(string $uuid, array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setObject($data);
		return $entity;
	}//end entity()

	/**
	 * The message the tests receive, as it is stored.
	 *
	 * @return array<string, string> The stored message.
	 */
	private function storedMessage(): array {
		return ['channel' => 'portal', 'text' => 'nog een vraag', 'at' => '2026-05-01T10:00:00+00:00'];
	}//end storedMessage()

	/**
	 * A failed lookup is logged and the message opens a new conversation.
	 *
	 * @return void
	 */
	public function testAnUnreadableLookupOpensANewConversation(): void {
		$service = $this->service(new RuntimeException('register down'));
		$this->logger->expects($this->once())
			->method('warning')
			->with('Hermiq could not read the intake conversations: register down', $this->callback(
				static fn (array $context): bool => ($context['exception'] ?? null) instanceof RuntimeException
			));

		$result = $service->receive('p', 's', 'portal', 'nog een vraag', new DateTimeImmutable(self::NOW));

		$this->assertCount(1, $this->saved);
		$this->assertNull($this->saved[0][1]);
		$this->assertSame(
			['person' => 'p', 'subject' => 's', 'state' => 'open', 'messages' => [$this->storedMessage()]],
			$this->saved[0][0]
		);
		$this->assertSame('new-1', $result['id']);
		$this->assertSame('open', $result['state']);
		$this->assertFalse($result['terminal']);
	}//end testAnUnreadableLookupOpensANewConversation()

	/**
	 * The lookup filters on the person, and rows that are not the person's open
	 * conversation about this subject are passed over.
	 *
	 * @return void
	 */
	public function testOnlyTheOpenConversationAboutTheSubjectIsJoined(): void {
		$service = $this->service(
			[
				['person' => 'p', 'subject' => 's', 'state' => 'open'],
				$this->entity('closed', ['person' => 'p', 'subject' => 's', 'state' => 'filed', 'messages' => []]),
				$this->entity('other-subject', ['person' => 'p', 'subject' => 'x', 'state' => 'open', 'messages' => []]),
				$this->entity('other-person', ['person' => 'q', 'subject' => 's', 'state' => 'open', 'messages' => []]),
			]
		);

		$service->receive('p', 's', 'portal', 'nog een vraag', new DateTimeImmutable(self::NOW));

		$this->assertSame([['filters' => ['person' => 'p'], 'limit' => 100]], $this->queries);
		$this->assertCount(1, $this->saved);
		$this->assertNull($this->saved[0][1]);
		$this->assertSame([$this->storedMessage()], $this->saved[0][0]['messages']);
	}//end testOnlyTheOpenConversationAboutTheSubjectIsJoined()

	/**
	 * A stored conversation without a state counts as open and is joined.
	 *
	 * @return void
	 */
	public function testAConversationWithoutAStateIsJoined(): void {
		$earlier = ['channel' => 'email', 'text' => 'hallo', 'at' => '2026-04-30T09:00:00+00:00'];
		$service = $this->service(
			[$this->entity('c-1', ['person' => 'p', 'subject' => 's', 'messages' => [$earlier]])]
		);

		$result = $service->receive('p', 's', 'portal', 'nog een vraag', new DateTimeImmutable(self::NOW));

		$this->assertSame('c-1', $this->saved[0][1]);
		$this->assertSame([$earlier, $this->storedMessage()], $this->saved[0][0]['messages']);
		$this->assertSame('c-1', $result['id']);
		$this->assertSame('open', $result['state']);
	}//end testAConversationWithoutAStateIsJoined()

	/**
	 * Stored messages that are not a list are replaced by the received message alone.
	 *
	 * @return void
	 */
	public function testCorruptStoredMessagesStartOver(): void {
		$service = $this->service(
			[$this->entity('c-1', ['person' => 'p', 'subject' => 's', 'state' => 'open', 'messages' => 'corrupt'])]
		);

		$service->receive('p', 's', 'portal', 'nog een vraag', new DateTimeImmutable(self::NOW));

		$this->assertSame('c-1', $this->saved[0][1]);
		$this->assertSame([$this->storedMessage()], $this->saved[0][0]['messages']);
	}//end testCorruptStoredMessagesStartOver()
}//end class

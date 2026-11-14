<?php

/**
 * Hermiq IntakeService escalation, review and conclusion unit tests.
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
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrail\ObjectAuditEvent;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for the conversation paths after intake: escalation scoring, reviews and the ending.
 *
 * @covers \OCA\Hermiq\Service\Intake\IntakeService
 * @uses   \OCA\Hermiq\Service\Intake\IntakeConclusion
 * @uses   \OCA\Hermiq\Service\Intake\IntakeReview
 */
class IntakeServiceFlowTest extends TestCase {

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
	 * The tool grant double.
	 *
	 * @var IntakeToolGrant&MockObject
	 */
	private IntakeToolGrant&MockObject $grant;

	/**
	 * The audit mapper double.
	 *
	 * @var AuditTrailMapper&MockObject
	 */
	private AuditTrailMapper&MockObject $audit;

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
	 * Recorded audit events.
	 *
	 * @var list<ObjectAuditEvent>
	 */
	private array $events = [];

	/**
	 * Wire the doubles over one stored conversation `c-1`.
	 *
	 * @param array<string, mixed> $stored The stored conversation payload.
	 *
	 * @return IntakeService The service.
	 */
	private function service(array $stored = ['person' => 'p', 'subject' => 's', 'messages' => [['text' => 'hallo']]]): IntakeService {
		$this->saved = [];
		$this->events = [];
		$this->objects = $this->createMock(ObjectService::class);
		$this->objects->method('find')->willReturnCallback(
			function (int|string $id) use ($stored): ?ObjectEntity {
				if ($id === 'boom') {
					throw new RuntimeException('down');
				}

				return ($id === 'c-1' ? $this->entity('c-1', $stored) : null);
			}
		);
		$this->objects->method('saveObject')->willReturnCallback(
			function (array|ObjectEntity $object, ?array $extend = [], mixed $register = null, mixed $schema = null, ?string $uuid = null): ObjectEntity {
				$this->saved[] = [(array)$object, $uuid];
				return $this->entity((string)$uuid, (array)$object);
			}
		);

		$this->grant = $this->createMock(IntakeToolGrant::class);
		$this->audit = $this->createMock(AuditTrailMapper::class);
		$this->audit->method('recordObjectEvent')->willReturnCallback(
			function (ObjectAuditEvent $event): AuditTrail {
				$this->events[] = $event;
				return new AuditTrail();
			}
		);
		$this->logger = $this->createMock(LoggerInterface::class);

		$settings = $this->createMock(IntakeSettings::class);
		$settings->method('abstentionThreshold')->willReturn(0.8);

		return new IntakeService(
			objectService: $this->objects,
			grant: $this->grant,
			settings: $settings,
			auditTrailMapper: $this->audit,
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
	 * The fixed clock.
	 *
	 * @return DateTimeImmutable The time.
	 */
	private function now(): DateTimeImmutable {
		return new DateTimeImmutable(self::NOW);
	}//end now()

	/**
	 * Unknown, empty and unreadable conversations yield null and save nothing.
	 *
	 * @return void
	 */
	public function testUnknownConversationsYieldNull(): void {
		$service = $this->service();
		$this->grant->expects($this->never())->method('call');

		$this->assertNull($service->conversation(''));
		$this->assertNull($service->conversation('boom'));
		$this->assertNull($service->scoreEscalation('nope', ['level' => 'high']));
		$this->assertNull($service->review('nope', 'tool', []));
		$this->assertNull($service->conclude('nope', [], [], 'tool'));
		$this->assertSame([], $this->saved);
	}//end testUnknownConversationsYieldNull()

	/**
	 * conversation() shapes the stored record with its id, open state and terminal flag.
	 *
	 * @return void
	 */
	public function testConversationIsShaped(): void {
		$shaped = $this->service()->conversation('c-1');

		$this->assertSame('c-1', $shaped['id']);
		$this->assertSame('open', $shaped['state']);
		$this->assertFalse($shaped['terminal']);
	}//end testConversationIsShaped()

	/**
	 * A deterministic signal wins over a model signal, and is labelled as scored by rule.
	 *
	 * @return void
	 */
	public function testTheDeterministicSignalWins(): void {
		$service = $this->service();

		$service->scoreEscalation('c-1', ['level' => 'urgent', 'reason' => 'keyword'], ['level' => 'low', 'reason' => 'model']);
		$service->scoreEscalation('c-1', null, ['level' => 'low']);

		$this->assertSame(
			['level' => 'urgent', 'reason' => 'keyword', 'decidedBy' => 'deterministic', 'label' => 'Scored by rule'],
			$this->saved[0][0]['escalation']
		);
		$this->assertSame('c-1', $this->saved[0][1]);
		$this->assertSame(
			['level' => 'low', 'reason' => '', 'decidedBy' => 'model', 'label' => 'Scored by a model'],
			$this->saved[1][0]['escalation']
		);
	}//end testTheDeterministicSignalWins()

	/**
	 * Without any signal the conversation is returned unchanged and not saved.
	 *
	 * @return void
	 */
	public function testNoSignalChangesNothing(): void {
		$shaped = $this->service()->scoreEscalation('c-1', null, null);

		$this->assertSame('c-1', $shaped['id']);
		$this->assertArrayNotHasKey('escalation', $shaped);
		$this->assertSame([], $this->saved);
	}//end testNoSignalChangesNothing()

	/**
	 * A review appends the verdict, attributed to the result's own reviewer field or else the tool.
	 *
	 * @return void
	 */
	public function testAReviewIsAppendedWithItsReviewer(): void {
		$service = $this->service(['reviews' => 'corrupt']);
		$this->grant->method('call')->willReturnOnConsecutiveCalls(
			['result' => ['reviewer' => '', 'reviewedBy' => 'Team Vergunningen', 'ok' => true], 'isError' => false],
			['result' => 'plain text', 'isError' => true]
		);

		$service->review('c-1', 'vergunning.review', ['a' => 1], $this->now());
		$service->review('c-1', 'other.review', [], $this->now());

		$this->assertSame(
			[
				[
					'tool' => 'vergunning.review',
					'reviewer' => 'Team Vergunningen',
					'verdict' => ['reviewer' => '', 'reviewedBy' => 'Team Vergunningen', 'ok' => true],
					'failed' => false,
					'at' => self::NOW,
				],
			],
			$this->saved[0][0]['reviews']
		);
		$this->assertSame('other.review', $this->saved[1][0]['reviews'][0]['reviewer']);
		$this->assertTrue($this->saved[1][0]['reviews'][0]['failed']);
	}//end testAReviewIsAppendedWithItsReviewer()

	/**
	 * A type outside the catalogue is handed over with the transcript, without calling the intake tool.
	 *
	 * @return void
	 */
	public function testATypeOutsideTheCatalogueIsHandedOver(): void {
		$service = $this->service();
		$this->grant->expects($this->never())->method('call');

		$result = $service->conclude('c-1', ['type' => 'verzonnen', 'confidence' => 0.99], ['afval', 'parkeren'], 'intake.file', $this->now());

		$this->assertSame('handover', $result['state']);
		$this->assertTrue($result['terminal']);
		$this->assertSame('the proposed request type is not in the catalogue this municipality declares', $result['handover']['reason']);
		$this->assertSame([['text' => 'hallo']], $result['handover']['transcript']);
		$this->assertSame(['type' => 'verzonnen', 'confidence' => 0.99, 'threshold' => 0.8, 'catalogue' => ['afval', 'parkeren']], $result['classification']);
		$this->assertCount(1, $this->events);
		$this->assertSame('intake', $this->events[0]->action);
		$this->assertSame('c-1', $this->events[0]->object->getUuid());
		$this->assertSame('handover', $this->events[0]->context['state']);
		$this->assertSame('conversational-intake', $this->events[0]->context['feature']);
	}//end testATypeOutsideTheCatalogueIsHandedOver()

	/**
	 * A catalogue type below the threshold is handed over as uncertain.
	 *
	 * @return void
	 */
	public function testAnUncertainClassificationIsHandedOver(): void {
		$service = $this->service();
		$this->grant->expects($this->never())->method('call');

		$result = $service->conclude('c-1', ['type' => 'afval', 'confidence' => 0.79], ['afval'], 'intake.file', $this->now());

		$this->assertSame('the classification was less certain than the threshold this instance requires', $result['handover']['reason']);
	}//end testAnUncertainClassificationIsHandedOver()

	/**
	 * A refused filing becomes a handover; the audit failure is only logged.
	 *
	 * @return void
	 */
	public function testARefusedFilingIsHandedOverEvenIfTheAuditFails(): void {
		$service = $this->service();
		$this->grant->expects($this->once())
			->method('call')
			->with('intake.file', ['type' => 'afval', 'subject' => 's', 'person' => 'p', 'messages' => [['text' => 'hallo']]])
			->willReturn(['result' => 'refused', 'isError' => true]);
		$this->audit = $this->createMock(AuditTrailMapper::class);
		$this->audit->method('recordObjectEvent')->willThrowException(new RuntimeException('audit down'));
		$service = new IntakeService(
			objectService: $this->objects,
			grant: $this->grant,
			settings: $this->createConfiguredMock(IntakeSettings::class, ['abstentionThreshold' => 0.5]),
			auditTrailMapper: $this->audit,
			logger: $this->logger
		);
		$this->logger->expects($this->once())
			->method('warning')
			->with('Hermiq could not record an intake outcome: audit down', $this->anything());

		$result = $service->conclude('c-1', ['type' => ' afval ', 'confidence' => 0.6], ['afval'], 'intake.file', $this->now());

		$this->assertSame('handover', $result['state']);
		$this->assertSame('the owning app did not create a record for this request', $result['handover']['reason']);
	}//end testARefusedFilingIsHandedOverEvenIfTheAuditFails()

	/**
	 * A confident catalogue type is filed through the intake tool and recorded.
	 *
	 * @return void
	 */
	public function testAConfidentTypeIsFiled(): void {
		$service = $this->service();
		$this->grant->method('call')->willReturn(['result' => ['id' => 'zaak-1'], 'isError' => false]);

		$result = $service->conclude('c-1', ['type' => 'afval', 'confidence' => 0.8], ['afval'], 'intake.file', $this->now());

		$this->assertSame('filed', $result['state']);
		$this->assertTrue($result['terminal']);
		$this->assertSame(['tool' => 'intake.file', 'type' => 'afval', 'record' => ['id' => 'zaak-1'], 'at' => self::NOW], $result['filed']);
		$this->assertArrayNotHasKey('handover', $result);
		$this->assertSame('filed', $this->events[0]->context['state']);
	}//end testAConfidentTypeIsFiled()
}//end class

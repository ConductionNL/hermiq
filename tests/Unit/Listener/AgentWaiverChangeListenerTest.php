<?php

/**
 * Unit tests for AgentWaiverChangeListener and GrantWaiverAudit.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/agent-capability-reach/spec.md#requirement-waiving-approval-is-recorded-as-a-distinct-audited-event
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Listener;

use OCA\Hermiq\Listener\AgentWaiverChangeListener;
use OCA\Hermiq\Service\Agent\AgentObjectMatcher;
use OCA\Hermiq\Service\Agent\GrantWaiverAudit;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Service\Capability\ToolGrantResolver;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * A waiver written through OpenRegister's generic objects API is audited.
 *
 * @covers \OCA\Hermiq\Listener\AgentWaiverChangeListener
 * @covers \OCA\Hermiq\Service\Agent\GrantWaiverAudit
 *
 * @spec openspec/specs/agent-capability-reach/spec.md#requirement-waiving-approval-is-recorded-as-a-distinct-audited-event
 */
class AgentWaiverChangeListenerTest extends TestCase {

	private const WAIVED = 'hermiq.sendMail' . ToolGrantResolver::WAIVER_FRAGMENT;

	/**
	 * Every audit entry written: action, context and the object's uuid.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $entries = [];

	private GrantWaiverAudit $audit;

	private AgentWaiverChangeListener $listener;

	private bool $auditFails = false;

	private ?string $uid = 'bob';

	private LoggerInterface&MockObject $listenerLogger;

	protected function setUp(): void {
		parent::setUp();

		$mapper = $this->createMock(AuditTrailMapper::class);
		$mapper->method('createAuditTrailEntry')->willReturnCallback(
			function (ObjectEntity $object, string $action, array $context): AuditTrail {
				if ($this->auditFails === true) {
					throw new RuntimeException('audit down');
				}

				$this->entries[] = ['uuid' => $object->getUuid(), 'action' => $action, 'context' => $context];
				return new AuditTrail();
			}
		);

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturnCallback(
			function (): ?IUser {
				if ($this->uid === null) {
					return null;
				}

				$user = $this->createMock(IUser::class);
				$user->method('getUID')->willReturn($this->uid);
				return $user;
			}
		);

		$matcher = $this->createMock(AgentObjectMatcher::class);
		$matcher->method('isAgent')->willReturnCallback(
			static fn (ObjectEntity $object): bool => ((string)$object->getSchema() === 'agent')
		);

		$this->audit = new GrantWaiverAudit($mapper, $session, $this->createMock(LoggerInterface::class));
		$this->listenerLogger = $this->createMock(LoggerInterface::class);
		$this->listener = new AgentWaiverChangeListener($this->audit, $matcher, $this->listenerLogger);

	}//end setUp()

	/**
	 * An object with the given tools.
	 *
	 * @param mixed $tools The stored tools.
	 * @param string $schema The schema the matcher reads.
	 *
	 * @return ObjectEntity
	 */
	private function object(mixed $tools, string $schema = 'agent'): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('agent-1');
		$entity->setSchema($schema);
		$entity->setObject(['name' => 'Digest', 'tools' => $tools]);
		return $entity;
	}//end object()

	/**
	 * An update through the generic API that adds a waiver writes the waiver event.
	 *
	 * @return void
	 */
	public function testAGenericUpdateAddingAWaiverIsAudited(): void {
		$this->listener->handle(new ObjectUpdatedEvent($this->object([self::WAIVED, 'files.listFiles']), $this->object(['hermiq.sendMail', 'files.listFiles'])));

		$this->assertCount(1, $this->entries);
		$this->assertSame('agent-1', $this->entries[0]['uuid']);
		$this->assertSame(GrantWaiverAudit::ACTION, $this->entries[0]['action']);
		$this->assertSame([self::WAIVED], $this->entries[0]['context']['added']);
		$this->assertSame([], $this->entries[0]['context']['removed']);
		$this->assertSame('bob', $this->entries[0]['context']['actor']);
		$this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\+00:00$/', $this->entries[0]['context']['at']);
	}//end testAGenericUpdateAddingAWaiverIsAudited()

	/**
	 * Removing a waiver is audited too.
	 *
	 * @return void
	 */
	public function testAGenericUpdateRemovingAWaiverIsAudited(): void {
		$this->listener->handle(new ObjectUpdatedEvent($this->object(['hermiq.sendMail']), $this->object([self::WAIVED])));

		$this->assertSame([[], [self::WAIVED]], [$this->entries[0]['context']['added'], $this->entries[0]['context']['removed']]);
	}//end testAGenericUpdateRemovingAWaiverIsAudited()

	/**
	 * Waivers stored in the structured shape are seen on both sides.
	 *
	 * @return void
	 */
	public function testStructuredGrantsAreRead(): void {
		$before = ['hermiq' => ['mail' => ['send' => 'hermiq.sendMail']]];
		$after = ['hermiq' => ['mail' => ['send' => self::WAIVED]]];

		$this->listener->handle(new ObjectUpdatedEvent($this->object($after), $this->object($before)));

		$this->assertSame([self::WAIVED], ($this->entries[0]['context']['added'] ?? null));
	}//end testStructuredGrantsAreRead()

	/**
	 * A created agent that already carries a waiver is audited.
	 *
	 * @return void
	 */
	public function testACreatedAgentWithAWaiverIsAudited(): void {
		$this->listener->handle(new ObjectCreatedEvent($this->object([self::WAIVED])));

		$this->assertSame([self::WAIVED], ($this->entries[0]['context']['added'] ?? null));
	}//end testACreatedAgentWithAWaiverIsAudited()

	/**
	 * An update without the previous object counts every waiver as added.
	 *
	 * @return void
	 */
	public function testAnUpdateWithoutThePreviousObjectCountsTheWaiverAsAdded(): void {
		$this->listener->handle(new ObjectUpdatedEvent($this->object([self::WAIVED])));

		$this->assertSame([self::WAIVED], ($this->entries[0]['context']['added'] ?? null));
	}//end testAnUpdateWithoutThePreviousObjectCountsTheWaiverAsAdded()

	/**
	 * THE CONTROLS: nothing is written when no waiver moved, for another
	 * schema's object, or for another event.
	 *
	 * @return void
	 */
	public function testNothingIsWrittenWhenNoWaiverMovedOrTheObjectIsNotAnAgent(): void {
		$this->listenerLogger->expects($this->never())->method('warning');

		$this->listener->handle(new ObjectUpdatedEvent($this->object([self::WAIVED, 'a.b']), $this->object([self::WAIVED])));
		$this->listener->handle(new ObjectUpdatedEvent($this->object(['hermiq.sendMail', 'x.y']), $this->object(['hermiq.sendMail'])));
		$this->listener->handle(new ObjectUpdatedEvent($this->object([self::WAIVED], 'conversation'), $this->object([], 'conversation')));
		$this->listener->handle(new ObjectDeletedEvent($this->object([self::WAIVED])));
		$this->listener->handle(new ObjectCreatedEvent($this->object('not a list')));

		$this->assertSame([], $this->entries);

		$this->listener->handle(new ObjectUpdatedEvent($this->object([self::WAIVED]), $this->object([])));
		$this->assertCount(1, $this->entries);
	}//end testNothingIsWrittenWhenNoWaiverMovedOrTheObjectIsNotAnAgent()

	/**
	 * Without a signed-in user (cron, occ) the actor is the system.
	 *
	 * @return void
	 */
	public function testAWriteWithoutASessionIsRecordedAsTheSystem(): void {
		$this->uid = null;

		$this->listener->handle(new ObjectUpdatedEvent($this->object([self::WAIVED]), $this->object([])));

		$this->assertSame(GrantWaiverAudit::SYSTEM_ACTOR, $this->entries[0]['context']['actor']);
	}//end testAWriteWithoutASessionIsRecordedAsTheSystem()

	/**
	 * A failing audit write does not fail the object write.
	 *
	 * @return void
	 */
	public function testAFailingAuditWriteDoesNotThrow(): void {
		$this->auditFails = true;

		$this->listener->handle(new ObjectUpdatedEvent($this->object([self::WAIVED]), $this->object([])));

		$this->assertSame([], $this->entries);
	}//end testAFailingAuditWriteDoesNotThrow()

	/**
	 * The service itself never throws on a failing audit write, so the
	 * tool-grants endpoint does not turn a saved grant change into an error.
	 *
	 * @return void
	 */
	public function testTheServiceSwallowsAFailingAuditWrite(): void {
		$this->auditFails = true;

		$this->audit->record($this->object([self::WAIVED]), []);

		$this->assertSame([], $this->entries);
	}//end testTheServiceSwallowsAFailingAuditWrite()
}//end class

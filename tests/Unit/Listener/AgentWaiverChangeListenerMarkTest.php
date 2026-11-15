<?php

/**
 * The leave-draft mark of AgentWaiverChangeListener, with the audit and the
 * agent matcher as test doubles: a draft update that leaves draft is audited
 * and marks the agent, the create OpenRegister announces next is not audited
 * a second time, and an event that carries no written agent is ignored.
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

use OCA\Hermiq\Kernel\Contracts\AgentObjectMatcherInterface;
use OCA\Hermiq\Kernel\Contracts\GrantWaiverAuditInterface;
use OCA\Hermiq\Listener\AgentWaiverChangeListener;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\DraftUpdatedEvent;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the leave-draft mark of AgentWaiverChangeListener.
 *
 * @covers \OCA\Hermiq\Listener\AgentWaiverChangeListener
 */
class AgentWaiverChangeListenerMarkTest extends TestCase {

	/**
	 * The uuids audited, in order.
	 *
	 * @var list<string>
	 */
	private array $audited = [];

	/**
	 * A listener whose matcher treats every object as an agent.
	 *
	 * @return AgentWaiverChangeListener
	 */
	private function listener(): AgentWaiverChangeListener {
		$audit = $this->createMock(GrantWaiverAuditInterface::class);
		$audit->method('waivers')->willReturn([]);
		$audit->method('record')->willReturnCallback(
			function (ObjectEntity $agent, array $before): void {
				$this->audited[] = (string)$agent->getUuid();
			}
		);
		$agents = $this->createMock(AgentObjectMatcherInterface::class);
		$agents->method('isAgent')->willReturn(true);

		return new AgentWaiverChangeListener($audit, $agents, $this->createMock(LoggerInterface::class));
	}//end listener()

	/**
	 * An agent object.
	 *
	 * @param string $uuid The uuid.
	 * @param string|null $status The status; draft when 'draft'.
	 *
	 * @return ObjectEntity
	 */
	private static function agent(string $uuid, ?string $status = null): ObjectEntity {
		$agent = new ObjectEntity();
		$agent->setUuid($uuid);
		$agent->setStatus($status);
		$agent->setObject(['tools' => []]);
		return $agent;
	}//end agent()

	/**
	 * Leaving draft is audited once; the create announced right after it is
	 * skipped, and only that one create.
	 *
	 * @return void
	 */
	public function testTheCreateAnnouncedAfterLeavingDraftIsAuditedOnlyOnce(): void {
		$listener = $this->listener();

		$listener->handle(new DraftUpdatedEvent(self::agent('agent-1'), self::agent('agent-1', ObjectEntity::STATUS_DRAFT)));
		$listener->handle(new ObjectCreatedEvent(self::agent('agent-1')));
		$listener->handle(new ObjectCreatedEvent(self::agent('agent-1')));

		$this->assertSame(['agent-1', 'agent-1'], $this->audited);
	}//end testTheCreateAnnouncedAfterLeavingDraftIsAuditedOnlyOnce()

	/**
	 * The mark is per agent: another agent's create is still audited.
	 *
	 * @return void
	 */
	public function testTheMarkIsPerAgent(): void {
		$listener = $this->listener();

		$listener->handle(new DraftUpdatedEvent(self::agent('agent-1'), self::agent('agent-1', ObjectEntity::STATUS_DRAFT)));
		$listener->handle(new ObjectCreatedEvent(self::agent('agent-2')));

		$this->assertSame(['agent-1', 'agent-2'], $this->audited);
	}//end testTheMarkIsPerAgent()

	/**
	 * A delete carries no written agent and is not audited.
	 *
	 * @return void
	 */
	public function testADeleteIsNotAudited(): void {
		$this->listener()->handle(new ObjectDeletedEvent(self::agent('agent-1')));

		$this->assertSame([], $this->audited);
	}//end testADeleteIsNotAudited()

	/**
	 * A failing audit write is logged with its cause and does not reach the writer.
	 *
	 * @return void
	 */
	public function testAFailingAuditWriteIsLoggedNotThrown(): void {
		$audit = $this->createMock(GrantWaiverAuditInterface::class);
		$audit->method('waivers')->willReturn([]);
		$audit->method('record')->willThrowException(new \RuntimeException('audit table locked'));
		$agents = $this->createMock(AgentObjectMatcherInterface::class);
		$agents->method('isAgent')->willReturn(true);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with('[AgentWaiverChangeListener] agent write not audited: audit table locked');

		(new AgentWaiverChangeListener($audit, $agents, $logger))->handle(new ObjectCreatedEvent(self::agent('agent-1')));
	}//end testAFailingAuditWriteIsLoggedNotThrown()
}//end class

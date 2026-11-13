<?php

/**
 * The agent object listeners are registered on every event that carries an agent write.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\AppInfo
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

namespace OCA\Hermiq\Tests\Unit\AppInfo;

use OCA\Hermiq\Listener\AgentBotLifecycleListener;
use OCA\Hermiq\Listener\AgentWaiverChangeListener;
use OCA\Hermiq\Listener\ListenerRegistrar;
use OCA\OpenRegister\Event\DraftCreatedEvent;
use OCA\OpenRegister\Event\DraftUpdatedEvent;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use PHPUnit\Framework\TestCase;

/**
 * Nextcloud delivers an event only to the listeners registered on its own
 * class, so the draft events need their own registration.
 *
 * @covers \OCA\Hermiq\Listener\ListenerRegistrar
 */
class AgentObjectListenersTest extends TestCase {

	/**
	 * The waiver audit listens to the draft events as well as the object events.
	 *
	 * @return void
	 */
	public function testTheWaiverAuditListensToDraftAndObjectWrites(): void {
		$registered = [];
		$context = $this->createMock(IRegistrationContext::class);
		$context->method('registerEventListener')->willReturnCallback(
			static function (string $event, string $listener) use (&$registered): void {
				$registered[$listener][] = $event;
			}
		);

		$application = new ListenerRegistrar();
		$register = new \ReflectionMethod(ListenerRegistrar::class, 'registerAgentObjectListeners');
		$register->invoke($application, $context);

		$this->assertSame(
			[ObjectCreatedEvent::class, ObjectUpdatedEvent::class, DraftCreatedEvent::class, DraftUpdatedEvent::class],
			$registered[AgentWaiverChangeListener::class]
		);
		$this->assertSame(
			[ObjectCreatedEvent::class, ObjectUpdatedEvent::class, ObjectDeletedEvent::class],
			$registered[AgentBotLifecycleListener::class],
			'A draft agent gets no Talk bot until it leaves draft.'
		);
	}//end testTheWaiverAuditListensToDraftAndObjectWrites()
}//end class

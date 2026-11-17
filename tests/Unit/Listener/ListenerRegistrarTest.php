<?php

/**
 * Every listener hermiq registers, in the order it registers them, and the
 * two init scripts it loads on every page.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Listener;

use OCA\Hermiq\Event\AiOversightRecordedEvent;
use OCA\Hermiq\Flow\HermiqFlowNodeListener;
use OCA\Hermiq\Listener\AgentActingUserGuardListener;
use OCA\Hermiq\Listener\AgentDepartmentGuardListener;
use OCA\Hermiq\Listener\AgentBotLifecycleListener;
use OCA\Hermiq\Listener\AgentRunRequestedListener;
use OCA\Hermiq\Listener\AgentWaiverChangeListener;
use OCA\Hermiq\Listener\AiOversightRecordedListener;
use OCA\Hermiq\Listener\FlowOversightRegistrationListener;
use OCA\Hermiq\Listener\FlowRunTerminalListener;
use OCA\Hermiq\Listener\ListenerRegistrar;
use OCA\Hermiq\Listener\RegisterAgentLeafListener;
use OCA\Hermiq\Listener\ShareableConfigTypeListener;
use OCA\Hermiq\Listener\TalkApprovalReactionListener;
use OCA\Hermiq\Listener\TalkBotInvokeListener;
use OCA\Hermiq\Listener\TaskTerminalListener;
use OCA\Hermiq\Listener\UserLifecycleListener;
use OCA\OpenRegister\Event\AgentRunRequestedEvent;
use OCA\OpenRegister\Event\DraftCreatedEvent;
use OCA\OpenRegister\Event\DraftUpdatedEvent;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\OpenRegister\Event\RegisterLeafProvidersEvent;
use OCA\OpenRegister\Service\Config\RegisterShareableConfigTypesEvent;
use OCA\OpenRegister\Service\Flow\RegisterFlowNodesEvent;
use OCA\OpenRegister\Service\Flow\RegisterFlowOversightEvent;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\User\Events\UserChangedEvent;
use OCP\User\Events\UserDeletedEvent;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ListenerRegistrar::register().
 *
 * The unit bootstrap loads every OpenRegister event class the registrar guards
 * on, so the guarded registrations are all expected here.
 *
 * @covers \OCA\Hermiq\Listener\ListenerRegistrar
 */
class ListenerRegistrarTest extends TestCase {

	/**
	 * Every event/listener pair, in registration order, and the init scripts.
	 *
	 * @return void
	 */
	public function testRegisterWiresEveryListenerInOrderAndLoadsBothInitScripts(): void {
		$registered = [];
		$context = $this->createMock(IRegistrationContext::class);
		$context->method('registerEventListener')->willReturnCallback(
			static function (string $event, string $listener) use (&$registered): void {
				$registered[] = [$event, $listener];
			}
		);
		$scripts = [];

		(new ListenerRegistrar())->register(
			$context,
			static function (string $suffix) use (&$scripts): void {
				$scripts[] = $suffix;
			}
		);

		$this->assertSame(
			[
				[AgentRunRequestedEvent::class, AgentRunRequestedListener::class],
				[AiOversightRecordedEvent::class, AiOversightRecordedListener::class],
				[ObjectCreatedEvent::class, AgentBotLifecycleListener::class],
				[ObjectUpdatedEvent::class, AgentBotLifecycleListener::class],
				[ObjectDeletedEvent::class, AgentBotLifecycleListener::class],
				[ObjectCreatedEvent::class, AgentWaiverChangeListener::class],
				[ObjectUpdatedEvent::class, AgentWaiverChangeListener::class],
				[DraftCreatedEvent::class, AgentWaiverChangeListener::class],
				[DraftUpdatedEvent::class, AgentWaiverChangeListener::class],
				[ObjectCreatingEvent::class, AgentActingUserGuardListener::class],
				[ObjectUpdatingEvent::class, AgentActingUserGuardListener::class],
				[ObjectCreatingEvent::class, AgentDepartmentGuardListener::class],
				[ObjectUpdatingEvent::class, AgentDepartmentGuardListener::class],
				[RegisterFlowNodesEvent::class, HermiqFlowNodeListener::class],
				[RegisterFlowOversightEvent::class, FlowOversightRegistrationListener::class],
				[RegisterLeafProvidersEvent::class, RegisterAgentLeafListener::class],
				[RegisterShareableConfigTypesEvent::class, ShareableConfigTypeListener::class],
				[UserDeletedEvent::class, UserLifecycleListener::class],
				[UserChangedEvent::class, UserLifecycleListener::class],
				['OCA\\Talk\\Events\\BotInvokeEvent', TalkBotInvokeListener::class],
				['OCA\\OpenRegister\\Event\\TaskTerminalEvent', TaskTerminalListener::class],
				['OCA\\OpenRegister\\Event\\FlowRunTerminalEvent', FlowRunTerminalListener::class],
				['OCA\\Talk\\Events\\BotInvokeEvent', TalkApprovalReactionListener::class],
			],
			$registered
		);
		$this->assertSame(['-agent-leaf', '-companion'], $scripts, 'The leaf bundle loads with its leaf; the companion loads on every page.');
	}//end testRegisterWiresEveryListenerInOrderAndLoadsBothInitScripts()
}//end class

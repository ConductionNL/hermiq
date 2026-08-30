<?php

/**
 * Unit tests for UserLifecycleListener (agent-lifecycle-governance).
 *
 * Exercises the listener's own event-shape resolution (which events/shapes it reacts
 * to) and its "never throw" contract; the actual pause mechanic is
 * ScheduleService::pauseForUser()'s own responsibility and is tested separately.
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
 * @spec openspec/changes/agent-lifecycle-governance/tasks.md#task-3-userlifecyclelistener-offboarding-on-nc-user-deletedisable
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Listener;

use OCA\Hermiq\Listener\UserLifecycleListener;
use OCA\Hermiq\Service\ScheduleService;
use OCP\EventDispatcher\Event;
use OCP\IUser;
use OCP\User\Events\UserChangedEvent;
use OCP\User\Events\UserDeletedEvent;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for UserLifecycleListener.
 *
 * @spec openspec/changes/agent-lifecycle-governance/tasks.md#task-3-userlifecyclelistener-offboarding-on-nc-user-deletedisable
 */
class UserLifecycleListenerTest extends TestCase {
	/**
	 * An IUser with the given uid.
	 *
	 * @param string $uid The uid.
	 *
	 * @return IUser
	 */
	private function user(string $uid): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		return $user;
	}//end user()

	/**
	 * An unrelated event is ignored entirely — no pause is triggered.
	 *
	 * @return void
	 */
	public function testIgnoresUnrelatedEvent(): void {
		$scheduleService = $this->createMock(ScheduleService::class);
		$scheduleService->expects($this->never())->method('pauseForUser');

		$listener = new UserLifecycleListener($scheduleService, $this->createMock(LoggerInterface::class));
		$listener->handle($this->createMock(Event::class));

		$this->addToAssertionCount(1);

	}//end testIgnoresUnrelatedEvent()

	/**
	 * UserDeletedEvent triggers pauseForUser() with the deleted user's uid.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agent-lifecycle-governance/specs/agent-lifecycle-governance/spec.md#requirement-automatic-offboarding-pause-on-nextcloud-user-deletion-or-disable
	 */
	public function testUserDeletedEventPausesForThatUser(): void {
		$scheduleService = $this->createMock(ScheduleService::class);
		$scheduleService->expects($this->once())
			->method('pauseForUser')
			->with('deleted.user')
			->willReturn(2);

		$listener = new UserLifecycleListener($scheduleService, $this->createMock(LoggerInterface::class));
		$listener->handle(new UserDeletedEvent($this->user('deleted.user')));

	}//end testUserDeletedEventPausesForThatUser()

	/**
	 * A UserChangedEvent with feature='enabled'/value=false (a user being
	 * DISABLED — this NC version has no dedicated DisableUserEvent) triggers
	 * pauseForUser() with that user's uid.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agent-lifecycle-governance/specs/agent-lifecycle-governance/spec.md#requirement-automatic-offboarding-pause-on-nextcloud-user-deletion-or-disable
	 */
	public function testUserDisabledEventPausesForThatUser(): void {
		$scheduleService = $this->createMock(ScheduleService::class);
		$scheduleService->expects($this->once())
			->method('pauseForUser')
			->with('disabled.user')
			->willReturn(1);

		$listener = new UserLifecycleListener($scheduleService, $this->createMock(LoggerInterface::class));
		$listener->handle(new UserChangedEvent($this->user('disabled.user'), 'enabled', false, true));

	}//end testUserDisabledEventPausesForThatUser()

	/**
	 * A UserChangedEvent that RE-ENABLES a user (value=true) is not an
	 * offboarding event and must not trigger a pause.
	 *
	 * @return void
	 */
	public function testUserReEnabledEventIsIgnored(): void {
		$scheduleService = $this->createMock(ScheduleService::class);
		$scheduleService->expects($this->never())->method('pauseForUser');

		$listener = new UserLifecycleListener($scheduleService, $this->createMock(LoggerInterface::class));
		$listener->handle(new UserChangedEvent($this->user('re-enabled.user'), 'enabled', true, false));

	}//end testUserReEnabledEventIsIgnored()

	/**
	 * A UserChangedEvent for an unrelated feature (e.g. displayName) is ignored.
	 *
	 * @return void
	 */
	public function testUserChangedEventForUnrelatedFeatureIsIgnored(): void {
		$scheduleService = $this->createMock(ScheduleService::class);
		$scheduleService->expects($this->never())->method('pauseForUser');

		$listener = new UserLifecycleListener($scheduleService, $this->createMock(LoggerInterface::class));
		$listener->handle(new UserChangedEvent($this->user('someone'), 'displayName', 'New Name', 'Old Name'));

	}//end testUserChangedEventForUnrelatedFeatureIsIgnored()

	/**
	 * A pauseForUser() failure is caught and logged — the listener MUST NOT
	 * throw into Nextcloud's own user-deletion/disable dispatch.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agent-lifecycle-governance/specs/agent-lifecycle-governance/spec.md#requirement-automatic-offboarding-pause-on-nextcloud-user-deletion-or-disable
	 */
	public function testPauseFailureIsCaughtAndLogged(): void {
		$scheduleService = $this->createMock(ScheduleService::class);
		$scheduleService->method('pauseForUser')->willThrowException(new RuntimeException('boom'));

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error');

		$listener = new UserLifecycleListener($scheduleService, $logger);
		$listener->handle(new UserDeletedEvent($this->user('deleted.user')));

		$this->addToAssertionCount(1);

	}//end testPauseFailureIsCaughtAndLogged()
}//end class

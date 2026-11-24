<?php

/**
 * Changing the department an agent runs for is for administrators only.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/human-approval-gate/spec.md#requirement-an-admin-shall-be-able-to-switch-ai-off-for-one-department
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Listener;

use OCA\Hermiq\Kernel\Contracts\AgentObjectMatcherInterface;
use OCA\Hermiq\Listener\AgentDepartmentGuardListener;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Refused for a member, allowed for an administrator, and the writes the guard leaves alone.
 *
 * @covers \OCA\Hermiq\Listener\AgentDepartmentGuardListener
 */
class AgentDepartmentGuardListenerTest extends TestCase {

	/**
	 * The guard over doubles, with this writer signed in (or nobody).
	 *
	 * @param string|null $writer The signed-in uid; 'admin' is the administrator.
	 *
	 * @return AgentDepartmentGuardListener
	 */
	private function guard(?string $writer): AgentDepartmentGuardListener {
		$agents = $this->createMock(AgentObjectMatcherInterface::class);
		$agents->method('isAgent')->willReturnCallback(static fn (ObjectEntity $o): bool => (string)$o->getSchema() === 'agent');

		$user = null;
		if ($writer !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($writer);
		}

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturnCallback(static fn (string $uid): bool => $uid === 'admin');

		return new AgentDepartmentGuardListener(agents: $agents, userSession: $session, groupManager: $groups, logger: new NullLogger());
	}//end guard()

	/**
	 * An object of a schema with this data.
	 *
	 * @param array<string, mixed> $data   The data.
	 * @param string               $schema The schema.
	 *
	 * @return ObjectEntity
	 */
	private static function object(array $data, string $schema = 'agent'): ObjectEntity {
		$object = new ObjectEntity();
		$object->setSchema($schema);
		$object->setObject($data);
		return $object;
	}//end object()

	/**
	 * An update of an agent from one department to another.
	 *
	 * @param string|null $from The stored department.
	 * @param string|null $to   The written department.
	 *
	 * @return ObjectUpdatingEvent
	 */
	private static function update(?string $from, ?string $to): ObjectUpdatingEvent {
		return new ObjectUpdatingEvent(
			self::object(data: ['name' => 'Helper', 'department' => $to]),
			self::object(data: ['name' => 'Helper', 'department' => $from])
		);
	}//end update()

	/**
	 * A member cannot move, clear or set an agent's department.
	 *
	 * @return void
	 */
	public function testAMemberCannotChangeTheDepartment(): void {
		foreach ([['sociaal-domein', 'burgerzaken'], ['sociaal-domein', null], ['sociaal-domein', '  ']] as [$from, $to]) {
			$event = self::update(from: $from, to: $to);
			$this->guard(writer: 'pieter')->handle($event);

			self::assertTrue($event->isPropagationStopped(), (string)$to);
			self::assertSame(['department' => AgentDepartmentGuardListener::REFUSED], $event->getErrors());
		}

		$create = new ObjectCreatingEvent(self::object(data: ['name' => 'Nieuw', 'department' => 'burgerzaken']));
		$this->guard(writer: 'pieter')->handle($create);

		self::assertTrue($create->isPropagationStopped());
		self::assertSame(['department' => AgentDepartmentGuardListener::REFUSED], $create->getErrors());
	}//end testAMemberCannotChangeTheDepartment()

	/**
	 * An administrator changes it; nobody signed in is left to the caller.
	 *
	 * @return void
	 */
	public function testAnAdministratorOrASystemWriteMayChangeIt(): void {
		foreach (['admin', null] as $writer) {
			$event = self::update(from: 'sociaal-domein', to: 'burgerzaken');
			$this->guard(writer: $writer)->handle($event);

			self::assertFalse($event->isPropagationStopped());
			self::assertSame([], $event->getErrors());
		}
	}//end testAnAdministratorOrASystemWriteMayChangeIt()

	/**
	 * A member's write that keeps the department, a write of another schema, a
	 * create with no department and an unrelated event pass.
	 *
	 * @return void
	 */
	public function testWritesThatDoNotChangeTheDepartmentPass(): void {
		$guard = $this->guard(writer: 'pieter');

		$same = self::update(from: 'sociaal-domein', to: ' sociaal-domein ');
		$guard->handle($same);
		self::assertFalse($same->isPropagationStopped());

		$other = new ObjectCreatingEvent(self::object(data: ['department' => 'burgerzaken'], schema: 'skill'));
		$guard->handle($other);
		self::assertFalse($other->isPropagationStopped());

		$none = new ObjectCreatingEvent(self::object(data: ['name' => 'Nieuw']));
		$guard->handle($none);
		self::assertFalse($none->isPropagationStopped());

		$created = new ObjectCreatedEvent(self::object(data: ['department' => 'burgerzaken']));
		$guard->handle($created);
		self::assertFalse($created->isPropagationStopped());
	}//end testWritesThatDoNotChangeTheDepartmentPass()
}//end class

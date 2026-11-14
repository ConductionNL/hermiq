<?php

/**
 * Naming the account an agent works with is checked before every write.
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
 * @spec openspec/changes/agent-acting-identity-picker/specs/agent-identity/spec.md#requirement-naming-a-service-account-is-a-governance-action-checked-on-every-write-req-agid-002
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Listener;

use OCA\Hermiq\Kernel\Contracts\ActionAuthServiceInterface;
use OCA\Hermiq\Kernel\Contracts\AgentObjectMatcherInterface;
use OCA\Hermiq\Listener\AgentActingUserGuardListener;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Allowed, refused for the action, refused for the account, and the writes
 * the guard leaves alone.
 *
 * @covers \OCA\Hermiq\Listener\AgentActingUserGuardListener
 */
class AgentActingUserGuardListenerTest extends TestCase {

	/**
	 * The actions each writer passes, by uid.
	 *
	 * @var array<string, bool>
	 */
	private array $mayName = ['admin' => true, 'pieter' => false];

	/**
	 * The accounts that exist, by uid, and whether each is enabled.
	 *
	 * @var array<string, bool>
	 */
	private array $accounts = ['woo-triage' => true, 'burgemeester' => true, 'oud-account' => false];

	/**
	 * The actions asked, as uid:action.
	 *
	 * @var array<int, string>
	 */
	private array $asked = [];

	/**
	 * The guard over doubles, with this writer signed in (or nobody).
	 *
	 * @param string|null $writer The signed-in uid.
	 *
	 * @return AgentActingUserGuardListener
	 */
	private function guard(?string $writer): AgentActingUserGuardListener {
		$agents = $this->createMock(AgentObjectMatcherInterface::class);
		$agents->method('isAgent')->willReturnCallback(static fn (ObjectEntity $o): bool => (string)$o->getSchema() === 'agent');

		$actions = $this->createMock(ActionAuthServiceInterface::class);
		$actions->method('can')->willReturnCallback(
			function (IUser $user, string $action): bool {
				$this->asked[] = $user->getUID() . ':' . $action;
				return ($this->mayName[$user->getUID()] ?? false);
			}
		);

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($writer === null ? null : $this->user(uid: $writer, enabled: true));

		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturnCallback(
			fn (string $uid): ?IUser => isset($this->accounts[$uid]) === true ? $this->user(uid: $uid, enabled: $this->accounts[$uid]) : null
		);

		return new AgentActingUserGuardListener(agents: $agents, actions: $actions, userSession: $session, userManager: $users, logger: new NullLogger());
	}//end guard()

	/**
	 * A user double.
	 *
	 * @param string $uid     The uid.
	 * @param bool   $enabled Whether the account is enabled.
	 *
	 * @return IUser
	 */
	private function user(string $uid, bool $enabled): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$user->method('isEnabled')->willReturn($enabled);
		return $user;
	}//end user()

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
	 * An update of an agent from one acting user to another.
	 *
	 * @param string|null $from The stored acting user.
	 * @param string|null $to   The written acting user.
	 *
	 * @return ObjectUpdatingEvent
	 */
	private static function update(?string $from, ?string $to): ObjectUpdatingEvent {
		return new ObjectUpdatingEvent(
			self::object(data: ['name' => 'Woo-triage', 'actingUser' => $to]),
			self::object(data: ['name' => 'Woo-triage', 'actingUser' => $from])
		);
	}//end update()

	/**
	 * An administrator gives an agent its own service account.
	 *
	 * @return void
	 */
	public function testAnAdministratorNamesAnEnabledAccount(): void {
		$event = self::update(from: null, to: 'woo-triage');
		$this->guard(writer: 'admin')->handle($event);

		self::assertFalse($event->isPropagationStopped());
		self::assertSame([], $event->getErrors());
		self::assertSame(['admin:agent.set-acting-user'], $this->asked);
	}//end testAnAdministratorNamesAnEnabledAccount()

	/**
	 * An owner without the action names an account through the API: refused,
	 * naming the action, on an update and on a create alike.
	 *
	 * @return void
	 */
	public function testAWriterWithoutTheActionIsRefused(): void {
		$update = self::update(from: '', to: 'burgemeester');
		$this->guard(writer: 'pieter')->handle($update);
		self::assertTrue($update->isPropagationStopped());
		self::assertSame(['actingUser' => AgentActingUserGuardListener::REFUSED_ACTION], $update->getErrors());
		self::assertStringContainsString('agent.set-acting-user', $update->getErrors()['actingUser']);

		$create = new ObjectCreatingEvent(self::object(data: ['name' => 'Nieuw', 'actingUser' => ' burgemeester ']));
		$this->guard(writer: 'pieter')->handle($create);
		self::assertTrue($create->isPropagationStopped());
		self::assertSame(AgentActingUserGuardListener::REFUSED_ACTION, $create->getErrors()['actingUser']);
	}//end testAWriterWithoutTheActionIsRefused()

	/**
	 * A disabled or unknown account cannot be named, even by an administrator.
	 *
	 * @return void
	 */
	public function testADisabledOrUnknownAccountIsRefused(): void {
		foreach (['oud-account', 'niemand'] as $account) {
			$event = self::update(from: 'woo-triage', to: $account);
			$this->guard(writer: 'admin')->handle($event);

			self::assertTrue($event->isPropagationStopped(), $account);
			self::assertSame(['actingUser' => AgentActingUserGuardListener::REFUSED_ACCOUNT], $event->getErrors());
		}
	}//end testADisabledOrUnknownAccountIsRefused()

	/**
	 * An unchanged value and clearing the value pass for anyone who may
	 * write the agent, without asking the matrix.
	 *
	 * @return void
	 */
	public function testAnUnchangedOrClearedValuePasses(): void {
		foreach ([['oud-account', 'oud-account'], ['woo-triage', ''], ['woo-triage', null], [null, null]] as [$from, $to]) {
			$event = self::update(from: $from, to: $to);
			$this->guard(writer: 'pieter')->handle($event);

			self::assertFalse($event->isPropagationStopped(), json_encode([$from, $to]));
		}

		self::assertSame([], $this->asked);
	}//end testAnUnchangedOrClearedValuePasses()

	/**
	 * A value another listener added to the write counts as written.
	 *
	 * @return void
	 */
	public function testAValueAddedByAnEarlierListenerIsChecked(): void {
		$event = self::update(from: null, to: null);
		$event->setModifiedData(['actingUser' => 'burgemeester']);
		$this->guard(writer: 'pieter')->handle($event);

		self::assertTrue($event->isPropagationStopped());
	}//end testAValueAddedByAnEarlierListenerIsChecked()

	/**
	 * Another schema's object, a write with nobody signed in, an event the
	 * guard does not handle and a write already stopped are left alone.
	 *
	 * @return void
	 */
	public function testWritesTheGuardDoesNotOwnAreLeftAlone(): void {
		$other = new ObjectUpdatingEvent(self::object(data: ['actingUser' => 'burgemeester'], schema: 'case'));
		$this->guard(writer: 'pieter')->handle($other);
		self::assertFalse($other->isPropagationStopped());

		$background = self::update(from: null, to: 'burgemeester');
		$this->guard(writer: null)->handle($background);
		self::assertFalse($background->isPropagationStopped());

		$stopped = self::update(from: null, to: 'burgemeester');
		$stopped->stopPropagation();
		$this->guard(writer: 'pieter')->handle($stopped);
		self::assertSame([], $stopped->getErrors());

		$this->guard(writer: 'pieter')->handle(new ObjectCreatedEvent(self::object(data: ['actingUser' => 'burgemeester'])));
		self::assertSame([], $this->asked);
	}//end testWritesTheGuardDoesNotOwnAreLeftAlone()
}//end class

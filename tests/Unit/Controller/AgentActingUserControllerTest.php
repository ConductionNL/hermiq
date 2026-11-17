<?php

/**
 * Unit tests for AgentActingUserController (agent-acting-identity-picker).
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Controller
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
 * @spec openspec/changes/agent-acting-identity-picker/specs/agent-identity/spec.md#requirement-the-edit-page-shows-a-declared-account-that-no-longer-resolves-req-agid-003
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Controller;

use OCA\Hermiq\Controller\AgentActingUserController;
use OCA\Hermiq\Kernel\Contracts\AgentAccessServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for whether an agent's declared acting user still resolves.
 *
 * @spec openspec/changes/agent-acting-identity-picker/specs/agent-identity/spec.md#requirement-the-edit-page-shows-a-declared-account-that-no-longer-resolves-req-agid-003
 */
#[CoversClass(AgentActingUserController::class)]
class AgentActingUserControllerTest extends TestCase {

	/**
	 * The per-agent access double.
	 *
	 * @var AgentAccessServiceInterface&MockObject
	 */
	private AgentAccessServiceInterface $access;

	/**
	 * The user lookup double.
	 *
	 * @var IUserManager&MockObject
	 */
	private IUserManager $users;

	/**
	 * Wire fresh doubles before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->access = $this->createMock(AgentAccessServiceInterface::class);
		$this->users = $this->createMock(IUserManager::class);

	}//end setUp()

	/**
	 * The controller for a session of the given user, or an anonymous one.
	 *
	 * @param string|null $uid The caller's uid, or null.
	 *
	 * @return AgentActingUserController
	 */
	private function controller(?string $uid = 'admin'): AgentActingUserController {
		$session = $this->createMock(IUserSession::class);
		if ($uid === null) {
			$session->method('getUser')->willReturn(null);
		} else {
			$session->method('getUser')->willReturn($this->user(uid: $uid, enabled: true));
		}

		return new AgentActingUserController(
			$this->createMock(IRequest::class),
			$this->access,
			$session,
			$this->users
		);

	}//end controller()

	/**
	 * A user double.
	 *
	 * @param string $uid The uid.
	 * @param bool $enabled Whether the account is enabled.
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
	 * An agent object with the given payload.
	 *
	 * @param array<string, mixed> $data The payload.
	 *
	 * @return ObjectEntity
	 */
	private function agent(array $data): ObjectEntity {
		$agent = new ObjectEntity();
		$agent->setUuid('agent-1');
		$agent->setObject($data);
		return $agent;

	}//end agent()

	/**
	 * A departed (disabled) service account answers that it does not resolve.
	 *
	 * @return void
	 */
	public function testADisabledAccountDoesNotResolve(): void {
		$this->access->expects($this->once())->method('loadAccessibleAgent')
			->with('agent-1', 'admin')
			->willReturn($this->agent(['actingUser' => 'oud-account']));
		$this->users->expects($this->once())->method('get')->with('oud-account')
			->willReturn($this->user(uid: 'oud-account', enabled: false));

		$response = $this->controller()->show('agent-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['actingUser' => 'oud-account', 'resolves' => false], $response->getData());

	}//end testADisabledAccountDoesNotResolve()

	/**
	 * An account that no longer exists does not resolve either.
	 *
	 * @return void
	 */
	public function testAMissingAccountDoesNotResolve(): void {
		$this->access->method('loadAccessibleAgent')->willReturn($this->agent(['actingUser' => ' gone ']));
		$this->users->expects($this->once())->method('get')->with('gone')->willReturn(null);

		$this->assertSame(['actingUser' => 'gone', 'resolves' => false], $this->controller()->show('agent-1')->getData());

	}//end testAMissingAccountDoesNotResolve()

	/**
	 * An enabled account resolves.
	 *
	 * @return void
	 */
	public function testAnEnabledAccountResolves(): void {
		$this->access->method('loadAccessibleAgent')->willReturn($this->agent(['actingUser' => 'woo-triage']));
		$this->users->method('get')->with('woo-triage')->willReturn($this->user(uid: 'woo-triage', enabled: true));

		$this->assertSame(['actingUser' => 'woo-triage', 'resolves' => true], $this->controller()->show('agent-1')->getData());

	}//end testAnEnabledAccountResolves()

	/**
	 * An agent with no declared account (or a non-string one) resolves without a lookup.
	 *
	 * @return void
	 */
	public function testNoDeclaredAccountResolvesWithoutALookup(): void {
		$this->access->method('loadAccessibleAgent')->willReturnOnConsecutiveCalls(
			$this->agent(['name' => 'Woo-triage']),
			$this->agent(['actingUser' => ['x']])
		);
		$this->users->expects($this->never())->method('get');

		$this->assertSame(['actingUser' => '', 'resolves' => true], $this->controller()->show('agent-1')->getData());
		$this->assertSame(['actingUser' => '', 'resolves' => true], $this->controller()->show('agent-1')->getData());

	}//end testNoDeclaredAccountResolvesWithoutALookup()

	/**
	 * An agent the caller may not read answers 404 and names no account.
	 *
	 * @return void
	 */
	public function testAnAgentTheCallerCannotReadIsNotFound(): void {
		$this->access->method('loadAccessibleAgent')->willReturn(null);
		$this->users->expects($this->never())->method('get');

		$response = $this->controller('pieter')->show('agent-1');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(['error' => 'Agent not found'], $response->getData());

	}//end testAnAgentTheCallerCannotReadIsNotFound()

	/**
	 * An anonymous request is not found, and the agent is not even loaded.
	 *
	 * @return void
	 */
	public function testAnAnonymousRequestIsNotFound(): void {
		$this->access->expects($this->never())->method('loadAccessibleAgent');

		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller(null)->show('agent-1')->getStatus());

	}//end testAnAnonymousRequestIsNotFound()
}//end class

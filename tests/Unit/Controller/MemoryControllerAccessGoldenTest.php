<?php

/**
 * Golden test of the access gate on every memory route.
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
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/agent-memory/spec.md#requirement-per-tenant-memory-scoping
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Controller;

use OCA\Hermiq\Controller\MemoryController;
use OCA\Hermiq\Service\AgentAccessService;
use OCA\Hermiq\Service\MemoryService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Anonymous, refused and allowed outcomes of all eight routes, with the exact checks made.
 *
 * @covers \OCA\Hermiq\Controller\MemoryController
 *
 * @spec openspec/specs/agent-memory/spec.md#requirement-per-tenant-memory-scoping
 */
class MemoryControllerAccessGoldenTest extends TestCase {

	private const SERVICE_METHODS = [
		'getMemory',
		'appendMemoryEntry',
		'correctMemoryEntry',
		'forgetEntry',
		'listUserProfiles',
		'listSessions',
		'consolidateMemory',
		'recallSessions',
	];

	/**
	 * Every access check and service call, in order.
	 *
	 * @var list<string>
	 */
	private array $calls = [];

	/**
	 * The routes: invocation, access level, the service call once allowed, and the failure message.
	 *
	 * @return array<string, array{0: callable, 1: string, 2: string, 3: string}>
	 */
	public static function routes(): array {
		return [
			'memory' => [static fn (MemoryController $c) => $c->memory(agentId: 'a1'), 'read', 'getMemory', 'Could not load memory'],
			'addMemory' => [static fn (MemoryController $c) => $c->addMemory(agentId: 'a1'), 'write', 'appendMemoryEntry', 'Could not add memory'],
			'correctEntry' => [
				static fn (MemoryController $c) => $c->correctEntry(agentId: 'a1', entryId: 'e1'),
				'write',
				'correctMemoryEntry',
				'Could not correct memory',
			],
			'forgetEntry' => [
				static fn (MemoryController $c) => $c->forgetEntry(agentId: 'a1', entryId: 'e1'),
				'write',
				'forgetEntry',
				'Could not forget memory',
			],
			'userProfiles' => [
				static fn (MemoryController $c) => $c->userProfiles(agentId: 'a1'),
				'read',
				'listUserProfiles',
				'Could not load user profiles',
			],
			'sessions' => [static fn (MemoryController $c) => $c->sessions(agentId: 'a1'), 'read', 'listSessions', 'Could not load sessions'],
			'consolidate' => [static fn (MemoryController $c) => $c->consolidate(agentId: 'a1'), 'write', 'consolidateMemory', 'Consolidation failed'],
			'recall' => [static fn (MemoryController $c) => $c->recall(agentId: 'a1', q: 'x'), 'read', 'recallSessions', 'Recall failed'],
		];

	}//end routes()

	/**
	 * Anonymous is 401 with no check; refused is 404 after the route's own check; allowed proceeds.
	 *
	 * @param callable $route   Invokes the route.
	 * @param string   $level   `read` or `write`.
	 * @param string   $service The service call the route makes once allowed.
	 * @param string   $failure The route's own failure message.
	 *
	 * @return void
	 *
	 * @dataProvider routes
	 */
	public function testGate(callable $route, string $level, string $service, string $failure): void {
		$check = 'loadAccessibleAgent';
		if ($level === 'write') {
			$check = 'loadModifiableAgent';
		}

		$anonymous = $route($this->controller(uid: null, allowed: false));
		$this->assertSame(expected: [401, ['error' => 'Unauthenticated'], []], actual: [$anonymous->getStatus(), $anonymous->getData(), $this->calls]);

		$this->calls = [];
		$refused = $route($this->controller(uid: 'mallory', allowed: false));
		$this->assertSame(
			expected: [404, ['error' => 'Agent not found'], [$check . ' a1 mallory']],
			actual: [$refused->getStatus(), $refused->getData(), $this->calls]
		);

		$this->calls = [];
		$allowed = $route($this->controller(uid: 'alice', allowed: true));
		$this->assertSame(expected: [500, ['error' => $failure]], actual: [$allowed->getStatus(), $allowed->getData()]);
		$this->assertSame(expected: $check . ' a1 alice', actual: $this->calls[0]);
		$this->assertContains(needle: $service, haystack: $this->calls);

	}//end testGate()

	/**
	 * The controller over recording doubles; every service call throws.
	 *
	 * @param string|null $uid     The signed-in user, or null.
	 * @param bool        $allowed Whether the access check finds the agent.
	 *
	 * @return MemoryController
	 */
	private function controller(?string $uid, bool $allowed): MemoryController {
		$session = $this->createMock(originalClassName: IUserSession::class);
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(originalClassName: IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session->method('getUser')->willReturn($user);

		$access = $this->createMock(originalClassName: AgentAccessService::class);
		foreach (['loadAccessibleAgent', 'loadModifiableAgent'] as $method) {
			$access->method($method)->willReturnCallback(
				function (string $agentId, string $userId) use ($method, $allowed): ?ObjectEntity {
					$this->calls[] = $method . ' ' . $agentId . ' ' . $userId;
					if ($allowed === true) {
						return new ObjectEntity();
					}

					return null;
				}
			);
		}

		$memory = $this->createMock(originalClassName: MemoryService::class);
		foreach (self::SERVICE_METHODS as $method) {
			$memory->method($method)->willReturnCallback(
				function () use ($method): never {
					$this->calls[] = $method;
					throw new RuntimeException(message: 'store down');
				}
			);
		}

		$request = $this->createMock(originalClassName: IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => (['text' => 'hello', 'entries' => [['text' => 'x']]][$key] ?? $default)
		);

		return new MemoryController(
			request: $request,
			memoryService: $memory,
			userSession: $session,
			logger: new NullLogger(),
			agentAccess: $access
		);

	}//end controller()
}//end class

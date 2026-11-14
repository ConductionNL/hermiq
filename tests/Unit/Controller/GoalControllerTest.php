<?php

/**
 * Unit tests for GoalController.
 *
 * @category Tests
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
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Controller;

use OCA\Hermiq\Controller\GoalController;
use OCA\Hermiq\Service\GoalService;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Tests for GoalController.
 *
 * @spec openspec/changes/agents-standing-goal/specs/agent-schedule/spec.md#requirement-a-goal-can-be-stopped-by-its-owner-or-the-agent-owner-req-aggoal-003
 */
class GoalControllerTest extends TestCase {

	/**
	 * A controller for the given user (null = signed out) over the given service.
	 *
	 * @param string|null          $uid     The signed-in user.
	 * @param GoalService          $service The service.
	 * @param array<string, mixed> $params  The request parameters.
	 *
	 * @return GoalController
	 */
	private function controller(?string $uid, GoalService $service, array $params = []): GoalController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => ($params[$key] ?? $default)
		);
		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session->method('getUser')->willReturn($user);

		return new GoalController($request, $service, $session, new NullLogger());
	}//end controller()

	/**
	 * Another user stopping a goal gets 404; the owner gets the stopped goal.
	 *
	 * @return void
	 */
	public function testAnotherUserGets404WhenStoppingAGoal(): void {
		$service = $this->createMock(GoalService::class);
		$service->method('stop')->willReturnCallback(
			static function (string $goalId, string $uid): array {
				if ($uid !== 'officer') {
					throw new RuntimeException('Goal not found', 404);
				}

				return ['id' => $goalId, 'status' => 'stopped'];
			}
		);

		$this->assertSame(404, $this->controller('mallory', $service)->stop('g-1')->getStatus());
		$response = $this->controller('officer', $service)->stop('g-1');
		$this->assertSame(200, $response->getStatus());
		$this->assertSame('stopped', $response->getData()['status']);
		$this->assertSame(401, $this->controller(null, $service)->stop('g-1')->getStatus());
	}//end testAnotherUserGets404WhenStoppingAGoal()

	/**
	 * Setting passes the form's fields through; a second active goal is 409, a bad one 422.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agents-standing-goal/specs/agent-schedule/spec.md#requirement-a-person-can-give-an-agent-a-standing-goal-with-a-check-req-aggoal-001
	 */
	public function testSettingAGoalPassesTheFormThrough(): void {
		$params = ['statement' => 'Every reminder sent', 'check' => ['kind' => 'judge', 'question' => 'Sent?'], 'intervalMinutes' => 30, 'maxTurns' => 5];
		$service = $this->createMock(GoalService::class);
		$service->expects($this->once())->method('set')
			->with('s-1', 'officer', ['statement' => 'Every reminder sent', 'check' => ['kind' => 'judge', 'question' => 'Sent?'], 'intervalMinutes' => 30, 'maxTurns' => 5])
			->willReturn(['id' => 'g-1', 'status' => 'active', 'turnsUsed' => 0, 'maxTurns' => 5]);
		$response = $this->controller('officer', $service, $params)->create('s-1');
		$this->assertSame(200, $response->getStatus());
		$this->assertSame('g-1', $response->getData()['id']);

		foreach ([409, 422, 404] as $code) {
			$refusing = $this->createMock(GoalService::class);
			$refusing->method('set')->willThrowException(new RuntimeException('no', $code));
			$this->assertSame($code, $this->controller('officer', $refusing, $params)->create('s-1')->getStatus());
		}
	}//end testSettingAGoalPassesTheFormThrough()

	/**
	 * Reading a session without a goal answers `{goal: null}`; a stranger's session is 404.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agents-standing-goal/specs/agent-schedule/spec.md#requirement-a-person-can-give-an-agent-a-standing-goal-with-a-check-req-aggoal-001
	 */
	public function testReadingTheGoalOfASession(): void {
		$service = $this->createMock(GoalService::class);
		$service->method('forSession')->willReturnCallback(
			static function (string $sessionId, string $uid): ?array {
				if ($uid !== 'officer') {
					throw new RuntimeException('Session not found', 404);
				}

				return null;
			}
		);

		$this->assertSame(['goal' => null], $this->controller('officer', $service)->show('s-1')->getData());
		$this->assertSame(404, $this->controller('mallory', $service)->show('s-1')->getStatus());
	}//end testReadingTheGoalOfASession()
}//end class

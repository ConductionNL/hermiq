<?php

/**
 * Hermiq SessionForkController: the fork route's answers.
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
 * @spec openspec/changes/chat-fork-and-share-link/specs/session-fork/spec.md#requirement-the-owner-can-fork-a-session-at-an-answer-req-sfork-001
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Controller;

use OCA\Hermiq\Controller\SessionForkController;
use OCA\Hermiq\Service\SessionForkException;
use OCA\Hermiq\Service\SessionForkService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the fork route.
 */
class SessionForkControllerTest extends TestCase {

	/**
	 * The controller with the given service and caller.
	 *
	 * @param SessionForkService $forks  The service.
	 * @param string|null        $caller The signed-in uid, null when nobody is.
	 *
	 * @return SessionForkController
	 */
	private function controller(SessionForkService $forks, ?string $caller): SessionForkController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => ($key === 'turnUuid' ? ' t-2 ' : $default)
		);
		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($caller !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($caller);
		}

		$session->method('getUser')->willReturn($user);

		return new SessionForkController(request: $request, forks: $forks, userSession: $session);
	}//end controller()

	/**
	 * The owner gets 201 with the new session; the caller and the trimmed turn reach the service.
	 *
	 * @return void
	 */
	public function testTheOwnerGetsTheNewSession(): void {
		$fork = new ObjectEntity();
		$fork->setUuid('s-2');
		$fork->setObject(['title' => 'Omgevingsvisie 2040 (fork)', 'agentId' => 'agent-1', 'forkedFrom' => ['sessionId' => 's-1', 'turnId' => 't-2']]);
		$forks = $this->createMock(SessionForkService::class);
		$forks->expects($this->once())->method('fork')->with('s-1', 't-2', 'alice')->willReturn(['session' => $fork, 'turns' => 4]);

		$response = $this->controller(forks: $forks, caller: 'alice')->create(uuid: 's-1');

		$this->assertSame(201, $response->getStatus());
		$this->assertSame(
			['uuid' => 's-2', 'title' => 'Omgevingsvisie 2040 (fork)', 'agentId' => 'agent-1', 'forkedFrom' => ['sessionId' => 's-1', 'turnId' => 't-2'], 'messageCount' => 4],
			$response->getData()
		);
	}

	/**
	 * A refusal answers with its own status and message.
	 *
	 * @return void
	 */
	public function testARefusalKeepsItsStatus(): void {
		$forks = $this->createMock(SessionForkService::class);
		$forks->method('fork')->willThrowException(new SessionForkException('Session not found.', 404));

		$response = $this->controller(forks: $forks, caller: 'carol')->create(uuid: 's-1');

		$this->assertSame(404, $response->getStatus());
		$this->assertSame(['error' => 'Session not found.'], $response->getData());
	}

	/**
	 * Nobody signed in: 401, and the service is never asked.
	 *
	 * @return void
	 */
	public function testNobodySignedInIs401(): void {
		$forks = $this->createMock(SessionForkService::class);
		$forks->expects($this->never())->method('fork');

		$this->assertSame(401, $this->controller(forks: $forks, caller: null)->create(uuid: 's-1')->getStatus());
	}
}

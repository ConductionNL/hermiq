<?php

/**
 * SessionShareController: thin routes over SessionShareService, every refusal
 * answered with its status (chat-fork-and-share-link).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/chat-fork-and-share-link/specs/session-sharing/spec.md#requirement-the-owner-shares-a-session-with-named-users-or-groups-req-sshare-001
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Controller;

use OCA\Hermiq\Controller\SessionShareController;
use OCA\Hermiq\Kernel\Contracts\SessionShareServiceInterface;
use OCA\Hermiq\Service\SessionShareException;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * The routes pass the caller and the body through and answer refusals with their code.
 */
class SessionShareControllerTest extends TestCase {

	/**
	 * The controller as a caller, or as nobody.
	 *
	 * @param string|null                  $uid     The caller.
	 * @param SessionShareServiceInterface $service The service.
	 * @param array<string, string>        $body    The request body.
	 *
	 * @return SessionShareController
	 */
	private function controller(?string $uid, SessionShareServiceInterface $service, array $body = []): SessionShareController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $key, mixed $default = null): mixed => ($body[$key] ?? $default));
		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session->method('getUser')->willReturn($user);

		return new SessionShareController(request: $request, shares: $service, userSession: $session);
	}//end controller()

	/**
	 * Each route calls the service as the caller, with the body trimmed.
	 *
	 * @return void
	 */
	public function testTheRoutesActAsTheCaller(): void {
		$service = $this->createMock(SessionShareServiceInterface::class);
		$service->expects($this->once())->method('list')->with('sess-1', 'anne')->willReturn([['id' => 'bram']]);
		$service->expects($this->once())->method('add')->with('sess-1', 'anne', 'group', 'team-inkoop')->willReturn([['id' => 'team-inkoop']]);
		$service->expects($this->once())->method('remove')->with('sess-1', 'anne', 'user', 'bram')->willReturn([]);

		$this->assertSame(['results' => [['id' => 'bram']]], $this->controller('anne', $service)->index(uuid: 'sess-1')->getData());
		$added = $this->controller('anne', $service, ['type' => ' group ', 'id' => ' team-inkoop '])->create(uuid: 'sess-1');
		$this->assertSame(200, $added->getStatus());
		$this->assertSame(['results' => [['id' => 'team-inkoop']]], $added->getData());
		$this->assertSame(['results' => []], $this->controller('anne', $service)->destroy(uuid: 'sess-1', type: 'user', id: 'bram')->getData());
	}//end testTheRoutesActAsTheCaller()

	/**
	 * A refusal is answered with its status and message; no user is a 401 without a call.
	 *
	 * @return void
	 */
	public function testRefusalsCarryTheirStatus(): void {
		$service = $this->createMock(SessionShareServiceInterface::class);
		$service->method('add')->willThrowException(new SessionShareException('You can only share with members of your groups.', 403));
		$service->method('list')->willThrowException(new SessionShareException('Session not found.', 404));

		$refused = $this->controller('anne', $service, ['type' => 'user', 'id' => 'dirk'])->create(uuid: 'sess-1');
		$this->assertSame(403, $refused->getStatus());
		$this->assertSame(['error' => 'You can only share with members of your groups.'], $refused->getData());
		$this->assertSame(404, $this->controller('bram', $service)->index(uuid: 'sess-1')->getStatus());

		$nobody = $this->createMock(SessionShareServiceInterface::class);
		$nobody->expects($this->never())->method('list');
		$this->assertSame(401, $this->controller(null, $nobody)->index(uuid: 'sess-1')->getStatus());
	}//end testRefusalsCarryTheirStatus()
}//end class

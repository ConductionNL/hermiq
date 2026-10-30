<?php

/**
 * SessionParticipantController maps the service's refusals to their status
 * (chat-work-together-in-one-session). Uses the REAL SessionParticipantService
 * over a mocked ObjectService, so the owner check under test is the real one.
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
 * @spec openspec/specs/session-participants/spec.md#requirement-the-owner-invites-colleagues-into-a-session-req-spart-001
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Controller;

use OCA\Hermiq\Controller\SessionParticipantController;
use OCA\Hermiq\Service\SessionParticipantService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Notification\IManager as INotificationManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests for SessionParticipantController.
 *
 * @spec openspec/specs/session-participants/spec.md#requirement-the-owner-invites-colleagues-into-a-session-req-spart-001
 */
class SessionParticipantControllerTest extends TestCase {

	/**
	 * A controller acting as $uid (null = not logged in) over anne's session with bram listed.
	 *
	 * @param string|null $uid The caller.
	 *
	 * @return SessionParticipantController
	 */
	private function controllerAs(?string $uid): SessionParticipantController {
		$session = new ObjectEntity();
		$session->setUuid('sess-1');
		$session->setObject(['agentId' => 'a1', 'userId' => 'anne', 'participants' => ['bram']]);
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('find')->willReturn($session);
		$objectService->expects($this->never())->method('saveObject');

		$service = new SessionParticipantService(
			$objectService,
			$this->createMock(IUserManager::class),
			$this->createMock(INotificationManager::class),
			new NullLogger()
		);

		$userSession = $this->createMock(IUserSession::class);
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$userSession->method('getUser')->willReturn($user);

		return new SessionParticipantController($this->createMock(IRequest::class), $service, $userSession);
	}//end controllerAs()

	/**
	 * A participant and a stranger get 404 on every route; no login is 401.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/session-participants/spec.md#requirement-the-owner-invites-colleagues-into-a-session-req-spart-001
	 */
	public function testOnlyTheOwnerReachesTheRoster(): void {
		foreach (['bram', 'carol'] as $caller) {
			$controller = $this->controllerAs($caller);
			$this->assertSame(404, $controller->index('sess-1')->getStatus());
			$this->assertSame(404, $controller->create('sess-1')->getStatus());
			$this->assertSame(404, $controller->destroy('sess-1', 'bram')->getStatus());
		}

		$this->assertSame(401, $this->controllerAs(null)->index('sess-1')->getStatus());
		$this->assertSame(200, $this->controllerAs('anne')->index('sess-1')->getStatus());

	}//end testOnlyTheOwnerReachesTheRoster()
}//end class

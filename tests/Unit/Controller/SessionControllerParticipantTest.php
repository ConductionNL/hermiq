<?php

/**
 * SessionController for a listed participant (chat-work-together-in-one-session).
 *
 * A participant finds the session in their list, reads it and its turns (with
 * who asked each one), and cannot rename, archive or delete it. A stranger gets
 * 403. Real ObjectEntity; mocks of the real ObjectService, Engine, TalkSessionRoom
 * and IUserSession.
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
 * @spec openspec/specs/session-participants/spec.md#requirement-an-invited-colleague-reads-and-takes-turns-req-spart-002
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Controller;

use OCA\Hermiq\Controller\SessionController;
use OCA\Hermiq\Service\Engine\Engine;
use OCA\Hermiq\Service\Talk\TalkSessionRoom;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests for participant access in SessionController.
 *
 * @spec openspec/specs/session-participants/spec.md#requirement-an-invited-colleague-reads-and-takes-turns-req-spart-002
 */
class SessionControllerParticipantTest extends TestCase {

	/**
	 * Every findAll config the controller sent.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $queries = [];

	/**
	 * Build a controller acting as $uid over one stored session (owner anne, participant bram).
	 *
	 * @param string $uid The caller.
	 *
	 * @return array{0: SessionController, 1: ObjectService}
	 */
	private function controllerAs(string $uid): array {
		$session = new ObjectEntity();
		$session->setUuid('sess-1');
		$session->setObject(['agentId' => 'a1', 'userId' => 'anne', 'title' => 'Omgevingsvisie 2040', 'participants' => ['bram']]);

		$turn = new ObjectEntity();
		$turn->setUuid('turn-1');
		$turn->setObject(['sessionId' => 'sess-1', 'role' => 'user', 'content' => 'Wat staat er over groen?', 'authorId' => 'bram', 'authorDisplayName' => 'Bram Jansen']);

		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('setRegister')->willReturnSelf();
		$objectService->method('setSchema')->willReturnSelf();
		$objectService->method('find')->willReturn($session);
		$objectService->method('findAll')->willReturnCallback(
			function (array $config) use ($session, $turn, $uid): array {
				$this->queries[] = $config;
				$filters = ($config['filters'] ?? []);
				if (isset($filters['sessionId']) === true) {
					return [$turn];
				}

				if (($filters['userId'] ?? null) === $uid && $uid === 'anne') {
					return [$session];
				}

				if (($filters['participants'] ?? null) === $uid && $uid === 'bram') {
					return [$session];
				}

				return [];
			}
		);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn([]);

		$sessionRoom = $this->createMock(TalkSessionRoom::class);
		$sessionRoom->method('attachToSession')->willReturnArgument(0);

		$controller = new SessionController(
			request: $request,
			engine: $this->createMock(Engine::class),
			objectService: $objectService,
			userSession: $userSession,
			sessionRoom: $sessionRoom,
			logger: new NullLogger()
		);

		return [$controller, $objectService];
	}//end controllerAs()

	/**
	 * A participant sees the session in their list, marked as shared with them.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/session-participants/spec.md#requirement-an-invited-colleague-reads-and-takes-turns-req-spart-002
	 */
	public function testAParticipantSeesTheSessionInTheirList(): void {
		[$controller] = $this->controllerAs('bram');
		$data = $controller->index()->getData();

		$this->assertSame(1, $data['total']);
		$this->assertSame('sess-1', $data['results'][0]['uuid']);
		$this->assertSame('participant', $data['results'][0]['role']);

	}//end testAParticipantSeesTheSessionInTheirList()

	/**
	 * The owner's list marks the session as theirs and shows the roster.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/session-participants/spec.md#requirement-an-invited-colleague-reads-and-takes-turns-req-spart-002
	 */
	public function testTheOwnersListMarksTheirOwnSession(): void {
		[$controller] = $this->controllerAs('anne');
		$data = $controller->index()->getData();

		$this->assertSame(1, $data['total'], 'A session the owner also matches as participant is listed once.');
		$this->assertSame('owner', $data['results'][0]['role']);
		$this->assertSame(['bram'], $data['results'][0]['participants']);

	}//end testTheOwnersListMarksTheirOwnSession()

	/**
	 * A participant reads the session and its turns, each with who asked it.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/session-participants/spec.md#requirement-an-invited-colleague-reads-and-takes-turns-req-spart-002
	 */
	public function testAParticipantReadsTheSessionAndSeesWhoAsked(): void {
		[$controller] = $this->controllerAs('bram');

		$this->assertSame(200, $controller->show('sess-1')->getStatus());
		$messages = $controller->messages('sess-1');
		$this->assertSame(200, $messages->getStatus());
		$this->assertSame('bram', $messages->getData()['results'][0]['authorId']);
		$this->assertSame('Bram Jansen', $messages->getData()['results'][0]['authorDisplayName']);

	}//end testAParticipantReadsTheSessionAndSeesWhoAsked()

	/**
	 * A stranger gets 403 on the session and its turns.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/session-participants/spec.md#requirement-an-invited-colleague-reads-and-takes-turns-req-spart-002
	 */
	public function testAStrangerIsRefused(): void {
		[$controller] = $this->controllerAs('carol');

		$this->assertSame(403, $controller->show('sess-1')->getStatus());
		$this->assertSame(403, $controller->messages('sess-1')->getStatus());
		$this->assertSame(0, $controller->index()->getData()['total']);

	}//end testAStrangerIsRefused()

	/**
	 * A participant cannot delete or rename the session.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/session-participants/spec.md#requirement-an-invited-colleague-reads-and-takes-turns-req-spart-002
	 */
	public function testAParticipantCannotDeleteOrRename(): void {
		[$controller, $objectService] = $this->controllerAs('bram');
		$objectService->expects($this->never())->method('saveObject');
		$objectService->expects($this->never())->method('deleteObject');

		$this->assertSame(403, $controller->destroy('sess-1')->getStatus());
		$this->assertSame(403, $controller->update('sess-1')->getStatus());

	}//end testAParticipantCannotDeleteOrRename()
}//end class

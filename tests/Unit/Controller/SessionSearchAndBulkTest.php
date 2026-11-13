<?php

/**
 * Unit tests for searching the session list and acting on several sessions at once.
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
 * @spec openspec/changes/chat-session-search-and-bulk-delete/specs/session-surface/spec.md
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Controller;

use OCA\Hermiq\Controller\SessionController;
use OCA\Hermiq\Service\AgentAccessService;
use OCA\Hermiq\Service\Chat\SessionCollaborators;
use OCA\Hermiq\Service\Chat\SessionPendingApprovals;
use OCA\Hermiq\Service\Chat\SessionSubject;
use OCA\Hermiq\Service\Engine\Engine;
use OCA\Hermiq\Service\Talk\TalkSessionRoom;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * `GET /api/sessions?search=` and `POST /api/sessions/bulk`.
 *
 * @covers \OCA\Hermiq\Controller\SessionController
 * @uses   \OCA\Hermiq\Service\SessionSearchService
 * @uses   \OCA\Hermiq\Service\Chat\SessionCollaborators
 * @uses   \OCA\Hermiq\Service\Chat\SessionSubject
 * @uses   \OCA\Hermiq\Service\Talk\ConversationParticipation
 *
 * @spec openspec/changes/chat-session-search-and-bulk-delete/specs/session-surface/spec.md
 */
class SessionSearchAndBulkTest extends TestCase {

	/**
	 * Mock request.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest $request;

	/**
	 * Mock ObjectService.
	 *
	 * @var ObjectService&MockObject
	 */
	private ObjectService $objectService;

	/**
	 * The sessions find() knows, by uuid.
	 *
	 * @var array<string, ObjectEntity>
	 */
	private array $stored = [];

	/**
	 * Every deleteObject() call as [uuid, schema].
	 *
	 * @var list<array{0: string, 1: string}>
	 */
	private array $deleted = [];

	/**
	 * Every saveObject() call as [uuid, metadata].
	 *
	 * @var list<array{0: string, 1: mixed}>
	 */
	private array $saved = [];

	/**
	 * Wire the mocks: alice is signed in.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->objectService = $this->createMock(ObjectService::class);
		$this->objectService->method('setRegister')->willReturnSelf();
		$this->objectService->method('setSchema')->willReturnSelf();
		$this->objectService->method('find')->willReturnCallback(
			fn (mixed $id, mixed ...$rest): ?ObjectEntity => ($this->stored[(string)$id] ?? null)
		);
		$this->objectService->method('deleteObject')->willReturnCallback(
			function (string $uuid, mixed $register = null, mixed $schema = null): bool {
				$this->deleted[] = [$uuid, (string)$schema];
				return true;
			}
		);
		$this->objectService->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend = [], mixed $register = null, mixed $schema = null, ?string $uuid = null): ObjectEntity {
				$this->saved[] = [(string)$uuid, ($object['metadata'] ?? null)];
				return $this->session(uuid: (string)$uuid, payload: $object);
			}
		);
	}//end setUp()

	/**
	 * The controller over the mocks.
	 *
	 * @return SessionController
	 */
	private function controller(): SessionController {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$sessionRoom = $this->createMock(TalkSessionRoom::class);
		$sessionRoom->method('attachToSession')->willReturnArgument(0);

		return new SessionController(
			request: $this->request,
			engine: $this->createMock(Engine::class),
			objectService: $this->objectService,
			userSession: $userSession,
			logger: $this->createMock(LoggerInterface::class),
			collaborators: new SessionCollaborators(
				sessionRoom: $sessionRoom,
				agentAccess: $this->createMock(AgentAccessService::class),
				subject: new SessionSubject(objectService: $this->objectService),
				pendingApprovals: $this->createMock(SessionPendingApprovals::class)
			)
		);
	}//end controller()

	/**
	 * A session or turn fixture.
	 *
	 * @param string               $uuid    The uuid.
	 * @param array<string, mixed> $payload The payload.
	 *
	 * @return ObjectEntity
	 */
	private function session(string $uuid, array $payload): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setObject($payload);
		return $entity;
	}//end session()

	/**
	 * Lists two own sessions and a colleague's shared one, and answers the turn search.
	 *
	 * @param list<ObjectEntity> $turns The turns the text search finds.
	 * @param array<int, array<string, mixed>> $searches Receives every turn-search config.
	 *
	 * @return void
	 */
	private function world(array $turns, array &$searches): void {
		$own = $this->session(uuid: 's-woz', payload: ['userId' => 'alice', 'title' => 'Bezwaren', 'agentId' => 'a1']);
		$titled = $this->session(uuid: 's-monument', payload: ['userId' => 'alice', 'title' => 'Monumentenzorg', 'agentId' => 'a1']);
		$shared = $this->session(uuid: 's-bob', payload: ['userId' => 'bob', 'title' => 'Monument bij Bob', 'participants' => ['alice'], 'agentId' => 'a1']);
		$this->objectService->method('findAll')->willReturnCallback(
			function (array $config) use ($own, $titled, $shared, $turns, &$searches): array {
				$filters = ($config['filters'] ?? []);
				if (isset($filters['@self.owner']) === true) {
					$searches[] = $config;
					return $turns;
				}

				if (isset($filters['userId']) === true) {
					return [$own, $titled];
				}

				return [$shared];
			}
		);
	}//end world()

	/**
	 * A word from a turn finds its session, with the turn and an excerpt; a title hit says so.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/chat-session-search-and-bulk-delete/specs/session-surface/spec.md#requirement-a-person-can-search-their-own-sessions-by-title-and-content-req-ssrch-001
	 */
	public function testASearchFindsOwnSessionsByTurnAndByTitle(): void {
		$searches = [];
		$this->world(
			turns: [
				$this->session(uuid: 't-1', payload: ['sessionId' => 's-woz', 'content' => 'Hoe beoordeel ik een WOZ-bezwaar over een monument?']),
				$this->session(uuid: 't-bob', payload: ['sessionId' => 's-bob', 'content' => 'monument']),
			],
			searches: $searches
		);
		$this->request->method('getParams')->willReturn(['search' => ' monument ']);

		$data = $this->controller()->index()->getData();

		$this->assertSame(['s-woz', 's-monument'], array_column($data['results'], 'uuid'));
		$this->assertSame(
			['turnUuid' => 't-1', 'excerpt' => 'Hoe beoordeel ik een WOZ-bezwaar over een monument?'],
			$data['results'][0]['match']
		);
		$this->assertSame(['title' => true], $data['results'][1]['match']);
		$this->assertSame(2, $data['total']);
		$this->assertSame('monument', $data['search']);
		$this->assertFalse($data['truncated']);
		$this->assertSame(['@self.owner' => 'alice'], $searches[0]['filters']);
		$this->assertSame('monument', $searches[0]['search']);
	}//end testASearchFindsOwnSessionsByTurnAndByTitle()

	/**
	 * A word only a colleague's shared session holds finds nothing.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/chat-session-search-and-bulk-delete/specs/session-surface/spec.md#requirement-a-person-can-search-their-own-sessions-by-title-and-content-req-ssrch-001
	 */
	public function testAColleaguesSessionNeverAnswers(): void {
		$searches = [];
		$this->world(
			turns: [$this->session(uuid: 't-bob', payload: ['sessionId' => 's-bob', 'content' => 'Bob zegt kapelletje'])],
			searches: $searches
		);
		$this->request->method('getParams')->willReturn(['search' => 'Bob']);

		$data = $this->controller()->index()->getData();

		$this->assertSame([], $data['results']);
		$this->assertSame(0, $data['total']);
	}//end testAColleaguesSessionNeverAnswers()

	/**
	 * Without a term, or with one character, the list is the plain list and no turn is searched.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/chat-session-search-and-bulk-delete/specs/session-surface/spec.md#requirement-a-person-can-search-their-own-sessions-by-title-and-content-req-ssrch-001
	 */
	public function testWithoutATermTheListIsUnchanged(): void {
		foreach ([[], ['search' => 'm']] as $params) {
			$searches = [];
			$this->objectService = $this->createMock(ObjectService::class);
			$this->setUp();
			$this->world(turns: [], searches: $searches);
			$this->request->method('getParams')->willReturn($params);

			$data = $this->controller()->index()->getData();

			$this->assertSame(['results', 'total', 'limit', 'offset'], array_keys($data));
			$this->assertSame(3, $data['total']);
			$this->assertArrayNotHasKey('match', $data['results'][0]);
			$this->assertSame([], $searches);
		}
	}//end testWithoutATermTheListIsUnchanged()

	/**
	 * Post a bulk request.
	 *
	 * @param mixed $action The action.
	 * @param mixed $uuids  The uuids.
	 *
	 * @return array{status: int, data: mixed}
	 */
	private function bulk(mixed $action, mixed $uuids): array {
		$this->request->method('getParam')->willReturnCallback(
			static fn (string $key): mixed => ['action' => $action, 'uuids' => $uuids][$key] ?? null
		);
		$response = $this->controller()->bulk();

		return ['status' => $response->getStatus(), 'data' => $response->getData()];
	}//end bulk()

	/**
	 * Two own sessions are deleted with their feedback and turns; a colleague's reads as not found.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/chat-session-search-and-bulk-delete/specs/session-surface/spec.md#requirement-a-person-can-archive-restore-or-delete-several-sessions-in-one-action-req-ssrch-002
	 * @spec openspec/changes/chat-session-search-and-bulk-delete/specs/session-surface/spec.md#requirement-deleting-a-session-removes-everything-that-belongs-to-it-req-ssrch-003
	 */
	public function testBulkDeleteSkipsAForeignSession(): void {
		$this->stored = [
			's1' => $this->session(uuid: 's1', payload: ['userId' => 'alice']),
			's2' => $this->session(uuid: 's2', payload: ['userId' => 'alice']),
			's-bob' => $this->session(uuid: 's-bob', payload: ['userId' => 'bob']),
		];
		$this->objectService->method('findAll')->willReturnCallback(
			function (array $config): array {
				$filters = $config['filters'];
				if (isset($filters['conversationId']) === true) {
					return [$this->session(uuid: 'fb-' . $filters['conversationId'], payload: [])];
				}

				return [$this->session(uuid: 'turn-' . $filters['sessionId'], payload: [])];
			}
		);

		$answer = $this->bulk(action: 'delete', uuids: ['s1', 's-bob', 's2', 's1']);

		$this->assertSame(200, $answer['status']);
		$this->assertSame(
			[
				['uuid' => 's1', 'status' => 'ok'],
				['uuid' => 's-bob', 'status' => 'not_found'],
				['uuid' => 's2', 'status' => 'ok'],
			],
			$answer['data']['results']
		);
		$this->assertSame(
			[
				['fb-s1', 'feedback'], ['turn-s1', 'agentsessionturn'], ['s1', 'agentsession'],
				['fb-s2', 'feedback'], ['turn-s2', 'agentsessionturn'], ['s2', 'agentsession'],
			],
			$this->deleted
		);
	}//end testBulkDeleteSkipsAForeignSession()

	/**
	 * Archive marks only unarchived sessions; restore clears the marker; a missing one is not found.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/chat-session-search-and-bulk-delete/specs/session-surface/spec.md#requirement-a-person-can-archive-restore-or-delete-several-sessions-in-one-action-req-ssrch-002
	 */
	public function testBulkArchiveAndRestore(): void {
		$archived = ['deletedAt' => '2026-10-01T00:00:00+00:00', 'deletedBy' => 'alice'];
		$this->stored = [
			'active' => $this->session(uuid: 'active', payload: ['userId' => 'alice']),
			'archived' => $this->session(uuid: 'archived', payload: ['userId' => 'alice', 'metadata' => $archived]),
		];

		$answer = $this->bulk(action: 'archive', uuids: ['active', 'archived', 'gone']);

		$this->assertSame(['ok', 'ok', 'not_found'], array_column($answer['data']['results'], 'status'));
		$this->assertCount(1, $this->saved);
		$this->assertSame('active', $this->saved[0][0]);
		$this->assertSame('alice', $this->saved[0][1]['deletedBy']);

		$this->saved = [];
		$this->request = $this->createMock(IRequest::class);
		$answer = $this->bulk(action: 'restore', uuids: ['archived']);

		$this->assertSame([['uuid' => 'archived', 'status' => 'ok']], $answer['data']['results']);
		$this->assertSame([['archived', null]], $this->saved);
		$this->assertSame([], $this->deleted);
	}//end testBulkArchiveAndRestore()

	/**
	 * A failing write is reported as refused for that session only.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/chat-session-search-and-bulk-delete/specs/session-surface/spec.md#requirement-a-person-can-archive-restore-or-delete-several-sessions-in-one-action-req-ssrch-002
	 */
	public function testAFailedWriteIsRefused(): void {
		$this->objectService = $this->createMock(ObjectService::class);
		$this->objectService->method('find')->willReturn($this->session(uuid: 's1', payload: ['userId' => 'alice']));
		$this->objectService->method('saveObject')->willThrowException(new RuntimeException('down'));

		$answer = $this->bulk(action: 'archive', uuids: ['s1']);

		$this->assertSame([['uuid' => 's1', 'status' => 'refused']], $answer['data']['results']);
	}//end testAFailedWriteIsRefused()

	/**
	 * A request outside the bounds is 400 and changes nothing.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/chat-session-search-and-bulk-delete/specs/session-surface/spec.md#requirement-a-person-can-archive-restore-or-delete-several-sessions-in-one-action-req-ssrch-002
	 */
	public function testAnOutOfBoundsRequestChangesNothing(): void {
		$this->stored = ['s1' => $this->session(uuid: 's1', payload: ['userId' => 'alice'])];
		$cases = [
			['delete', array_map(static fn (int $i): string => 's' . $i, range(1, 101))],
			['delete', []],
			['delete', 's1'],
			['delete', ['s1', 7]],
			['purge', ['s1']],
			[null, ['s1']],
		];

		foreach ($cases as [$action, $uuids]) {
			$this->request = $this->createMock(IRequest::class);
			$answer = $this->bulk(action: $action, uuids: $uuids);
			$this->assertSame(400, $answer['status'], json_encode([$action, is_array($uuids) ? count($uuids) : $uuids]));
		}

		$this->assertSame([], $this->deleted);
		$this->assertSame([], $this->saved);
	}//end testAnOutOfBoundsRequestChangesNothing()

	/**
	 * A signed-out caller is 401.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/chat-session-search-and-bulk-delete/specs/session-surface/spec.md#requirement-a-person-can-archive-restore-or-delete-several-sessions-in-one-action-req-ssrch-002
	 */
	public function testASignedOutCallerIsRefused(): void {
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn(null);
		$controller = new SessionController(
			request: $this->request,
			engine: $this->createMock(Engine::class),
			objectService: $this->objectService,
			userSession: $userSession,
			logger: $this->createMock(LoggerInterface::class),
			collaborators: new SessionCollaborators(
				sessionRoom: $this->createMock(TalkSessionRoom::class),
				agentAccess: $this->createMock(AgentAccessService::class),
				subject: new SessionSubject(objectService: $this->objectService),
				pendingApprovals: $this->createMock(SessionPendingApprovals::class)
			)
		);

		$this->assertSame(401, $controller->bulk()->getStatus());
	}//end testASignedOutCallerIsRefused()
}//end class

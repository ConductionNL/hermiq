<?php

/**
 * Unit tests for SessionController (agent-engine-port, renamed by session-api-rename).
 *
 * Exercises the ported conversation CRUD against ObjectService: the
 * active/archived partition on the payload-level `metadata.deletedAt` marker,
 * the gate-7 ownership guards (403 on a foreign conversation), the immutable
 * field protection on update, the two-step destroy (archive → permanent), and
 * restore clearing the marker.
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
 * @spec openspec/changes/agent-engine-port/tasks.md#task-4-1
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Controller;

use Exception;
use OCA\Hermiq\Controller\SessionController;
use OCA\Hermiq\Service\AgentAccessService;
use OCA\Hermiq\Service\Engine\Engine;
use OCA\Hermiq\Service\Talk\TalkSessionRoom;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the agent-engine-port SessionController.
 *
 * @spec openspec/changes/agent-engine-port/tasks.md#task-4-1
 */
class SessionControllerTest extends TestCase {

	/**
	 * Mock request.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest $request;

	/**
	 * Mock engine facade.
	 *
	 * @var Engine&MockObject
	 */
	private Engine $engine;

	/**
	 * Mock ObjectService.
	 *
	 * @var ObjectService&MockObject
	 */
	private ObjectService $objectService;

	/**
	 * Mock user session (alice by default).
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession $userSession;

	/**
	 * Mock agent access (every agent readable unless a test says otherwise).
	 *
	 * @var AgentAccessService&MockObject
	 */
	private AgentAccessService $agentAccess;

	/**
	 * Wire fresh mocks before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->engine = $this->createMock(Engine::class);
		$this->objectService = $this->createMock(ObjectService::class);
		$this->objectService->method('setRegister')->willReturnSelf();
		$this->objectService->method('setSchema')->willReturnSelf();

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->userSession = $this->createMock(IUserSession::class);
		$this->userSession->method('getUser')->willReturn($user);

		$this->agentAccess = $this->createMock(AgentAccessService::class);

	}//end setUp()

	/**
	 * An agent the caller may use.
	 *
	 * @param string $uuid The agent uuid.
	 *
	 * @return ObjectEntity
	 */
	private function readableAgent(string $uuid): ObjectEntity {
		$agent = new ObjectEntity();
		$agent->setUuid($uuid);
		$agent->setObject(['name' => 'Agent builder', 'isPrivate' => false]);
		$this->agentAccess->method('loadAccessibleAgent')->willReturnCallback(
			static fn (string $agentId, string $userId): ?ObjectEntity => ($agentId === $uuid ? $agent : null)
		);
		return $agent;
	}//end readableAgent()

	/**
	 * Build the controller wired to the current mocks.
	 *
	 * @return SessionController
	 */
	private function controller(): SessionController {
		// TalkSessionRoom is mocked, not exercised: a bare double returns the
		// session unchanged from attachToSession(), which is exactly the
		// no-Talk path these CRUD assertions are written against. The room
		// behaviour itself is proven live, against a real spreed.
		$sessionRoom = $this->createMock(TalkSessionRoom::class);
		$sessionRoom->method('attachToSession')->willReturnArgument(0);

		return new SessionController(
			request: $this->request,
			engine: $this->engine,
			objectService: $this->objectService,
			userSession: $this->userSession,
			sessionRoom: $sessionRoom,
			logger: $this->createMock(originalClassName: LoggerInterface::class),
			agentAccess: $this->agentAccess
		);

	}//end controller()

	/**
	 * Build a conversation ObjectEntity fixture.
	 *
	 * @param string $uuid The object UUID.
	 * @param array<string,mixed> $payload The object payload.
	 *
	 * @return ObjectEntity
	 */
	private function conversation(string $uuid, array $payload): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setObject($payload);
		return $entity;
	}//end conversation()

	/**
	 * index() default lists only active conversations; _deleted=true lists
	 * only archived ones (metadata.deletedAt partition).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agent-engine-port/tasks.md#task-4-1
	 */
	public function testIndexPartitionsActiveAndArchived(): void {
		$active = $this->conversation('conv-active', ['userId' => 'alice', 'agentId' => 'a1', 'title' => 'Active']);
		$archived = $this->conversation(
			'conv-archived',
			[
				'userId' => 'alice',
				'agentId' => 'a1',
				'title' => 'Archived',
				'metadata' => ['deletedAt' => '2026-07-01T00:00:00+00:00', 'deletedBy' => 'alice'],
			]
		);
		$this->objectService->method('findAll')->willReturn([$active, $archived]);

		// Default: active only.
		$this->request->method('getParams')->willReturn([]);
		$response = $this->controller()->index();
		$this->assertSame(200, $response->getStatus());
		$this->assertSame(1, $response->getData()['total']);
		$this->assertSame('conv-active', $response->getData()['results'][0]['uuid']);
		$this->assertNull($response->getData()['results'][0]['deletedAt']);

	}//end testIndexPartitionsActiveAndArchived()

	/**
	 * A serialized session carries its trigger origin, and a session that
	 * predates the property is reported as `human` rather than as nothing.
	 *
	 * Both halves matter: the session list groups on this field, so a filter
	 * that returned everything would pass the first assertion on its own.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/session-frontend-rename/specs/session-surface/spec.md#requirement-human-and-automated-sessions-must-be-listed-separately
	 */
	public function testIndexReportsTriggerOriginAndDefaultsItToHuman(): void {
		$automated = $this->conversation(
			uuid: 'conv-cron',
			payload: ['userId' => 'alice', 'agentId' => 'a1', 'title' => 'Nightly', 'triggerOrigin' => 'cron']
		);
		$legacy = $this->conversation(uuid: 'conv-legacy', payload: ['userId' => 'alice', 'agentId' => 'a1', 'title' => 'Old']);
		$this->objectService->method('findAll')->willReturn([$automated, $legacy]);
		$this->request->method('getParams')->willReturn([]);

		$results = $this->controller()->index()->getData()['results'];
		$origins = array_combine(array_column($results, 'uuid'), array_column($results, 'triggerOrigin'));

		$this->assertSame(expected: 'cron', actual: $origins['conv-cron'], message: 'A stored origin must be reported verbatim.');
		$this->assertSame(expected: 'human', actual: $origins['conv-legacy'], message: 'A session with no origin was started by a person.');

	}//end testIndexReportsTriggerOriginAndDefaultsItToHuman()


	/**
	 * index() with _deleted=true returns only the archived conversations.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agent-engine-port/tasks.md#task-4-1
	 */
	public function testIndexDeletedFilterReturnsArchivedOnly(): void {
		$active = $this->conversation('conv-active', ['userId' => 'alice', 'agentId' => 'a1', 'title' => 'Active']);
		$archived = $this->conversation(
			'conv-archived',
			[
				'userId' => 'alice',
				'agentId' => 'a1',
				'title' => 'Archived',
				'metadata' => ['deletedAt' => '2026-07-01T00:00:00+00:00', 'deletedBy' => 'alice'],
			]
		);
		$this->objectService->method('findAll')->willReturn([$active, $archived]);
		$this->request->method('getParams')->willReturn(['_deleted' => 'true']);

		$response = $this->controller()->index();

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(1, $response->getData()['total']);
		$this->assertSame('conv-archived', $response->getData()['results'][0]['uuid']);
		$this->assertSame('2026-07-01T00:00:00+00:00', $response->getData()['results'][0]['deletedAt']);

	}//end testIndexDeletedFilterReturnsArchivedOnly()

	/**
	 * show() is 404 for a missing conversation and 403 for a foreign one
	 * (gate-7); the owner gets the payload plus messageCount from the
	 * paginated total.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agent-engine-port/tasks.md#task-4-1
	 */
	public function testShowGuardsAndReturnsMessageCount(): void {
		$this->request->method('getParams')->willReturn([]);

		// Missing → 404.
		$this->objectService->method('find')->willReturnOnConsecutiveCalls(
			null,
			$this->conversation('conv-bob', ['userId' => 'bob', 'agentId' => 'a1']),
			$this->conversation('conv-1', ['userId' => 'alice', 'agentId' => 'a1', 'title' => 'Mine'])
		);
		$this->objectService->method('searchObjectsPaginated')->willReturn(['results' => [], 'total' => 3]);

		$controller = $this->controller();

		$this->assertSame(404, $controller->show('ghost')->getStatus());

		// Foreign → 403.
		$this->assertSame(403, $controller->show('conv-bob')->getStatus());

		// Own → 200 with messageCount.
		$response = $controller->show('conv-1');
		$this->assertSame(200, $response->getStatus());
		$this->assertSame('Mine', $response->getData()['title']);
		$this->assertSame(3, $response->getData()['messageCount']);

	}//end testShowGuardsAndReturnsMessageCount()

	/**
	 * messages() enforces the ownership guard and returns the page plus the
	 * paginated total.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agent-engine-port/tasks.md#task-4-1
	 */
	public function testMessagesReturnsPageAndTotal(): void {
		$this->request->method('getParams')->willReturn(['limit' => 10]);
		$this->objectService->method('find')->willReturn(
			$this->conversation('conv-1', ['userId' => 'alice', 'agentId' => 'a1'])
		);

		$message = new ObjectEntity();
		$message->setUuid('msg-1');
		$message->setObject(['sessionId' => 'conv-1', 'role' => 'user', 'content' => 'hi']);
		$this->objectService->method('findAll')->willReturn([$message]);
		$this->objectService->method('searchObjectsPaginated')->willReturn(['results' => [], 'total' => 1]);

		$response = $this->controller()->messages('conv-1');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(1, $response->getData()['total']);
		$this->assertSame('msg-1', $response->getData()['results'][0]['uuid']);

	}//end testMessagesReturnsPageAndTotal()

	/**
	 * create() persists userId/agentId/title/metadata and generates a unique
	 * title via the engine when none is provided.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agent-engine-port/tasks.md#task-4-1
	 */
	public function testCreatePersistsConversationWithGeneratedTitle(): void {
		$this->request->method('getParams')->willReturn(['agentId' => 'agent-1']);
		$this->readableAgent(uuid: 'agent-1');
		$this->engine->expects($this->once())->method('ensureUniqueTitle')->willReturn('New Conversation 2');

		$saved = null;
		$this->objectService->method('saveObject')->willReturnCallback(
			function (mixed $object) use (&$saved): ObjectEntity {
				$saved = $object;
				$entity = new ObjectEntity();
				$entity->setUuid('conv-new');
				$entity->setObject($object);
				return $entity;
			}
		);

		$response = $this->controller()->create();

		$this->assertSame(201, $response->getStatus());
		$this->assertSame('alice', $saved['userId']);
		$this->assertSame('agent-1', $saved['agentId']);
		$this->assertSame('New Conversation 2', $saved['title']);
		$this->assertSame('conv-new', $response->getData()['uuid']);

	}//end testCreatePersistsConversationWithGeneratedTitle()

	/**
	 * An agent the caller cannot use (unknown, or private and not shared) is a 404
	 * and nothing is saved (hermiq#1086).
	 *
	 * This replaces the old "unknown agentUuid leaves the session unbound" contract.
	 * That 201 only existed in the mock: the session schema requires `agentId`, so in
	 * production the unbound save failed validation and surfaced as a 500. Now that
	 * the create runs past OpenRegister's create check (see the test below), the
	 * controller is the guard and refuses before it writes anything. A private agent
	 * answers exactly like a missing one, so a non-owner cannot confirm it exists.
	 *
	 * @param array<string, string> $params The request parameters.
	 *
	 * @return void
	 *
	 * @dataProvider unusableAgents
	 *
	 * @spec openspec/changes/agent-engine-port/tasks.md#task-4-1
	 */
	public function testCreateRefusesAnAgentTheCallerCannotUse(array $params): void {
		$this->request->method('getParams')->willReturn($params);
		$this->agentAccess->method('loadAccessibleAgent')->willReturn(null);
		$this->objectService->expects($this->never())->method('saveObject');

		$response = $this->controller()->create();

		$this->assertSame(404, $response->getStatus());
		$this->assertSame('Agent not found', $response->getData()['error']);
		$this->assertNotEmpty($response->getData()['message']);

	}//end testCreateRefusesAnAgentTheCallerCannotUse()

	/**
	 * Both ways a create names its agent.
	 *
	 * @return array<string, array{0: array<string, string>}>
	 */
	public static function unusableAgents(): array {
		return [
			'unknown agentUuid' => [['agentUuid' => 'agent-missing']],
			'private agentId' => [['agentId' => 'agent-private']],
		];
	}//end unusableAgents()

	/**
	 * A create without any agent is a 400 with a message, not the schema's 500.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agent-engine-port/tasks.md#task-4-1
	 */
	public function testCreateWithoutAnAgentIsAClientError(): void {
		$this->request->method('getParams')->willReturn(['title' => 'No agent']);
		$this->objectService->expects($this->never())->method('saveObject');

		$response = $this->controller()->create();

		$this->assertSame(400, $response->getStatus());
		$this->assertNotEmpty($response->getData()['message']);

	}//end testCreateWithoutAnAgentIsAClientError()

	/**
	 * A user who is not an admin can start a session (hermiq#1086).
	 *
	 * The Session schema grants `read` only, owner-scoped, and deliberately lists no
	 * `create` (PrivateSchemaReadRulesTest::testWriteActionsStayOmitted, hermiq#319).
	 * OpenRegister therefore refuses `create` to every non-admin on the default
	 * `_rbac: true` path, which is the 500 in #1086. The controller is the guard
	 * instead: it has resolved the caller and checked the agent, so it saves with
	 * `_rbac: false`, as GoalService::set() does for the owner-scoped Goal schema.
	 * OpenRegister still stamps `_owner` from the user session, so the owner-only
	 * read and the owner admit on later updates keep working.
	 *
	 * The session is always the caller's: a `userId` in the request is ignored, so
	 * this path cannot plant a session in somebody else's list.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agent-engine-port/tasks.md#task-4-1
	 */
	public function testANonAdminCreatesTheirOwnSessionPastTheObjectApiCreateCheck(): void {
		$this->request->method('getParams')->willReturn(['agentUuid' => 'agent-1', 'userId' => 'bob', 'title' => 'Mine']);
		$this->readableAgent(uuid: 'agent-1');

		$args = null;
		$this->objectService->expects($this->once())->method('saveObject')->willReturnCallback(
			function (mixed ...$passed) use (&$args): ObjectEntity {
				$args = $passed;
				$entity = new ObjectEntity();
				$entity->setUuid('conv-new');
				$entity->setObject($passed[0]);
				return $entity;
			}
		);

		$response = $this->controller()->create();

		$this->assertSame(201, $response->getStatus());
		$this->assertSame('alice', $args[0]['userId'], 'The session belongs to the caller, never to a userId in the request.');
		$this->assertSame('agent-1', $args[0]['agentId']);
		$this->assertSame('agentsession', $args[3]);
		$this->assertFalse($args[5], 'The create must not depend on an object-API create grant the schema does not give (_rbac).');
		$this->assertTrue($args[6] ?? true, 'Multitenancy stays on, so the session gets the caller\'s organisation.');

	}//end testANonAdminCreatesTheirOwnSessionPastTheObjectApiCreateCheck()

	/**
	 * A failed create answers with the reason, and a 4xx code keeps its status.
	 *
	 * @param int $code The exception code the save throws with.
	 * @param int $status The status the response must carry.
	 *
	 * @return void
	 *
	 * @dataProvider failedSaves
	 *
	 * @spec openspec/changes/agent-engine-port/tasks.md#task-4-1
	 */
	public function testAFailedCreateCarriesItsReasonAndStatus(int $code, int $status): void {
		$this->request->method('getParams')->willReturn(['agentId' => 'agent-1', 'title' => 'x']);
		$this->readableAgent(uuid: 'agent-1');
		$this->objectService->method('saveObject')->willThrowException(new Exception('The save was refused', $code));

		$response = $this->controller()->create();

		$this->assertSame($status, $response->getStatus());
		$this->assertSame('Failed to create conversation', $response->getData()['error']);
		$this->assertSame('The save was refused', $response->getData()['message']);

	}//end testAFailedCreateCarriesItsReasonAndStatus()

	/**
	 * Exception codes and the status each one answers with.
	 *
	 * @return array<string, array{0: int, 1: int}>
	 */
	public static function failedSaves(): array {
		return [
			'a 4xx code keeps its status' => [422, 422],
			'a refusal keeps its 403' => [403, 403],
			'no code is a server error' => [0, 500],
			'a non-HTTP code is a server error' => [23000, 500],
		];
	}//end failedSaves()

	/**
	 * update() refuses a foreign conversation (403, gate-7) and, for the
	 * owner, only applies title/metadata — request attempts to change
	 * userId/agentId are ignored.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agent-engine-port/tasks.md#task-4-1
	 */
	public function testUpdateGuardsOwnershipAndImmutableFields(): void {
		$this->request->method('getParams')->willReturn(
			[
				'title' => 'Renamed',
				'userId' => 'mallory',
				'agentId' => 'evil-agent',
			]
		);

		$this->objectService->method('find')->willReturnOnConsecutiveCalls(
			$this->conversation('conv-bob', ['userId' => 'bob', 'agentId' => 'a1']),
			$this->conversation('conv-1', ['userId' => 'alice', 'agentId' => 'a1', 'title' => 'Old'])
		);

		$saved = null;
		$this->objectService->method('saveObject')->willReturnCallback(
			function (mixed $object) use (&$saved): ObjectEntity {
				$saved = $object;
				$entity = new ObjectEntity();
				$entity->setUuid('conv-1');
				$entity->setObject($object);
				return $entity;
			}
		);

		$controller = $this->controller();

		// Foreign → 403, nothing saved.
		$this->assertSame(403, $controller->update('conv-bob')->getStatus());
		$this->assertNull($saved);

		// Owner → title applied, immutables preserved.
		$response = $controller->update('conv-1');
		$this->assertSame(200, $response->getStatus());
		$this->assertSame('Renamed', $saved['title']);
		$this->assertSame('alice', $saved['userId'], 'userId must never be taken from the request.');
		$this->assertSame('a1', $saved['agentId'], 'agentId must never be taken from the request.');

	}//end testUpdateGuardsOwnershipAndImmutableFields()

	/**
	 * destroy() on an active conversation archives it (metadata.deletedAt
	 * marker, no hard delete).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agent-engine-port/tasks.md#task-4-1
	 */
	public function testDestroyArchivesActiveConversation(): void {
		$this->request->method('getParams')->willReturn([]);
		$this->objectService->method('find')->willReturn(
			$this->conversation('conv-1', ['userId' => 'alice', 'agentId' => 'a1'])
		);
		$this->objectService->expects($this->never())->method('deleteObject');

		$saved = null;
		$this->objectService->method('saveObject')->willReturnCallback(
			function (mixed $object) use (&$saved): ObjectEntity {
				$saved = $object;
				return new ObjectEntity();
			}
		);

		$response = $this->controller()->destroy('conv-1');

		$this->assertSame(200, $response->getStatus());
		$this->assertTrue($response->getData()['archived']);
		$this->assertNotEmpty($saved['metadata']['deletedAt']);
		$this->assertSame('alice', $saved['metadata']['deletedBy']);

	}//end testDestroyArchivesActiveConversation()

	/**
	 * destroy() on an already-archived conversation deletes it permanently:
	 * related feedback + messages first, then the conversation itself, all
	 * via ObjectService::deleteObject().
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agent-engine-port/tasks.md#task-4-1
	 */
	public function testDestroyArchivedConversationDeletesPermanently(): void {
		$this->request->method('getParams')->willReturn([]);
		$this->objectService->method('find')->willReturn(
			$this->conversation(
				'conv-1',
				[
					'userId' => 'alice',
					'agentId' => 'a1',
					'metadata' => ['deletedAt' => '2026-07-01T00:00:00+00:00'],
				]
			)
		);

		$related = new ObjectEntity();
		$related->setUuid('rel-1');
		$related->setObject(['sessionId' => 'conv-1']);
		$this->objectService->method('findAll')->willReturn([$related]);
		$this->objectService->expects($this->never())->method('saveObject');

		$deleted = [];
		$this->objectService->method('deleteObject')->willReturnCallback(
			function (string $uuid, mixed $register = null, mixed $schema = null) use (&$deleted): bool {
				$deleted[] = [$uuid, $schema];
				return true;
			}
		);

		$response = $this->controller()->destroy('conv-1');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(
			[
				['rel-1', 'feedback'],
				['rel-1', 'agentsessionturn'],
				['conv-1', 'agentsession'],
			],
			$deleted,
			'Feedback, then messages, then the conversation must be deleted (OR ordering).'
		);

	}//end testDestroyArchivedConversationDeletesPermanently()

	/**
	 * restore() clears the archive marker (metadata collapses back to null
	 * when no other keys remain).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agent-engine-port/tasks.md#task-4-1
	 */
	public function testRestoreClearsArchiveMarker(): void {
		$this->request->method('getParams')->willReturn([]);
		$this->objectService->method('find')->willReturn(
			$this->conversation(
				'conv-1',
				[
					'userId' => 'alice',
					'agentId' => 'a1',
					'metadata' => [
						'deletedAt' => '2026-07-01T00:00:00+00:00',
						'deletedBy' => 'alice',
					],
				]
			)
		);

		$saved = null;
		$this->objectService->method('saveObject')->willReturnCallback(
			function (mixed $object) use (&$saved): ObjectEntity {
				$saved = $object;
				$entity = new ObjectEntity();
				$entity->setUuid('conv-1');
				$entity->setObject($object);
				return $entity;
			}
		);

		$response = $this->controller()->restore('conv-1');

		$this->assertSame(200, $response->getStatus());
		$this->assertNull($saved['metadata'], 'The emptied metadata object must collapse to null.');
		$this->assertNull($response->getData()['deletedAt']);

	}//end testRestoreClearsArchiveMarker()

	/**
	 * destroyPermanent() deletes the messages and the conversation via
	 * ObjectService::deleteObject() (feedback is untouched, mirroring OR).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agent-engine-port/tasks.md#task-4-1
	 */
	public function testDestroyPermanentDeletesMessagesThenConversation(): void {
		$this->request->method('getParams')->willReturn([]);
		$this->objectService->method('find')->willReturn(
			$this->conversation('conv-1', ['userId' => 'alice', 'agentId' => 'a1'])
		);

		$message = new ObjectEntity();
		$message->setUuid('msg-1');
		$message->setObject(['sessionId' => 'conv-1']);
		$this->objectService->method('findAll')->willReturn([$message]);

		$deleted = [];
		$this->objectService->method('deleteObject')->willReturnCallback(
			function (string $uuid, mixed $register = null, mixed $schema = null) use (&$deleted): bool {
				$deleted[] = [$uuid, $schema];
				return true;
			}
		);

		$response = $this->controller()->destroyPermanent('conv-1');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(
			[
				['msg-1', 'agentsessionturn'],
				['conv-1', 'agentsession'],
			],
			$deleted
		);

	}//end testDestroyPermanentDeletesMessagesThenConversation()

	/**
	 * Gate-7: a foreign conversation can be neither destroyed, restored, nor
	 * permanently deleted — every path is 403 with no write.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agent-engine-port/tasks.md#task-4-1
	 */
	public function testForeignConversationLifecycleIsForbidden(): void {
		$this->request->method('getParams')->willReturn([]);
		$this->objectService->method('find')->willReturn(
			$this->conversation('conv-bob', ['userId' => 'bob', 'agentId' => 'a1'])
		);
		$this->objectService->expects($this->never())->method('saveObject');
		$this->objectService->expects($this->never())->method('deleteObject');

		$controller = $this->controller();

		$this->assertSame(403, $controller->destroy('conv-bob')->getStatus());
		$this->assertSame(403, $controller->restore('conv-bob')->getStatus());
		$this->assertSame(403, $controller->destroyPermanent('conv-bob')->getStatus());

	}//end testForeignConversationLifecycleIsForbidden()
}//end class

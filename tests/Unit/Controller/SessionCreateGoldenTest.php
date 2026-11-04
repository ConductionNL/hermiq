<?php

/**
 * Golden test for SessionController::create().
 *
 * Runs the session start over generated callers (signed out, signed in),
 * request bodies (agentId or agentUuid, missing agent, title given or not,
 * metadata in several shapes), agent lookups the caller may or may not use,
 * and saves or room attachments that fail, and compares the response, every
 * collaborator call and the log lines with a recorded fixture. Source positions
 * (`file`, `line`, `trace`) are left out of the log record. Set GOLDEN_RECORD=1
 * to (re)write the fixture; a run in record mode compares nothing.
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
use OCA\Hermiq\Service\Chat\SessionCollaborators;
use OCA\Hermiq\Service\Chat\SessionPendingApprovals;
use OCA\Hermiq\Service\Chat\SessionSubject;
use OCA\Hermiq\Service\AgentAccessService;
use OCA\Hermiq\Service\Engine\Engine;
use OCA\Hermiq\Service\Talk\TalkSessionRoom;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionMethod;
use RuntimeException;
use Stringable;

/**
 * Golden comparison of the session start.
 *
 * @covers \OCA\Hermiq\Controller\SessionController
 * @uses   \OCA\Hermiq\Service\Chat\SessionCollaborators
 * @uses   \OCA\Hermiq\Service\Chat\SessionSubject
 * @uses   \OCA\Hermiq\Service\Talk\ConversationParticipation
 *
 * @spec openspec/changes/agent-engine-port/tasks.md#task-4-1
 */
class SessionCreateGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/../../Fixtures/Golden/session-create.json';

	private const CASES = 300;

	/**
	 * The seeded generator of the current case.
	 *
	 * @var Randomizer
	 */
	private Randomizer $random;

	/**
	 * Every generated case gives the recorded response, calls and log.
	 *
	 * @return void
	 */
	public function testCreateMatchesTheRecordedFixture(): void {
		$actual = [];
		for ($case = 0; $case < self::CASES; $case++) {
			$this->random = new Randomizer(new Mt19937($case + 1));
			$calls = [];
			$logger = $this->logger();
			$params = $this->params();
			$caller = $this->pick(pool: [null, 'alice', 'alice', 'alice', 'bob']);
			$controller = $this->controller(caller: $caller, params: $params, calls: $calls, logger: $logger);
			$response = $controller->create();

			$actual[] = [
				'caller' => $caller,
				'params' => $params,
				'status' => $response->getStatus(),
				'data' => $response->getData(),
				'calls' => $calls,
				'log' => $logger->lines,
			];
		}

		if (getenv('GOLDEN_RECORD') === '1') {
			if (is_dir(dirname(self::FIXTURE)) === false) {
				mkdir(dirname(self::FIXTURE), 0777, true);
			}

			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
			$this->markTestSkipped(message: 'Fixture recorded.');
		}

		$expected = json_decode((string)file_get_contents(self::FIXTURE), true, 512, JSON_THROW_ON_ERROR);
		$this->assertCount(expectedCount: count($expected), haystack: $actual);
		foreach ($expected as $index => $one) {
			$this->assertSame(
				expected: json_encode($one, JSON_THROW_ON_ERROR),
				actual: json_encode($actual[$index], JSON_THROW_ON_ERROR),
				message: 'case ' . $index
			);
		}

	}//end testCreateMatchesTheRecordedFixture()

	/**
	 * A generated request body.
	 *
	 * @return array<string, mixed>
	 */
	private function params(): array {
		$params = [
			'agentId' => $this->pick(pool: ['ag1', 'ag1', 'ag-private', '', null]),
			'agentUuid' => $this->pick(pool: ['ag1', 'ag2', null]),
			'title' => $this->pick(pool: ['My chat', null, '', 12]),
			'metadata' => $this->pick(pool: [['topic' => 'x'], [], 'loose', null]),
			'userId' => 'mallory',
		];
		foreach (array_keys($params) as $key) {
			if ($this->random->getInt(0, 3) === 0) {
				unset($params[$key]);
			}
		}

		return $params;

	}//end params()

	/**
	 * The controller over a generated world, recording each collaborator call.
	 *
	 * @param string|null $caller The signed-in uid, or null when signed out.
	 * @param array<string, mixed> $params The request body.
	 * @param array<int, mixed> $calls The call log, appended to.
	 * @param AbstractLogger $logger The recording logger.
	 *
	 * @return SessionController
	 */
	private function controller(?string $caller, array $params, array &$calls, AbstractLogger $logger): SessionController {
		$session = $this->createMock(originalClassName: IUserSession::class);
		$user = null;
		if ($caller !== null) {
			$user = $this->createMock(originalClassName: IUser::class);
			$user->method('getUID')->willReturn($caller);
		}

		$session->method('getUser')->willReturn($user);

		$request = $this->createMock(originalClassName: IRequest::class);
		$request->method('getPathInfo')->willReturn('/apps/hermiq/api/sessions');
		$request->method('getParams')->willReturn($params);

		$access = $this->createMock(originalClassName: AgentAccessService::class);
		$access->method('loadAccessibleAgent')->willReturnCallback(
			function (string $agentId, string $userId) use (&$calls): ?ObjectEntity {
				$calls[] = ['loadAccessibleAgent', $agentId, $userId];
				if ($agentId === 'ag-private' && $userId !== 'bob') {
					return null;
				}

				$agent = new ObjectEntity();
				$agent->setUuid('uuid-' . $agentId);
				$agent->setObject(['name' => 'Agent']);
				return $agent;
			}
		);

		$engine = $this->createMock(originalClassName: Engine::class);
		$engine->method('ensureUniqueTitle')->willReturnCallback(
			function (string $baseTitle, string $userId, string $agentId) use (&$calls): string {
				$calls[] = ['ensureUniqueTitle', $baseTitle, $userId, $agentId];
				return $baseTitle . ' (2)';
			}
		);

		$failSave = $this->pick(pool: ['', '', '', '', '', '', '', 'runtime', 'forbidden']);
		$objectService = $this->createMock(originalClassName: ObjectService::class);
		$objectService->method('saveObject')->willReturnCallback(
			function (mixed ...$args) use (&$calls, $failSave): ObjectEntity {
				$passed = $this->passed(args: $args);
				$calls[] = ['saveObject', $passed];
				if ($failSave === 'runtime') {
					throw new RuntimeException('save refused');
				}

				if ($failSave === 'forbidden') {
					throw new Exception('not allowed', 403);
				}

				$saved = new ObjectEntity();
				$saved->setUuid('conv-1');
				$saved->setOrganisation('org1');
				$saved->setObject($passed['object']);
				return $saved;
			}
		);

		$roomFails = ($this->random->getInt(0, 9) === 0);
		$room = $this->createMock(originalClassName: TalkSessionRoom::class);
		$room->method('attachToSession')->willReturnCallback(
			function (ObjectEntity $conversation, string $title, string $ownerUid, string $agentId) use (&$calls, $roomFails): ObjectEntity {
				$calls[] = ['attachToSession', $conversation->getUuid(), $title, $ownerUid, $agentId];
				if ($roomFails === true) {
					throw new RuntimeException('talk down');
				}

				$object = $conversation->getObject();
				$object['talkRoomToken'] = 'tok';
				$conversation->setObject($object);
				return $conversation;
			}
		);

		return new SessionController(
			request: $request,
			engine: $engine,
			objectService: $objectService,
			userSession: $session,
			logger: $logger,
			collaborators: new SessionCollaborators(
				sessionRoom: $room,
				agentAccess: $access,
				subject: new SessionSubject(objectService: $objectService),
				pendingApprovals: $this->createMock(SessionPendingApprovals::class)
			)
		);

	}//end controller()

	/**
	 * A logger that keeps its lines without source positions.
	 *
	 * @return AbstractLogger
	 */
	private function logger(): AbstractLogger {
		return new class extends AbstractLogger {
			/**
			 * Recorded log lines.
			 *
			 * @var array<int, mixed>
			 */
			public array $lines = [];

			/**
			 * Record one line.
			 *
			 * @param mixed $level The level.
			 * @param string|Stringable $message The message.
			 * @param array<string, mixed> $context The context.
			 *
			 * @return void
			 */
			public function log($level, string|Stringable $message, array $context = []): void {
				unset($context['file'], $context['line'], $context['trace']);
				$this->lines[] = [$level, (string)$message, $context];
			}//end log()
		};

	}//end logger()

	/**
	 * One value from a pool.
	 *
	 * @param array<int, mixed> $pool The pool.
	 *
	 * @return mixed
	 */
	private function pick(array $pool): mixed {
		return $pool[$this->random->getInt(0, count($pool) - 1)];

	}//end pick()

	/**
	 * The arguments a saveObject call actually passed, by name, without the ones left at their default.
	 *
	 * @param array<int, mixed> $args The positional arguments the mock received.
	 *
	 * @return array<string, mixed>
	 */
	private function passed(array $args): array {
		$out = [];
		foreach ((new ReflectionMethod(ObjectService::class, 'saveObject'))->getParameters() as $index => $param) {
			if (array_key_exists($index, $args) === false) {
				continue;
			}

			if ($param->isDefaultValueAvailable() === true && $param->getDefaultValue() === $args[$index]) {
				continue;
			}

			$out[$param->getName()] = $args[$index];
		}

		return $out;

	}//end passed()
}//end class

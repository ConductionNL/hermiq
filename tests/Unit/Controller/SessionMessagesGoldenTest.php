<?php

/**
 * Golden test for SessionController::messages().
 *
 * Runs the turn list over generated callers (signed out, owner, listed
 * participant, stranger), lookups (missing, failing, sessions with rosters in
 * several shapes), paging parameters under both names, turn lists with odd
 * entries, and reads that fail, and compares the response, every collaborator
 * call and the log lines with a recorded fixture. Source positions (`file`,
 * `line`, `trace`) are left out of the log record. Set GOLDEN_RECORD=1 to
 * (re)write the fixture; a run in record mode compares nothing.
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

use OCA\Hermiq\Controller\SessionController;
use OCA\Hermiq\Service\Chat\SessionCollaborators;
use OCA\Hermiq\Service\Chat\SessionPendingApprovals;
use OCA\Hermiq\Service\Chat\SessionSubject;
use OCA\Hermiq\Service\AgentAccessService;
use OCA\Hermiq\Service\Engine\Engine;
use OCA\Hermiq\Service\Talk\TalkSessionRoom;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
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
 * Golden comparison of the turn list.
 *
 * @covers \OCA\Hermiq\Controller\SessionController
 * @uses   \OCA\Hermiq\Service\Chat\SessionCollaborators
 * @uses   \OCA\Hermiq\Service\Chat\SessionSubject
 * @uses   \OCA\Hermiq\Service\Talk\ConversationParticipation
 *
 * @spec openspec/changes/agent-engine-port/tasks.md#task-4-1
 */
class SessionMessagesGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/../../Fixtures/Golden/session-messages.json';

	private const CASES = 250;

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
	public function testMessagesMatchTheRecordedFixture(): void {
		$actual = [];
		for ($case = 0; $case < self::CASES; $case++) {
			$this->random = new Randomizer(new Mt19937($case + 1));
			$calls = [];
			$logger = $this->logger();
			$caller = $this->pick(pool: [null, 'alice', 'alice', 'alice', 'carol', 'bob']);
			$params = $this->params();
			$controller = $this->controller(caller: $caller, params: $params, calls: $calls, logger: $logger);
			$response = $controller->messages(uuid: 's' . ($case % 3));

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

	}//end testMessagesMatchTheRecordedFixture()

	/**
	 * Generated paging parameters under both names.
	 *
	 * @return array<string, mixed>
	 */
	private function params(): array {
		$params = [
			'limit' => $this->pick(pool: [10, '5', null, 'x']),
			'_limit' => $this->pick(pool: [20, null]),
			'offset' => $this->pick(pool: [0, 3, '2', null]),
			'_offset' => $this->pick(pool: [1, null]),
		];
		foreach (array_keys($params) as $key) {
			if ($this->random->getInt(0, 2) === 0) {
				unset($params[$key]);
			}
		}

		return $params;

	}//end params()

	/**
	 * The controller over a generated world, recording each collaborator call.
	 *
	 * @param string|null $caller The signed-in uid, or null when signed out.
	 * @param array<string, mixed> $params The query parameters.
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
		$request->method('getPathInfo')->willReturn('/apps/hermiq/api/sessions/s1/messages');
		$request->method('getParams')->willReturn($params);

		$conversation = $this->conversation();
		$turns = $this->turns();
		$failRead = $this->pick(pool: ['', '', '', '', '', 'findAll', 'count']);
		$total = $this->random->getInt(0, 40);
		$schema = '';

		$objectService = $this->createMock(originalClassName: ObjectService::class);
		$objectService->method('find')->willReturnCallback(
			function (mixed ...$args) use (&$calls, $conversation): mixed {
				$calls[] = ['find', $this->passed(method: 'find', args: $args)];
				if ($conversation === 'throws') {
					throw new DoesNotExistException('no such object');
				}

				return $conversation;
			}
		);
		$objectService->method('setRegister')->willReturnCallback(
			function (string $register) use (&$calls, $objectService): ObjectService {
				$calls[] = ['setRegister', $register];
				return $objectService;
			}
		);
		$objectService->method('setSchema')->willReturnCallback(
			function (string $slug) use (&$calls, &$schema, $objectService): ObjectService {
				$calls[] = ['setSchema', $slug];
				$schema = $slug;
				return $objectService;
			}
		);
		$objectService->method('findAll')->willReturnCallback(
			function (mixed ...$args) use (&$calls, &$schema, $turns, $failRead): array {
				$calls[] = ['findAll', $schema, $this->passed(method: 'findAll', args: $args)];
				if ($failRead === 'findAll') {
					throw new RuntimeException('turn read failed');
				}

				return $turns;
			}
		);
		$objectService->method('searchObjectsPaginated')->willReturnCallback(
			function (mixed ...$args) use (&$calls, &$schema, $failRead, $total): array {
				$calls[] = ['searchObjectsPaginated', $schema, $this->passed(method: 'searchObjectsPaginated', args: $args)];
				if ($failRead === 'count') {
					throw new RuntimeException('count failed');
				}

				return ['results' => [], 'total' => $total];
			}
		);

		$sessionRoom = $this->createMock(originalClassName: TalkSessionRoom::class);

		return new SessionController(
			request: $request,
			engine: $this->createMock(originalClassName: Engine::class),
			objectService: $objectService,
			userSession: $session,
			logger: $logger,
			collaborators: new SessionCollaborators(
				sessionRoom: $sessionRoom,
				agentAccess: $this->createMock(originalClassName: AgentAccessService::class),
				subject: new SessionSubject(objectService: $objectService),
				pendingApprovals: $this->createMock(SessionPendingApprovals::class)
			)
		);

	}//end controller()

	/**
	 * The lookup's answer: nothing, a failing lookup, or a session with a roster.
	 *
	 * @return mixed
	 */
	private function conversation(): mixed {
		$kind = $this->random->getInt(0, 9);
		if ($kind === 0) {
			return null;
		}

		if ($kind === 1) {
			return 'throws';
		}

		$payload = [
			'userId' => $this->pick(pool: ['alice', 'alice', 'bob', null]),
			'participants' => $this->pick(pool: [['carol'], ['carol', ''], 'carol', [], null, [['uid' => 'carol']]]),
		];

		$entity = new ObjectEntity();
		$entity->setUuid('s-entity');
		$entity->setObject($payload);
		return $entity;

	}//end conversation()

	/**
	 * The stored turns: objects with several payload shapes and the odd non-object.
	 *
	 * @return array<int, mixed>
	 */
	private function turns(): array {
		$out = [];
		for ($i = $this->random->getInt(0, 4); $i > 0; $i--) {
			if ($this->random->getInt(0, 7) === 0) {
				$out[] = ['not' => 'an entity'];
				continue;
			}

			$payload = $this->pick(
				pool: [
					['sessionId' => 's1', 'role' => 'user', 'content' => 'q' . $i, 'authorId' => 'carol'],
					['conversationId' => 's1', 'role' => 'assistant', 'content' => 'a' . $i, 'sources' => [['id' => 1]]],
					['role' => 'tool'],
				]
			);
			$entity = new ObjectEntity();
			$entity->setUuid('t' . $i);
			$entity->setObject($payload);
			$out[] = $entity;
		}

		return $out;

	}//end turns()

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
	 * The arguments an ObjectService call actually passed, by name, without the ones left at their default.
	 *
	 * @param string $method The method name.
	 * @param array<int, mixed> $args The positional arguments the mock received.
	 *
	 * @return array<string, mixed>
	 */
	private function passed(string $method, array $args): array {
		$out = [];
		foreach ((new ReflectionMethod(ObjectService::class, $method))->getParameters() as $index => $param) {
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

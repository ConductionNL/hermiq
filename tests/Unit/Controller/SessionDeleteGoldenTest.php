<?php

/**
 * Golden test for SessionController::destroy() and destroyPermanent().
 *
 * Runs both deletes over generated callers (signed out, owner, another user),
 * lookups (missing, failing, owned, someone else's), archive markers in every
 * shape, related turns and ratings, and writes or deletes that fail, and
 * compares the response, every collaborator call and the log lines with a
 * recorded fixture. Source positions (`file`, `line`, `trace`) are left out of
 * the log record, and the archive time is compared as "now". Set
 * GOLDEN_RECORD=1 to (re)write the fixture; a run in record mode compares
 * nothing.
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
 * Golden comparison of the two session deletes.
 *
 * @covers \OCA\Hermiq\Controller\SessionController
 * @uses   \OCA\Hermiq\Service\Chat\SessionCollaborators
 * @uses   \OCA\Hermiq\Service\Chat\SessionSubject
 *
 * @spec openspec/changes/agent-engine-port/tasks.md#task-4-1
 */
class SessionDeleteGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/../../Fixtures/Golden/session-delete.json';

	private const CASES = 200;

	private const CALLERS = [null, 'alice', 'alice', 'alice', 'alice', 'alice', 'bob'];

	private const MARKERS = [
		null, null, 'not a block', [], ['deletedAt' => ''], ['deletedAt' => '2026-10-01T10:00:00+00:00'],
		['deletedAt' => '2026-10-01T10:00:00+00:00', 'deletedBy' => 'alice'], ['deletedAt' => 0], ['other' => 1],
		['deletedAt' => '2026-10-02T08:00:00+00:00'], ['deletedAt' => 'yes'],
	];

	private const PATHS = ['/apps/hermiq/api/sessions/s1', '/apps/hermiq/api/conversations/s1'];

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
	public function testDeletesMatchTheRecordedFixture(): void {
		$actual = [];
		for ($case = 0; $case < self::CASES; $case++) {
			$this->random = new Randomizer(new Mt19937($case + 1));
			$method = ['destroy', 'destroy', 'destroyPermanent'][$this->random->getInt(0, 2)];
			$caller = $this->pick(pool: self::CALLERS);
			$calls = [];
			$logger = $this->logger();
			$controller = $this->controller(caller: $caller, calls: $calls, logger: $logger);
			$response = $controller->$method(uuid: 's' . ($case % 4));

			$actual[] = [
				'method' => $method,
				'caller' => $caller,
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

	}//end testDeletesMatchTheRecordedFixture()

	/**
	 * The controller over a generated world, recording each collaborator call.
	 *
	 * @param string|null $caller The signed-in uid, or null when signed out.
	 * @param array<int, mixed> $calls The call log, appended to.
	 * @param AbstractLogger $logger The recording logger.
	 *
	 * @return SessionController
	 */
	private function controller(?string $caller, array &$calls, AbstractLogger $logger): SessionController {
		$session = $this->createMock(originalClassName: IUserSession::class);
		$user = null;
		if ($caller !== null) {
			$user = $this->createMock(originalClassName: IUser::class);
			$user->method('getUID')->willReturn($caller);
		}

		$session->method('getUser')->willReturn($user);

		$request = $this->createMock(originalClassName: IRequest::class);
		$request->method('getPathInfo')->willReturn($this->pick(pool: self::PATHS));

		$conversation = $this->conversation();
		$related = ['message' => $this->related(prefix: 'm'), 'feedback' => $this->related(prefix: 'f')];
		$failDelete = ['', '', '', 'm1', 'f1', 'conversation'][$this->random->getInt(0, 5)];
		$failSave = ($this->random->getInt(0, 6) === 0);
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
			function (mixed ...$args) use (&$calls, &$schema, $related): array {
				$calls[] = ['findAll', $schema, $this->passed(method: 'findAll', args: $args)];
				return ($related[$schema] ?? []);
			}
		);
		$objectService->method('deleteObject')->willReturnCallback(
			function (mixed ...$args) use (&$calls, $failDelete): bool {
				$passed = $this->passed(method: 'deleteObject', args: $args);
				$calls[] = ['deleteObject', $passed];
				$target = (string)($passed['uuid'] ?? '');
				if ($failDelete !== '' && ($target === $failDelete || ($failDelete === 'conversation' && str_starts_with($target, 's') === true))) {
					throw new RuntimeException('delete refused for ' . $target);
				}

				return true;
			}
		);
		$objectService->method('saveObject')->willReturnCallback(
			function (mixed ...$args) use (&$calls, $failSave): ObjectEntity {
				$passed = $this->passed(method: 'saveObject', args: $args);
				$calls[] = ['saveObject', $this->withoutNow(passed: $passed)];
				if ($failSave === true) {
					throw new RuntimeException('save refused');
				}

				return new ObjectEntity();
			}
		);

		$sessionRoom = $this->createMock(originalClassName: TalkSessionRoom::class);
		$sessionRoom->method('attachToSession')->willReturnArgument(0);

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
	 * The lookup's answer: nothing, a failing lookup, or a conversation of alice or bob.
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

		$payload = ['title' => 'Chat', 'userId' => $this->pick(pool: ['alice', 'alice', 'alice', 'alice', 'bob', null])];
		$marker = $this->pick(pool: self::MARKERS);
		if ($marker !== null) {
			$payload['metadata'] = $marker;
		}

		$entity = new ObjectEntity();
		$entity->setUuid('conv-entity');
		$entity->setObject($payload);
		return $entity;

	}//end conversation()

	/**
	 * Related turns or ratings: objects and the odd non-object.
	 *
	 * @param string $prefix The uuid prefix.
	 *
	 * @return array<int, mixed>
	 */
	private function related(string $prefix): array {
		$out = [];
		for ($i = $this->random->getInt(0, 3); $i > 0; $i--) {
			if ($this->random->getInt(0, 7) === 0) {
				$out[] = ['not' => 'an entity'];
				continue;
			}

			$entity = new ObjectEntity();
			$entity->setUuid($prefix . $i);
			$out[] = $entity;
		}

		return $out;

	}//end related()

	/**
	 * A saved payload with its archive time replaced when it is the current time.
	 *
	 * @param array<string, mixed> $passed The passed save arguments.
	 *
	 * @return array<string, mixed>
	 */
	private function withoutNow(array $passed): array {
		$at = ($passed['object']['metadata']['deletedAt'] ?? null);
		if (is_string($at) === true && abs(strtotime($at) - time()) < 120) {
			$passed['object']['metadata']['deletedAt'] = '(now)';
		}

		return $passed;

	}//end withoutNow()

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

<?php

/**
 * Standing goals (agents-standing-goal): set, stop, and the turn on the
 * dispatcher with its gates, its session and its check.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/agents-standing-goal/specs/agent-schedule/spec.md#requirement-goal-turns-continue-the-same-session-through-the-scheduled-run-gates-req-aggoal-002
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service;

use OCA\Hermiq\Service\GoalCheckService;
use OCA\Hermiq\Service\GoalService;
use OCA\Hermiq\Service\ScheduleService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Tests for GoalService.
 *
 * @spec openspec/changes/agents-standing-goal/specs/agent-schedule/spec.md#requirement-goal-turns-continue-the-same-session-through-the-scheduled-run-gates-req-aggoal-002
 */
class GoalServiceTest extends TestCase {

	/**
	 * A stateful store keyed by schema and uuid.
	 *
	 * @return ObjectService
	 */
	private function store(): ObjectService {
		return new class() extends ObjectService {
			private string $schema = '';

			/** @var array<string, array<string, ObjectEntity>> */
			public array $objects = [];

			public int $writes = 0;

			public function __construct() {
			}

			public function setRegister(mixed $register): static {
				return $this;
			}

			public function setSchema(mixed $schema): static {
				$this->schema = (string)$schema;
				return $this;
			}

			public function findAll(array $config = [], bool $_rbac = true, bool $_multitenancy = true): array {
				return array_values($this->objects[$this->schema] ?? []);
			}

			public function find(
				int|string $id,
				?array $_extend = [],
				bool $files = false,
				mixed $register = null,
				mixed $schema = null,
				bool $_rbac = true,
				bool $_multitenancy = true,
				bool $_render = true,
				bool $_audit = true
			): ?ObjectEntity {
				return $this->objects[(string)$schema][(string)$id] ?? null;
			}

			public function saveObject(
				array|ObjectEntity $object,
				?array $extend = [],
				mixed $register = null,
				mixed $schema = null,
				?string $uuid = null,
				bool $_rbac = true,
				bool $_multitenancy = true,
				bool $silent = false,
				bool $_validation = true,
				?array $uploadedFiles = null,
				?\OCP\IUser $currentUser = null,
				bool $failIfExists = false,
				bool $_unowned = false,
				bool $_dedupOverride = false,
				?string $_owner = null,
			): ObjectEntity {
				$this->writes++;
				$id = $uuid ?? sprintf('00000000-0000-4000-8000-%012d', $this->writes);
				$entity = $this->objects[(string)$schema][$id] ?? new ObjectEntity();
				$entity->setUuid($id);
				$entity->setObject(is_array($object) ? $object : $object->getObject());
				$this->objects[(string)$schema][$id] = $entity;
				return $entity;
			}
		};
	}//end store()

	/**
	 * An entity with data and an owner.
	 *
	 * @param string               $uuid  The uuid.
	 * @param array<string, mixed> $data  The data.
	 * @param string               $owner The owner.
	 *
	 * @return ObjectEntity
	 */
	private function entity(string $uuid, array $data, string $owner = ''): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setObject($data);
		$entity->setOwner($owner);
		return $entity;
	}//end entity()

	/**
	 * The service with a store holding the officer's session and the agent.
	 *
	 * @param ObjectService        $store         The store.
	 * @param ScheduleService      $schedules     The gates and the run.
	 * @param GoalCheckService     $checks        The check.
	 * @param INotificationManager $notifications Notices.
	 *
	 * @return GoalService
	 */
	private function service(ObjectService $store, ScheduleService $schedules, GoalCheckService $checks, INotificationManager $notifications): GoalService {
		$store->objects['agentsession']['s-1'] = $this->entity('s-1', ['userId' => 'officer', 'agentId' => '11111111-1111-4111-8111-111111111111']);
		$store->objects['agent']['11111111-1111-4111-8111-111111111111'] = $this->entity('11111111-1111-4111-8111-111111111111', ['name' => 'Permit reminder'], 'agent-owner');

		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturn($this->createMock(IUser::class));

		return new GoalService(
			objectService: $store,
			schedules: $schedules,
			checks: $checks,
			userSession: $this->createMock(IUserSession::class),
			userManager: $users,
			notifications: $notifications,
			logger: new NullLogger()
		);
	}//end service()

	/**
	 * A notification manager that records the subjects sent.
	 *
	 * @param array<int, string> $sent The subjects, by reference.
	 *
	 * @return INotificationManager
	 */
	private function notifications(array &$sent): INotificationManager {
		$manager = $this->createMock(INotificationManager::class);
		$manager->method('createNotification')->willReturnCallback(function () use (&$sent): INotification {
			$notification = $this->createMock(INotification::class);
			foreach (['setApp', 'setUser', 'setDateTime', 'setObject'] as $method) {
				$notification->method($method)->willReturnSelf();
			}

			$notification->method('setSubject')->willReturnCallback(function (string $subject, array $params) use (&$sent, $notification): INotification {
				$sent[] = $subject . ': ' . $params['name'];
				return $notification;
			});
			return $notification;
		});
		return $manager;
	}//end notifications()

	/**
	 * The officer sets the reminder goal from the spec.
	 *
	 * @param GoalService $service The service.
	 * @param int         $maxTurns The turn limit.
	 *
	 * @return array<string, mixed>
	 */
	private function setGoal(GoalService $service, int $maxTurns = 10): array {
		return $service->set(
			sessionId: 's-1',
			uid: 'officer',
			input: [
				'statement' => 'Every overdue permit application has had a reminder',
				'check' => ['kind' => 'objectCount', 'register' => 'permits', 'schema' => 'application', 'filters' => ['status' => 'overdue'], 'target' => 0],
				'intervalMinutes' => 60,
				'maxTurns' => $maxTurns,
			]
		);
	}//end setGoal()

	/**
	 * Make the stored goal due now.
	 *
	 * @param ObjectService $store The store.
	 *
	 * @return void
	 */
	private function makeDue(ObjectService $store): void {
		foreach ($store->objects['agentgoal'] as $goal) {
			$data = $goal->getObject();
			$data['nextTurnAt'] = '2000-01-01T00:00:00+00:00';
			$goal->setObject($data);
		}
	}//end makeDue()

	/**
	 * Counts 4, 1, 0: reached on turn three, one notification, no fourth turn.
	 *
	 * @return void
	 */
	public function testTheGoalIsReachedAfterThreeTurns(): void {
		$store = $this->store();
		$schedules = $this->createMock(ScheduleService::class);
		$schedules->method('gateFor')->willReturn(null);
		$schedules->expects($this->exactly(3))->method('runAgentAsOwner')
			->with($this->anything(), $this->anything(), $this->stringContains('Continue working on the goal'), $this->anything(), $this->anything(), $this->anything(), $this->anything(), $this->anything(), $this->anything(), 's-1')
			->willReturn('Sent two reminders.');
		$checks = $this->createMock(GoalCheckService::class);
		$checks->method('check')->willReturnOnConsecutiveCalls(
			['reached' => false, 'value' => 4, 'summary' => '4 left, target 0'],
			['reached' => false, 'value' => 1, 'summary' => '1 left, target 0'],
			['reached' => true, 'value' => 0, 'summary' => '0 left, target 0'],
		);
		$sent = [];
		$service = $this->service($store, $schedules, $checks, $this->notifications($sent));

		$goal = $this->setGoal($service);
		$this->assertSame(0, $goal['turnsUsed']);
		for ($turn = 0; $turn < 4; $turn++) {
			$this->makeDue($store);
			$service->run();
		}

		$stored = $store->objects['agentgoal'][$goal['id']]->getObject();
		$this->assertSame('reached', $stored['status']);
		$this->assertSame(3, $stored['turnsUsed']);
		$this->assertSame(['goal_reached: Every overdue permit application has had a reminder'], $sent);
	}//end testTheGoalIsReachedAfterThreeTurns()

	/**
	 * The kill switch holds a turn: nothing runs, the goal shows blocked and retries later.
	 *
	 * @return void
	 */
	public function testTheKillSwitchHoldsAGoal(): void {
		$store = $this->store();
		$schedules = $this->createMock(ScheduleService::class);
		$schedules->method('gateFor')->willReturn('skipped_killswitch');
		$schedules->expects($this->never())->method('runAgentAsOwner');
		$checks = $this->createMock(GoalCheckService::class);
		$checks->expects($this->never())->method('check');
		$sent = [];
		$service = $this->service($store, $schedules, $checks, $this->notifications($sent));

		$goal = $this->setGoal($service);
		$this->makeDue($store);
		$service->run();

		$stored = $store->objects['agentgoal'][$goal['id']]->getObject();
		$this->assertSame('active', $stored['status']);
		$this->assertSame('skipped_killswitch', $stored['blocked']);
		$this->assertSame(0, $stored['turnsUsed']);
		$this->assertGreaterThan(time(), strtotime($stored['nextTurnAt']));
	}//end testTheKillSwitchHoldsAGoal()

	/**
	 * The turn limit used without success: exhausted, and the owner is told.
	 *
	 * @return void
	 */
	public function testAGoalIsExhaustedAtItsTurnLimit(): void {
		$store = $this->store();
		$schedules = $this->createMock(ScheduleService::class);
		$schedules->method('gateFor')->willReturn(null);
		$schedules->method('runAgentAsOwner')->willReturn('');
		$checks = $this->createMock(GoalCheckService::class);
		$checks->method('check')->willReturn(['reached' => false, 'value' => 2, 'summary' => '2 left, target 0']);
		$sent = [];
		$service = $this->service($store, $schedules, $checks, $this->notifications($sent));

		$goal = $this->setGoal($service, 1);
		$this->makeDue($store);
		$service->run();

		$this->assertSame('exhausted', $store->objects['agentgoal'][$goal['id']]->getObject()['status']);
		$this->assertSame(['goal_exhausted: Every overdue permit application has had a reminder'], $sent);
	}//end testAGoalIsExhaustedAtItsTurnLimit()

	/**
	 * One active goal per session, only on the person's own session, limits clamped.
	 *
	 * @return void
	 */
	public function testSettingAGoal(): void {
		$store = $this->store();
		$sent = [];
		$service = $this->service($store, $this->createMock(ScheduleService::class), $this->createMock(GoalCheckService::class), $this->notifications($sent));

		$goal = $service->set(sessionId: 's-1', uid: 'officer', input: ['statement' => 'x', 'check' => ['kind' => 'judge', 'question' => 'Done?'], 'intervalMinutes' => 1, 'maxTurns' => 500]);
		$this->assertSame(15, $goal['intervalMinutes']);
		$this->assertSame(50, $goal['maxTurns']);
		$this->assertSame('11111111-1111-4111-8111-111111111111', $goal['agentId']);

		foreach ([['s-1', 'officer', 409], ['s-1', 'someone-else', 404], ['no-session', 'officer', 404]] as [$session, $uid, $code]) {
			try {
				$this->setGoal(service: $service);
				$service->set(sessionId: $session, uid: $uid, input: ['statement' => 'y', 'check' => ['kind' => 'judge', 'question' => 'Done?']]);
				$this->fail('expected ' . $code);
			} catch (RuntimeException $e) {
				$this->assertContains($e->getCode(), [$code, 409]);
			}
		}
	}//end testSettingAGoal()

	/**
	 * The goal's owner and the agent's owner may stop it; anyone else gets 404.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agents-standing-goal/specs/agent-schedule/spec.md#requirement-a-goal-can-be-stopped-by-its-owner-or-the-agent-owner-req-aggoal-003
	 */
	public function testOnlyTheOwnersStopAGoal(): void {
		$store = $this->store();
		$sent = [];
		$service = $this->service($store, $this->createMock(ScheduleService::class), $this->createMock(GoalCheckService::class), $this->notifications($sent));
		$goal = $this->setGoal($service);

		try {
			$service->stop(goalId: $goal['id'], uid: 'someone-else');
			$this->fail('another user must get 404');
		} catch (RuntimeException $e) {
			$this->assertSame(404, $e->getCode());
		}

		$this->assertSame('stopped', $service->stop(goalId: $goal['id'], uid: 'agent-owner')['status']);
		$this->assertSame('stopped', $service->stop(goalId: $goal['id'], uid: 'officer')['status']);
	}//end testOnlyTheOwnersStopAGoal()

	/**
	 * What set() writes passes the real Goal fragment; a goal with a bad status does not.
	 *
	 * @return void
	 */
	public function testTheGoalPassesTheRealSchemaFragment(): void {
		$store = $this->store();
		$sent = [];
		$service = $this->service($store, $this->createMock(ScheduleService::class), $this->createMock(GoalCheckService::class), $this->notifications($sent));
		$goal = $this->setGoal($service);

		$register = json_decode((string)file_get_contents(__DIR__ . '/../../../lib/Settings/hermiq_register.json'));
		$fragment = $register->components->schemas->Goal;
		unset($fragment->authorization);
		$fragment = json_decode((string)preg_replace('/,?\s*"\$ref":\s*"[^"]*"/', '', (string)json_encode($fragment)));

		$payload = json_decode((string)json_encode($store->objects['agentgoal'][$goal['id']]->getObject()));
		$this->assertTrue((new Validator())->validate($payload, $fragment)->isValid(), 'the goal is refused by its fragment');

		$payload->status = 'blocked';
		$this->assertFalse((new Validator())->validate($payload, $fragment)->isValid(), 'negative control: status blocked is not a status');
	}//end testTheGoalPassesTheRealSchemaFragment()

	/**
	 * A goal entity owned by the organisation, as the turn reads it.
	 *
	 * @param array<string, mixed> $data The goal's fields.
	 *
	 * @return ObjectEntity
	 */
	private function goal(array $data): ObjectEntity {
		$goal = $this->entity('goal-1', $data + ['agentId' => 'a-1', 'setBy' => 'officer', 'statement' => 'S', 'sessionId' => 's-1', 'check' => ['kind' => 'judge', 'question' => 'Q?']]);
		$goal->setOrganisation('org-1');
		return $goal;
	}//end goal()

	/**
	 * One turn: it schedules the next turn from now, runs the agent as the goal's
	 * owner in the goal's session with the continue prompt, checks the answer as the
	 * owner and records the check; a failing run checks an empty answer.
	 *
	 * @return void
	 */
	public function testOneTurnRunsChecksAndRecords(): void {
		$store = $this->store();
		$runs = [];
		$schedules = $this->createMock(ScheduleService::class);
		$schedules->method('gateFor')->with('org-1', 'a-1')->willReturn(null);
		$schedules->method('runAgentAsOwner')->willReturnCallback(
			static function (...$args) use (&$runs): string {
				$runs[] = [$args[0], $args[1], $args[2], $args[3], $args[9]];
				if (count($runs) === 2) {
					throw new RuntimeException('runner down');
				}

				return 'did it';
			}
		);
		$checked = [];
		$checks = $this->createMock(GoalCheckService::class);
		$checks->method('check')->willReturnCallback(
			static function (array $check, string $lastAnswer, string $organisation) use (&$checked): array {
				$checked[] = [$check, $lastAnswer, $organisation];
				return ['reached' => false, 'value' => 2, 'summary' => '2 left'];
			}
		);
		$sent = [];
		$service = $this->service($store, $schedules, $checks, $this->notifications($sent));
		$now = new \DateTimeImmutable('2026-01-01T10:00:00+00:00');

		$service->turn($this->goal(['intervalMinutes' => 30, 'turnsUsed' => 1, 'maxTurns' => 5, 'lastCheckResult' => ['summary' => '3 left']]), $now);
		$service->turn($this->goal(['turnsUsed' => 1]), $now);

		$this->assertSame(
			[
				['officer', 'a-1', 'Continue working on the goal: S. Last check: 3 left.', 'org-1', 's-1'],
				['officer', 'a-1', 'Continue working on the goal: S. Last check: not run yet.', 'org-1', 's-1'],
			],
			$runs
		);
		$this->assertSame([[['kind' => 'judge', 'question' => 'Q?'], 'did it', 'org-1'], [['kind' => 'judge', 'question' => 'Q?'], '', 'org-1']], $checked);
		$stored = $store->objects['agentgoal']['goal-1']->getObject();
		$this->assertSame('2026-01-01T11:00:00+00:00', $stored['nextTurnAt']);
		$this->assertSame('', $stored['blocked']);
		$this->assertSame(2, $stored['turnsUsed']);
		$this->assertSame(['reached' => false, 'value' => 2, 'summary' => '2 left', 'checkedAt' => '2026-01-01T10:00:00+00:00'], $stored['lastCheckResult']);
		$this->assertArrayNotHasKey('status', $stored);
		$this->assertSame([], $sent);

	}//end testOneTurnRunsChecksAndRecords()

	/**
	 * A gated turn records only the gate and the next turn; reaching beats the turn
	 * limit, and the turn limit exhausts a goal, each notifying the owner once.
	 *
	 * @return void
	 */
	public function testGateReachedAndExhaustedTurns(): void {
		$store = $this->store();
		$schedules = $this->createMock(ScheduleService::class);
		$schedules->method('gateFor')->willReturnOnConsecutiveCalls('skipped_budget', null, null);
		$schedules->method('runAgentAsOwner')->willReturn('');
		$checks = $this->createMock(GoalCheckService::class);
		$checks->method('check')->willReturnOnConsecutiveCalls(
			['reached' => true, 'value' => 0, 'summary' => 'done'],
			['reached' => false, 'value' => 1, 'summary' => '1 left']
		);
		$sent = [];
		$service = $this->service($store, $schedules, $checks, $this->notifications($sent));
		$now = new \DateTimeImmutable('2026-01-01T10:00:00+00:00');

		$service->turn($this->goal(['turnsUsed' => 4, 'maxTurns' => 5, 'status' => 'active']), $now);
		$gated = $store->objects['agentgoal']['goal-1']->getObject();
		$this->assertSame(['skipped_budget', 4, 'active', '2026-01-01T11:00:00+00:00'], [$gated['blocked'], $gated['turnsUsed'], $gated['status'], $gated['nextTurnAt']]);
		$this->assertArrayNotHasKey('lastCheckResult', $gated);

		$service->turn($this->goal(['turnsUsed' => 4, 'maxTurns' => 5, 'status' => 'active']), $now);
		$this->assertSame('reached', $store->objects['agentgoal']['goal-1']->getObject()['status']);

		$service->turn($this->goal(['turnsUsed' => 4, 'maxTurns' => 5, 'status' => 'active']), $now);
		$this->assertSame('exhausted', $store->objects['agentgoal']['goal-1']->getObject()['status']);
		$this->assertSame(['goal_reached: S', 'goal_exhausted: S'], $sent);

	}//end testGateReachedAndExhaustedTurns()

	/**
	 * A goal's check is kept only as a complete objectCount or a non-blank judge
	 * question, normalised to exactly these fields.
	 *
	 * @return void
	 */
	public function testOnlyACompleteCheckIsAccepted(): void {
		$cases = [
			[['kind' => 'objectCount', 'register' => 'r', 'schema' => 's', 'filters' => 'x', 'target' => -3, 'extra' => 1], ['kind' => 'objectCount', 'register' => 'r', 'schema' => 's', 'filters' => ['x'], 'target' => 0]],
			[['kind' => 'objectCount', 'register' => 'r', 'schema' => 's', 'target' => '4'], ['kind' => 'objectCount', 'register' => 'r', 'schema' => 's', 'filters' => [], 'target' => 4]],
			[['kind' => 'objectCount', 'register' => 'r'], null],
			[['kind' => 'objectCount', 'schema' => 's'], null],
			[['kind' => 'judge', 'question' => '  Done?  ', 'register' => 'r'], ['kind' => 'judge', 'question' => 'Done?']],
			[['kind' => 'judge', 'question' => '   '], null],
			[['kind' => 'other', 'question' => 'Q'], null],
			[[], null],
		];
		foreach ($cases as $index => [$check, $expected]) {
			$store = $this->store();
			$service = $this->service($store, $this->createMock(ScheduleService::class), $this->createMock(GoalCheckService::class), $this->createMock(INotificationManager::class));
			try {
				$goal = $service->set(sessionId: 's-1', uid: 'officer', input: ['statement' => 'S', 'check' => $check]);
				$this->assertSame($expected, $goal['check'], 'case ' . $index);
			} catch (RuntimeException $e) {
				$this->assertNull($expected, 'case ' . $index);
				$this->assertSame([422, 'A goal needs a statement and a check.'], [$e->getCode(), $e->getMessage()]);
			}
		}

	}//end testOnlyACompleteCheckIsAccepted()

	/**
	 * The goal read keeps only goal entities whose fields equal every filter value,
	 * compared as strings, in read order.
	 *
	 * @return void
	 */
	public function testTheGoalReadKeepsGoalsMatchingEveryFilter(): void {
		$store = $this->store();
		$store->objects['agentgoal'] = [
			'a' => $this->entity('a', ['status' => 'active', 'sessionId' => 's-1']),
			'b' => $this->entity('b', ['status' => 'reached', 'sessionId' => 's-1']),
			'c' => $this->entity('c', ['status' => 'active', 'sessionId' => 's-2']),
			'd' => $this->entity('d', ['status' => 'active', 'sessionId' => 's-1', 'x' => 1]),
		];
		$service = $this->service($store, $this->createMock(ScheduleService::class), $this->createMock(GoalCheckService::class), $this->createMock(INotificationManager::class));
		$read = new \ReflectionMethod(GoalService::class, 'goals');

		$ids = static fn (array $goals): array => array_map(static fn (ObjectEntity $goal): string => (string)$goal->getUuid(), $goals);
		$this->assertSame(['a', 'd'], $ids($read->invoke($service, ['status' => 'active', 'sessionId' => 's-1'])));
		$this->assertSame(['a', 'b', 'c', 'd'], $ids($read->invoke($service, [])));
		$this->assertSame([], $ids($read->invoke($service, ['x' => 1])));
		$this->assertSame(['d'], $ids($read->invoke($service, ['x' => '1'])));

	}//end testTheGoalReadKeepsGoalsMatchingEveryFilter()
}//end class

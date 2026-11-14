<?php

/**
 * Hermiq GoalService.
 *
 * Standing goals (agents-standing-goal): set, read and stop a goal on a session,
 * and take each due goal's next turn. A turn passes the same gates as a
 * scheduled run (agent switched off, kill switch, budget), continues the goal's
 * own session through ScheduleService::runAgentAsOwner(), then runs the check
 * as the person who set the goal. Reached and exhausted goals notify that person.
 *
 * @category Service
 * @package  OCA\Hermiq\Service
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
 * @spec openspec/changes/agents-standing-goal/specs/agent-schedule/spec.md#requirement-goal-turns-continue-the-same-session-through-the-scheduled-run-gates-req-aggoal-002
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service;

use DateTime;
use DateTimeImmutable;
use DateTimeZone;
use OCA\Hermiq\Kernel\Contracts\GoalServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Notification\IManager as INotificationManager;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Standing goals: their lifecycle and their turns.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)  A turn needs the store, the schedule gates, the check, the owner's identity and the notifier.
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) The lifecycle (set, stop, who may stop) and the turn share the goal's guards.
 *
 * @spec openspec/changes/agents-standing-goal/specs/agent-schedule/spec.md#requirement-goal-turns-continue-the-same-session-through-the-scheduled-run-gates-req-aggoal-002
 */
class GoalService implements GoalServiceInterface {

	private const REGISTER = 'hermiq';
	private const GOAL_SCHEMA = 'agentgoal';
	private const SESSION_SCHEMA = 'agentsession';
	private const AGENT_SCHEMA = 'agent';

	/**
	 * The notification subject for each way a turn can end a goal.
	 *
	 * @var array<string, string>
	 */
	private const TURN_OUTCOME_SUBJECTS = ['reached' => 'goal_reached', 'exhausted' => 'goal_exhausted'];

	/**
	 * Constructor.
	 *
	 * @param ObjectService        $objectService Goal, session and agent reads and writes.
	 * @param ScheduleService      $schedules     The scheduled-run gates and the run itself.
	 * @param GoalCheckService     $checks        The check after each turn.
	 * @param IUserSession         $userSession   Runs the check as the goal's owner.
	 * @param IUserManager         $userManager   Resolves that owner.
	 * @param INotificationManager $notifications Reached and exhausted notices.
	 * @param LoggerInterface      $logger        PSR-3 logger.
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly ScheduleService $schedules,
		private readonly GoalCheckService $checks,
		private readonly IUserSession $userSession,
		private readonly IUserManager $userManager,
		private readonly INotificationManager $notifications,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Set a goal on the person's own session; one active goal per session.
	 *
	 * @param string               $sessionId The session.
	 * @param string               $uid       The person.
	 * @param array<string, mixed> $input     statement, check, intervalMinutes, maxTurns.
	 *
	 * @return array<string, mixed> The goal.
	 *
	 * @throws RuntimeException 404 for a session that is not the person's, 409 when one is active, 422 for a bad goal.
	 *
	 * @spec openspec/changes/agents-standing-goal/specs/agent-schedule/spec.md#requirement-a-person-can-give-an-agent-a-standing-goal-with-a-check-req-aggoal-001
	 */
	public function set(string $sessionId, string $uid, array $input): array {
		$session = $this->objectService->find(id: $sessionId, register: self::REGISTER, schema: self::SESSION_SCHEMA);
		if ($uid === '' || $session === null || (string)($session->getObject()['userId'] ?? '') !== $uid) {
			throw new RuntimeException('Session not found', 404);
		}

		if ($this->activeGoalOf(sessionId: $sessionId) !== null) {
			throw new RuntimeException('This session already has an active goal.', 409);
		}

		$statement = trim((string)($input['statement'] ?? ''));
		$check     = $this->cleanCheck(check: (array)($input['check'] ?? []));
		if ($statement === '' || $check === null) {
			throw new RuntimeException('A goal needs a statement and a check.', 422);
		}

		$interval = $this->clamp(value: ($input['intervalMinutes'] ?? 60), min: 15, max: 1440);
		$goal     = [
			'agentId' => (string)($session->getObject()['agentId'] ?? ''),
			'sessionId' => $sessionId,
			'statement' => mb_substr($statement, 0, 500),
			'check' => $check,
			'intervalMinutes' => $interval,
			'maxTurns' => $this->clamp(value: ($input['maxTurns'] ?? 10), min: 1, max: 50),
			'turnsUsed' => 0,
			'status' => 'active',
			'blocked' => '',
			'setBy' => $uid,
			'nextTurnAt' => $this->now()->modify('+' . $interval . ' minutes')->format(DATE_ATOM),
		];

		$saved = $this->objectService->saveObject(
			object: $goal,
			register: self::REGISTER,
			schema: self::GOAL_SCHEMA,
			_rbac: false,
			_multitenancy: false
		);

		return $this->view(goal: $saved);
	}//end set()

	/**
	 * The session's newest goal, for its owner, or null.
	 *
	 * @param string $sessionId The session.
	 * @param string $uid       The person.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @throws RuntimeException 404 for a session that is not the person's.
	 *
	 * @spec openspec/changes/agents-standing-goal/specs/agent-schedule/spec.md#requirement-a-person-can-give-an-agent-a-standing-goal-with-a-check-req-aggoal-001
	 */
	public function forSession(string $sessionId, string $uid): ?array {
		$session = $this->objectService->find(id: $sessionId, register: self::REGISTER, schema: self::SESSION_SCHEMA);
		if ($uid === '' || $session === null || (string)($session->getObject()['userId'] ?? '') !== $uid) {
			throw new RuntimeException('Session not found', 404);
		}

		$goals = $this->goals(filters: ['sessionId' => $sessionId]);
		if ($goals === []) {
			return null;
		}

		usort(
			$goals,
			static fn (ObjectEntity $a, ObjectEntity $b): int => strcmp(
				(string)($b->getCreated()?->format(DATE_ATOM) ?? ''),
				(string)($a->getCreated()?->format(DATE_ATOM) ?? '')
			)
		);

		return $this->view(goal: $goals[0]);
	}//end forSession()

	/**
	 * Stop a goal: only the person who set it and the agent's owner may; anyone else gets 404.
	 *
	 * @param string $goalId The goal.
	 * @param string $uid    The person.
	 *
	 * @return array<string, mixed> The stopped goal.
	 *
	 * @throws RuntimeException 404 for anyone else or an unknown goal.
	 *
	 * @spec openspec/changes/agents-standing-goal/specs/agent-schedule/spec.md#requirement-a-goal-can-be-stopped-by-its-owner-or-the-agent-owner-req-aggoal-003
	 */
	public function stop(string $goalId, string $uid): array {
		$goal = $this->objectService->find(id: $goalId, register: self::REGISTER, schema: self::GOAL_SCHEMA, _rbac: false, _multitenancy: false);
		if ($goal === null || $uid === '' || $this->mayStop(goal: $goal, uid: $uid) === false) {
			throw new RuntimeException('Goal not found', 404);
		}

		$data = $goal->getObject();
		if (($data['status'] ?? '') === 'active') {
			$data['status'] = 'stopped';
			$goal = $this->persist(goal: $goal, data: $data);
		}

		return $this->view(goal: $goal);
	}//end stop()

	/**
	 * Take the next turn of every due goal (called by the dispatcher's background job).
	 *
	 * @return int The goals handled.
	 *
	 * @spec openspec/changes/agents-standing-goal/specs/agent-schedule/spec.md#requirement-goal-turns-continue-the-same-session-through-the-scheduled-run-gates-req-aggoal-002
	 */
	public function run(): int {
		$now     = $this->now();
		$handled = 0;
		foreach ($this->goals(filters: ['status' => 'active']) as $goal) {
			$next = strtotime((string)($goal->getObject()['nextTurnAt'] ?? ''));
			if ($next !== false && $next > $now->getTimestamp()) {
				continue;
			}

			try {
				$this->turn(goal: $goal, now: $now);
				$handled++;
			} catch (Throwable $e) {
				$this->logger->error('[hermiq] Goal turn failed for ' . (string)$goal->getUuid() . ': ' . $e->getMessage(), ['exception' => $e]);
			}
		}

		return $handled;
	}//end run()

	/**
	 * One turn: the gates, the run in the goal's session, the check, the outcome.
	 *
	 * @param ObjectEntity      $goal The due goal.
	 * @param DateTimeImmutable $now  The tick.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agents-standing-goal/specs/agent-schedule/spec.md#requirement-goal-turns-continue-the-same-session-through-the-scheduled-run-gates-req-aggoal-002
	 */
	public function turn(ObjectEntity $goal, DateTimeImmutable $now): void {
		$data = $goal->getObject();
		$data['nextTurnAt'] = $now->modify('+' . (int)($data['intervalMinutes'] ?? 60) . ' minutes')->format(DATE_ATOM);

		$gate = $this->turnGate(goal: $goal);
		if ($gate !== null) {
			$data['blocked'] = $gate;
			$this->persist(goal: $goal, data: $data);
			return;
		}

		$answer = $this->runTurnAgent(goal: $goal);

		$data['blocked']   = '';
		$data['turnsUsed'] = (int)($data['turnsUsed'] ?? 0) + 1;
		$data['lastCheckResult'] = $this->checkTurnAnswer(goal: $goal, answer: $answer) + ['checkedAt' => $now->format(DATE_ATOM)];

		$this->persist(goal: $goal, data: $this->withTurnOutcome(goal: $goal, data: $data));
	}//end turn()

	/**
	 * The gate (kill switch, budget, ...) that holds the goal's agent now, if any.
	 *
	 * @param ObjectEntity $goal The goal.
	 *
	 * @return string|null The gate, or null when the turn may run.
	 */
	private function turnGate(ObjectEntity $goal): ?string {
		return $this->schedules->gateFor(
			organisation: (string)($goal->getOrganisation() ?? ''),
			agentId: (string)($goal->getObject()['agentId'] ?? '')
		);

	}//end turnGate()

	/**
	 * Run the goal's agent as its owner in the goal's session with the continue
	 * prompt; a failed run is logged and answers nothing.
	 *
	 * @param ObjectEntity $goal The goal.
	 *
	 * @return string The agent's answer, or `''`.
	 */
	private function runTurnAgent(ObjectEntity $goal): string {
		$data = $goal->getObject();
		try {
			return $this->schedules->runAgentAsOwner(
				owner: (string)($data['setBy'] ?? ''),
				agentId: (string)($data['agentId'] ?? ''),
				prompt: $this->turnPrompt(data: $data),
				organisation: (string)($goal->getOrganisation() ?? ''),
				continueSessionUuid: (string)($data['sessionId'] ?? '')
			);
		} catch (Throwable $e) {
			$this->logger->warning('[hermiq] Goal turn run failed: ' . $e->getMessage());
			return '';
		}

	}//end runTurnAgent()

	/**
	 * The prompt a turn continues the goal with: its statement and the last check.
	 *
	 * @param array<string, mixed> $data The goal's fields.
	 *
	 * @return string The prompt.
	 */
	private function turnPrompt(array $data): string {
		$last        = (array)($data['lastCheckResult'] ?? []);
		$lastSummary = (string)($last['summary'] ?? '');
		if ($lastSummary === '') {
			$lastSummary = 'not run yet';
		}

		return 'Continue working on the goal: ' . (string)($data['statement'] ?? '') . '. Last check: ' . $lastSummary . '.';

	}//end turnPrompt()

	/**
	 * Check a turn's answer against the goal's check, as the goal's owner.
	 *
	 * @param ObjectEntity $goal The goal.
	 * @param string $answer The agent's answer.
	 *
	 * @return array<string, mixed> The check result.
	 */
	private function checkTurnAnswer(ObjectEntity $goal, string $answer): array {
		$data = $goal->getObject();
		return $this->checkAs(
			owner: (string)($data['setBy'] ?? ''),
			check: (array)($data['check'] ?? []),
			answer: $answer,
			organisation: (string)($goal->getOrganisation() ?? '')
		);

	}//end checkTurnAnswer()

	/**
	 * End the goal when the turn reached it or used its last turn, notifying the owner.
	 *
	 * @param ObjectEntity $goal The goal.
	 * @param array<string, mixed> $data The goal's fields after the turn.
	 *
	 * @return array<string, mixed> The goal's fields, with the status when it ended.
	 */
	private function withTurnOutcome(ObjectEntity $goal, array $data): array {
		$status = $this->turnOutcome(data: $data);
		if ($status === null) {
			return $data;
		}

		$data['status'] = $status;
		$this->notify(
			owner: (string)($data['setBy'] ?? ''),
			goal: $goal,
			subject: self::TURN_OUTCOME_SUBJECTS[$status],
			statement: (string)($data['statement'] ?? '')
		);

		return $data;

	}//end withTurnOutcome()

	/**
	 * How a turn ends the goal: `reached` when the check passed, else `exhausted` at
	 * the turn limit, else not at all.
	 *
	 * @param array<string, mixed> $data The goal's fields after the turn.
	 *
	 * @return string|null The outcome, or null when the goal stays active.
	 */
	private function turnOutcome(array $data): ?string {
		if ($data['lastCheckResult']['reached'] === true) {
			return 'reached';
		}

		if ($data['turnsUsed'] >= (int)($data['maxTurns'] ?? 10)) {
			return 'exhausted';
		}

		return null;

	}//end turnOutcome()

	/**
	 * Run the check under the goal owner's identity, then restore the session.
	 *
	 * @param string               $owner        The person who set the goal.
	 * @param array<string, mixed> $check        The check.
	 * @param string               $answer       The turn's answer.
	 * @param string               $organisation The organisation.
	 *
	 * @return array{reached: bool, value: int|null, summary: string}
	 */
	private function checkAs(string $owner, array $check, string $answer, string $organisation): array {
		$user = $this->userManager->get($owner);
		if ($user === null) {
			return ['reached' => false, 'value' => null, 'summary' => 'The person who set the goal no longer exists.'];
		}

		$prior = $this->userSession->getUser();
		$this->userSession->setUser($user);
		try {
			return $this->checks->check(check: $check, lastAnswer: $answer, organisation: $organisation);
		} finally {
			$this->userSession->setUser($prior);
		}
	}//end checkAs()

	/**
	 * Tell the goal's owner it was reached or exhausted.
	 *
	 * @param string       $owner     The person.
	 * @param ObjectEntity $goal      The goal.
	 * @param string       $subject   goal_reached or goal_exhausted.
	 * @param string       $statement The goal's statement.
	 *
	 * @return void
	 */
	private function notify(string $owner, ObjectEntity $goal, string $subject, string $statement): void {
		try {
			$notification = $this->notifications->createNotification();
			$notification->setApp('hermiq')
				->setUser($owner)
				->setDateTime(new DateTime())
				->setObject('goal', (string)$goal->getUuid())
				->setSubject($subject, ['name' => $statement, 'sessionId' => (string)($goal->getObject()['sessionId'] ?? '')]);
			$this->notifications->notify($notification);
		} catch (Throwable $e) {
			$this->logger->warning('[hermiq] Goal notification failed: ' . $e->getMessage());
		}
	}//end notify()

	/**
	 * Whether a person may stop a goal: who set it, or the agent's owner.
	 *
	 * @param ObjectEntity $goal The goal.
	 * @param string       $uid  The person.
	 *
	 * @return bool
	 */
	private function mayStop(ObjectEntity $goal, string $uid): bool {
		if ((string)($goal->getObject()['setBy'] ?? '') === $uid) {
			return true;
		}

		$agent = $this->objectService->find(
			id: (string)($goal->getObject()['agentId'] ?? ''),
			register: self::REGISTER,
			schema: self::AGENT_SCHEMA,
			_rbac: false,
			_multitenancy: false
		);

		return $agent !== null && (string)($agent->getOwner() ?? '') === $uid;
	}//end mayStop()

	/**
	 * A check the dispatcher can run, or null.
	 *
	 * @param array<string, mixed> $check The proposed check.
	 *
	 * @return array<string, mixed>|null
	 */
	private function cleanCheck(array $check): ?array {
		$kind = (string)($check['kind'] ?? '');
		if ($kind === 'objectCount') {
			return $this->cleanObjectCountCheck(check: $check);
		}

		if ($kind === 'judge') {
			return $this->cleanJudgeCheck(check: $check);
		}

		return null;
	}//end cleanCheck()

	/**
	 * An objectCount check with its register and schema, normalised; null without them.
	 *
	 * @param array<string, mixed> $check The requested check.
	 *
	 * @return array<string, mixed>|null The clean check, or null.
	 */
	private function cleanObjectCountCheck(array $check): ?array {
		if ((string)($check['register'] ?? '') === '' || (string)($check['schema'] ?? '') === '') {
			return null;
		}

		return [
			'kind' => 'objectCount',
			'register' => (string)$check['register'],
			'schema' => (string)$check['schema'],
			'filters' => (array)($check['filters'] ?? []),
			'target' => max(0, (int)($check['target'] ?? 0)),
		];

	}//end cleanObjectCountCheck()

	/**
	 * A judge check with its trimmed question; null for a blank question.
	 *
	 * @param array<string, mixed> $check The requested check.
	 *
	 * @return array<string, string>|null The clean check, or null.
	 */
	private function cleanJudgeCheck(array $check): ?array {
		if (trim((string)($check['question'] ?? '')) === '') {
			return null;
		}

		return ['kind' => 'judge', 'question' => trim((string)$check['question'])];

	}//end cleanJudgeCheck()

	/**
	 * The session's active goal, or null.
	 *
	 * @param string $sessionId The session.
	 *
	 * @return ObjectEntity|null
	 */
	private function activeGoalOf(string $sessionId): ?ObjectEntity {
		foreach ($this->goals(filters: ['sessionId' => $sessionId, 'status' => 'active']) as $goal) {
			return $goal;
		}

		return null;
	}//end activeGoalOf()

	/**
	 * Goals matching filters, read as the system (callers check the person).
	 *
	 * @param array<string, string> $filters The filters.
	 *
	 * @return array<int, ObjectEntity>
	 */
	private function goals(array $filters): array {
		$found = $this->objectService->setRegister(self::REGISTER)->setSchema(self::GOAL_SCHEMA)->findAll(
			config: ['filters' => $filters, 'limit' => 500],
			_rbac: false,
			_multitenancy: false
		);

		return array_values(
			array_filter(
				$found,
				fn (mixed $goal): bool => $goal instanceof ObjectEntity && $this->matchesFilters(data: $goal->getObject(), filters: $filters)
			)
		);
	}//end goals()

	/**
	 * Whether a goal's fields equal every filter value, compared as strings.
	 *
	 * @param array<string, mixed> $data The goal's fields.
	 * @param array<string, mixed> $filters The filters.
	 *
	 * @return bool True when every filter matches.
	 */
	private function matchesFilters(array $data, array $filters): bool {
		$keep = true;
		foreach ($filters as $key => $value) {
			$keep = $keep && (string)($data[$key] ?? '') === $value;
		}

		return $keep;

	}//end matchesFilters()

	/**
	 * Save a goal's data back as the system.
	 *
	 * @param ObjectEntity         $goal The goal.
	 * @param array<string, mixed> $data Its new data.
	 *
	 * @return ObjectEntity
	 */
	private function persist(ObjectEntity $goal, array $data): ObjectEntity {
		unset($data['@self'], $data['id']);

		return $this->objectService->saveObject(
			object: $data,
			register: self::REGISTER,
			schema: self::GOAL_SCHEMA,
			uuid: (string)$goal->getUuid(),
			_rbac: false,
			_multitenancy: false
		);
	}//end persist()

	/**
	 * A goal as the page reads it.
	 *
	 * @param ObjectEntity $goal The goal.
	 *
	 * @return array<string, mixed>
	 */
	private function view(ObjectEntity $goal): array {
		return ['id' => (string)$goal->getUuid()] + $goal->getObject();
	}//end view()

	/**
	 * An integer clamped to a range.
	 *
	 * @param mixed $value The value.
	 * @param int   $min   The lowest.
	 * @param int   $max   The highest.
	 *
	 * @return int
	 */
	private function clamp(mixed $value, int $min, int $max): int {
		return max($min, min($max, (int)$value));
	}//end clamp()

	/**
	 * Now, in UTC.
	 *
	 * @return DateTimeImmutable
	 */
	private function now(): DateTimeImmutable {
		return new DateTimeImmutable('now', new DateTimeZone('UTC'));
	}//end now()
}//end class

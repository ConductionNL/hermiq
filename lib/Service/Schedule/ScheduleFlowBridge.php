<?php

/**
 * Mirrors hermiq schedules onto OpenRegister's schedule trigger.
 *
 * The seam of schedules-onto-engine-triggers: for every schedule whose
 * cadence a 5-field, timezone-safe cron can carry, this bridge keeps exactly
 * one OpenRegister flow (`openregister.trigger-schedule` ->
 * `hermiq.schedule-dispatch` -> `openregister.end`) in step with the
 * schedule, and writes the flow's uuid back onto the schedule as
 * `engineFlowId`. From that moment the ENGINE owns the schedule's clock and
 * the app-local dispatcher stops selecting it as due by nextRun; the
 * schedule's governance (kill switch, budget, approval, retry, delivery,
 * audit) is untouched because the dispatch node re-enters
 * `ScheduleService::runNow()`.
 *
 * Shapes the engine cannot time yet (once, inexpressible intervals,
 * timezone-sensitive crons) are left on the local dispatcher on purpose;
 * the change's design.md carries the full map and the staged phases.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Schedule
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/schedules-onto-engine-triggers/specs/schedule-engine-delegation/spec.md#requirement-eligible-schedules-delegate-their-clock-to-the-engine
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Schedule;

use DateTime;
use OCA\Hermiq\AppInfo\Application;
use OCA\Hermiq\Service\ScheduleService;
use OCA\OpenRegister\Db\Flow;
use OCA\OpenRegister\Db\FlowMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IUserManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Keeps each eligible schedule's mirror flow in step with the schedule.
 *
 * ELIGIBILITY IS THE WHOLE DECISION SURFACE of this class, so it is one
 * method (`ineligibilityReason()`) that answers with a reason instead of a
 * boolean: the sync log then says WHY a schedule stayed local, which is the
 * difference between a staged phase and a silent no-op.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) The bridge coordinates the
 *   schedule store, the flow store, identity resolution and the delegation
 *   marker; each collaborator is a distinct seam.
 *
 * @spec openspec/changes/schedules-onto-engine-triggers/specs/schedule-engine-delegation/spec.md#requirement-eligible-schedules-delegate-their-clock-to-the-engine
 */
class ScheduleFlowBridge {

	/**
	 * OpenRegister register slug that holds hermiq schedule objects.
	 *
	 * @var string
	 */
	private const REGISTER_SLUG = 'hermiq';

	/**
	 * OpenRegister schema slug for schedule objects.
	 *
	 * @var string
	 */
	private const SCHEMA_SLUG = 'schedule';

	/**
	 * OpenRegister schema slug for agent objects (actingUser resolution).
	 *
	 * @var string
	 */
	private const AGENT_SCHEMA = 'agent';

	/**
	 * The applicationSlug every mirror flow carries, so mirrors are
	 * selectable as one family and never mistaken for authored flows.
	 *
	 * @var string
	 */
	private const APPLICATION_SLUG = 'hermiq-schedules';

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService Reads schedules and agents.
	 * @param ScheduleService $scheduleService Writes/clears the delegation marker
	 *                                         through the sanitised persist.
	 * @param ScheduleCadenceMapper $cadenceMapper The shape map: which timing
	 *                                             shapes the engine can carry,
	 *                                             and as which cron.
	 * @param ContainerInterface $container Lazy FlowMapper resolution: an instance
	 *                                      whose OpenRegister predates the flow
	 *                                      store must keep booting, so the flow
	 *                                      classes are never hard constructor
	 *                                      dependencies.
	 * @param IUserManager $userManager Proves a resolved runAs names a live user.
	 * @param LoggerInterface $logger Sync diagnostics.
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly ScheduleService $scheduleService,
		private readonly ScheduleCadenceMapper $cadenceMapper,
		private readonly ContainerInterface $container,
		private readonly IUserManager $userManager,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Bring every schedule's delegation in step with the engine, once.
	 *
	 * Called from the dispatch tick (arming: a schedule created since the
	 * last tick is mirrored within one poll interval) and from the
	 * migration repair step (in-flight schedules on upgrade). Idempotent by
	 * construction: a mirrored, undrifted schedule is a no-op.
	 *
	 * Per-schedule isolation mirrors the tick loop: one bad schedule must
	 * not block the sync for the rest.
	 *
	 * @return array{mirrored:int,refreshed:int,retired:int,skipped:int,failed:int} Counts of what the pass did.
	 *
	 * @spec openspec/changes/schedules-onto-engine-triggers/specs/schedule-engine-delegation/spec.md#requirement-eligible-schedules-delegate-their-clock-to-the-engine
	 * @spec openspec/changes/schedules-onto-engine-triggers/specs/schedule-engine-delegation/spec.md#requirement-in-flight-schedules-migrate-and-roll-back
	 */
	public function syncAll(): array {
		$stats = ['mirrored' => 0, 'refreshed' => 0, 'retired' => 0, 'skipped' => 0, 'failed' => 0];

		if (class_exists(FlowMapper::class) === false) {
			// An OpenRegister without the flow store: every schedule stays on
			// the local dispatcher, which still works exactly as before.
			return $stats;
		}

		try {
			$schedules = $this->objectService
				->setRegister(self::REGISTER_SLUG)
				->setSchema(self::SCHEMA_SLUG)
				->findAll(config: ['limit' => 1000], _rbac: false, _multitenancy: false);
		} catch (Throwable $e) {
			$this->logger->error(
				'[hermiq] Schedule flow sync could not load schedules: ' . $e->getMessage(),
				['exception' => $e]
			);
			$stats['failed']++;
			return $stats;
		}

		foreach ($schedules as $schedule) {
			if (($schedule instanceof ObjectEntity) === false) {
				continue;
			}

			try {
				$outcome = $this->syncOne(schedule: $schedule);
				$stats[$outcome]++;
			} catch (Throwable $e) {
				$stats['failed']++;
				$this->logger->warning(
					sprintf(
						'[hermiq] Schedule flow sync failed for %s: %s',
						(string)$schedule->getUuid(),
						$e->getMessage()
					),
					['exception' => $e]
				);
			}
		}

		return $stats;
	}//end syncAll()

	/**
	 * Sync one schedule: mirror, refresh, retire, or leave alone.
	 *
	 * @param ObjectEntity $schedule The schedule to sync.
	 *
	 * @return string One of mirrored|refreshed|retired|skipped.
	 *
	 * @spec openspec/changes/schedules-onto-engine-triggers/specs/schedule-engine-delegation/spec.md#requirement-eligible-schedules-delegate-their-clock-to-the-engine
	 */
	private function syncOne(ObjectEntity $schedule): string {
		$data = $schedule->getObject();
		$delegatedTo = trim((string)($data['engineFlowId'] ?? ''));
		$reason = $this->ineligibilityReason(schedule: $schedule);

		if ($reason !== null) {
			if ($delegatedTo !== '') {
				// The schedule was edited into a shape the engine cannot time:
				// take the clock back. Marker first, flow second, so a crash
				// in between leaves a refusing mirror, never a double clock.
				$this->retire(schedule: $schedule, flowId: $delegatedTo);
				return 'retired';
			}

			return 'skipped';
		}

		if ($delegatedTo === '') {
			$this->mirror(schedule: $schedule);
			return 'mirrored';
		}

		return $this->refresh(schedule: $schedule, flowId: $delegatedTo);
	}//end syncOne()

	/**
	 * Why this schedule cannot delegate its clock, or null when it can.
	 *
	 * @param ObjectEntity $schedule The schedule to judge.
	 *
	 * @return string|null The reason it stays local, or null when eligible.
	 *
	 * @spec openspec/changes/schedules-onto-engine-triggers/specs/schedule-engine-delegation/spec.md#requirement-eligible-schedules-delegate-their-clock-to-the-engine
	 */
	public function ineligibilityReason(ObjectEntity $schedule): ?string {
		$data = $schedule->getObject();

		if (trim((string)($schedule->getOrganisation() ?? '')) === '') {
			// A flow row without an organisation is invisible to every tenant
			// forever (hermiq#140). Refuse to create one.
			return 'the schedule has no organisation, and an organisation-less flow row is unreachable';
		}

		if ($this->resolveRunAs(schedule: $schedule) === null) {
			return 'no live acting identity resolves (agent actingUser and owner both name nobody)';
		}

		if ($this->mappedCron(schedule: $schedule) === null) {
			return $this->cadenceMapper->gapReason(data: $data);
		}

		return null;
	}//end ineligibilityReason()

	/**
	 * The cron expression the engine should fire this schedule on, or null.
	 *
	 * Delegates to the ScheduleCadenceMapper, which owns the shape map; kept
	 * on the bridge so its callers (the sync pass, the tests) keep one seam.
	 *
	 * @param ObjectEntity $schedule The schedule.
	 *
	 * @return string|null The 5-field cron, or null when no safe form exists.
	 *
	 * @spec openspec/changes/schedules-onto-engine-triggers/specs/schedule-engine-delegation/spec.md#requirement-eligible-schedules-delegate-their-clock-to-the-engine
	 */
	public function mappedCron(ObjectEntity $schedule): ?string {
		return $this->cadenceMapper->mappedCron(schedule: $schedule);
	}//end mappedCron()

	/**
	 * The identity a mirrored run acts as, or null when nobody live resolves.
	 *
	 * The bound agent's `actingUser` when it names a live, enabled user,
	 * otherwise the schedule's owner: exactly the resolution the dispatch
	 * path applies at run time, computed here at mirror time so the trigger
	 * node can declare it. This mirrors EXISTING consent (the owner authored
	 * the schedule as a standing unattended instruction); it never widens
	 * (ADR-099). OpenRegister re-resolves at every firing and fails closed
	 * on a gone or disabled user, and `runNow()` re-resolves the acting user
	 * again, so a drifted declaration can delay a run but never widen one.
	 *
	 * @param ObjectEntity $schedule The schedule.
	 *
	 * @return string|null The uid to declare as runAs, or null.
	 *
	 * @spec openspec/changes/schedules-onto-engine-triggers/specs/schedule-engine-delegation/spec.md#requirement-a-mirrored-run-declares-and-re-enters-its-governed-identity
	 */
	public function resolveRunAs(ObjectEntity $schedule): ?string {
		$data = $schedule->getObject();

		$actingUser = $this->agentActingUser(agentId: (string)($data['agentId'] ?? ''));
		if ($actingUser !== null && $this->isLiveUser(uid: $actingUser) === true) {
			return $actingUser;
		}

		$owner = trim((string)($schedule->getOwner() ?? ''));
		if ($owner !== '' && $this->isLiveUser(uid: $owner) === true) {
			return $owner;
		}

		return null;
	}//end resolveRunAs()

	/**
	 * The bound agent's raw actingUser, or null when unset or unreadable.
	 *
	 * @param string $agentId The agent uuid.
	 *
	 * @return string|null The actingUser, or null.
	 *
	 * @spec openspec/changes/schedules-onto-engine-triggers/specs/schedule-engine-delegation/spec.md#requirement-a-mirrored-run-declares-and-re-enters-its-governed-identity
	 */
	private function agentActingUser(string $agentId): ?string {
		if ($agentId === '') {
			return null;
		}

		try {
			$agent = $this->objectService->find(
				id: $agentId,
				register: self::REGISTER_SLUG,
				schema: self::AGENT_SCHEMA,
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			return null;
		}

		if ($agent === null) {
			return null;
		}

		$actingUser = trim((string)($agent->getObject()['actingUser'] ?? ''));
		if ($actingUser === '') {
			return null;
		}

		return $actingUser;
	}//end agentActingUser()

	/**
	 * Whether the uid names an existing, enabled user.
	 *
	 * @param string $uid The uid to check.
	 *
	 * @return boolean Whether the user is live.
	 *
	 * @spec openspec/changes/schedules-onto-engine-triggers/specs/schedule-engine-delegation/spec.md#requirement-a-mirrored-run-declares-and-re-enters-its-governed-identity
	 */
	private function isLiveUser(string $uid): bool {
		$user = $this->userManager->get($uid);

		return ($user !== null && $user->isEnabled() === true);
	}//end isLiveUser()

	/**
	 * Create the mirror flow and hand the schedule's clock to the engine.
	 *
	 * Insert FIRST, mark SECOND: a crash in between leaves a flow whose
	 * dispatch node refuses to fire (the schedule does not name it), which
	 * the node then disables. The failure mode is a loud dead flow, never a
	 * double clock.
	 *
	 * @param ObjectEntity $schedule The eligible, unmirrored schedule.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/schedules-onto-engine-triggers/specs/schedule-engine-delegation/spec.md#requirement-eligible-schedules-delegate-their-clock-to-the-engine
	 */
	private function mirror(ObjectEntity $schedule): void {
		$mapper = $this->container->get(FlowMapper::class);
		$data = $schedule->getObject();
		$cron = (string)$this->mappedCron(schedule: $schedule);
		$runAs = (string)$this->resolveRunAs(schedule: $schedule);
		$uuid = $this->newUuid();

		$flow = new Flow();
		$flow->setUuid($uuid);
		$flow->setApp(Application::APP_ID);
		$flow->setName('Schedule mirror: ' . (string)($data['name'] ?? (string)$schedule->getUuid()));
		$flow->setDescription(
			'Mirror of hermiq schedule ' . (string)$schedule->getUuid()
			. ' (schedules-onto-engine-triggers). The engine owns the clock; the dispatch node'
			. ' re-enters the governed run path. Managed by the ScheduleFlowBridge: edits here'
			. ' are overwritten on its next sync pass.'
		);
		$flow->setTrigger('schedule');
		$flow->setTriggerRegister(null);
		$flow->setTriggerSchema(null);
		$flow->setCron($cron);
		$flow->setNodes($this->nodes(scheduleUuid: (string)$schedule->getUuid(), cron: $cron, runAs: $runAs));
		$flow->setEdges($this->edges());
		$flow->setLimits(['maxNodes' => 5, 'maxIterations' => 5]);
		$flow->setApplicationSlug(self::APPLICATION_SLUG);
		$flow->setEnabled((($data['enabled'] ?? false) === true));
		$flow->setOwner((string)($schedule->getOwner() ?? ''));
		$flow->setOrganisation((string)$schedule->getOrganisation());
		$flow->setCreated(new DateTime());
		$flow->setUpdated(new DateTime());

		$mapper->insert($flow);

		$this->scheduleService->markEngineDelegation(schedule: $schedule, flowId: $uuid);

		$this->logger->info(
			sprintf('[hermiq] Schedule %s delegated its clock to flow %s.', (string)$schedule->getUuid(), $uuid)
		);

	}//end mirror()

	/**
	 * Refresh a mirrored schedule's flow when its timing or state drifted.
	 *
	 * A mirror whose flow row is GONE (crashed rollback, manual deletion) is
	 * re-mirrored: the marker is cleared through the sanitised persist and
	 * the next sync pass creates a fresh flow, rather than leaving a
	 * delegated schedule with no clock at all.
	 *
	 * @param ObjectEntity $schedule The mirrored schedule.
	 * @param string $flowId The mirror flow's uuid.
	 *
	 * @return string One of refreshed|skipped.
	 *
	 * @spec openspec/changes/schedules-onto-engine-triggers/specs/schedule-engine-delegation/spec.md#requirement-eligible-schedules-delegate-their-clock-to-the-engine
	 */
	private function refresh(ObjectEntity $schedule, string $flowId): string {
		$mapper = $this->container->get(FlowMapper::class);
		$data = $schedule->getObject();

		try {
			$flow = $mapper->findByUuid($flowId);
		} catch (Throwable $e) {
			// The delegated clock does not exist: take the schedule back so
			// the local dispatcher covers it, and let the next pass re-mirror.
			$this->scheduleService->clearEngineDelegation(schedule: $schedule);
			$this->logger->warning(
				sprintf(
					'[hermiq] Schedule %s was delegated to missing flow %s; the local clock takes over until the next sync.',
					(string)$schedule->getUuid(),
					$flowId
				)
			);

			return 'refreshed';
		}

		$cron = (string)$this->mappedCron(schedule: $schedule);
		$runAs = (string)$this->resolveRunAs(schedule: $schedule);
		$enabled = (($data['enabled'] ?? false) === true);

		$storedCron = trim((string)($flow->getCron() ?? ''));
		$storedRunAs = $this->triggerRunAs(nodes: $flow->getNodes());
		$storedEnabled = ($flow->getEnabled() === true);

		if ($storedCron === $cron && $storedRunAs === $runAs && $storedEnabled === $enabled) {
			return 'skipped';
		}

		$flow->setCron($cron);
		$flow->setNodes($this->nodes(scheduleUuid: (string)$schedule->getUuid(), cron: $cron, runAs: $runAs));
		$flow->setEdges($this->edges());
		$flow->setEnabled($enabled);
		$flow->setUpdated(new DateTime());
		$mapper->update($flow);

		return 'refreshed';
	}//end refresh()

	/**
	 * Take a schedule's clock back and delete its mirror flow.
	 *
	 * Marker first, flow second (see syncOne). A flow that cannot be
	 * deleted is disabled instead, and either way the schedule is already
	 * back on the local clock.
	 *
	 * @param ObjectEntity $schedule The schedule to take back.
	 * @param string $flowId The mirror flow's uuid.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/schedules-onto-engine-triggers/specs/schedule-engine-delegation/spec.md#requirement-in-flight-schedules-migrate-and-roll-back
	 */
	public function retire(ObjectEntity $schedule, string $flowId): void {
		$this->scheduleService->clearEngineDelegation(schedule: $schedule);
		$this->deleteFlow(flowId: $flowId);

	}//end retire()

	/**
	 * Delete a mirror flow row; fall back to disabling it.
	 *
	 * @param string $flowId The flow uuid.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/schedules-onto-engine-triggers/specs/schedule-engine-delegation/spec.md#requirement-in-flight-schedules-migrate-and-roll-back
	 */
	public function deleteFlow(string $flowId): void {
		try {
			$mapper = $this->container->get(FlowMapper::class);
			$flow = $mapper->findByUuid($flowId);
		} catch (Throwable $e) {
			// Already gone: exactly the end state a delete wants.
			return;
		}

		try {
			$mapper->delete($flow);
		} catch (Throwable $e) {
			$this->logger->warning(
				sprintf('[hermiq] Could not delete mirror flow %s, disabling it instead: %s', $flowId, $e->getMessage()),
				['exception' => $e]
			);
			try {
				$flow->setEnabled(false);
				$mapper->update($flow);
			} catch (Throwable $inner) {
				$this->logger->error(
					sprintf('[hermiq] Could not disable mirror flow %s either: %s', $flowId, $inner->getMessage()),
					['exception' => $inner]
				);
			}
		}//end try

	}//end deleteFlow()

	/**
	 * The trigger node's declared runAs in a stored node list.
	 *
	 * @param mixed $nodes The stored nodes.
	 *
	 * @return string The declared runAs, or ''.
	 *
	 * @spec openspec/changes/schedules-onto-engine-triggers/specs/schedule-engine-delegation/spec.md#requirement-a-mirrored-run-declares-and-re-enters-its-governed-identity
	 */
	private function triggerRunAs(mixed $nodes): string {
		if (is_array($nodes) === false) {
			return '';
		}

		foreach ($nodes as $node) {
			if (is_array($node) === true && ($node['type'] ?? null) === 'openregister.trigger-schedule') {
				$config = ($node['config'] ?? []);
				if (is_array($config) === true) {
					return trim((string)($config['runAs'] ?? ''));
				}
			}
		}

		return '';
	}//end triggerRunAs()

	/**
	 * The mirror flow's three nodes.
	 *
	 * @param string $scheduleUuid The schedule uuid the dispatch node fires.
	 * @param string $cron The 5-field cron expression.
	 * @param string $runAs The declared acting identity.
	 *
	 * @return array<int,array<string,mixed>> The nodes.
	 *
	 * @spec openspec/changes/schedules-onto-engine-triggers/specs/schedule-engine-delegation/spec.md#requirement-eligible-schedules-delegate-their-clock-to-the-engine
	 */
	private function nodes(string $scheduleUuid, string $cron, string $runAs): array {
		return [
			[
				'id' => 'trigger',
				'position' => ['x' => 0, 'y' => 0],
				'type' => 'openregister.trigger-schedule',
				'config' => ['cron' => $cron, 'runAs' => $runAs],
			],
			[
				'id' => 'dispatch',
				'position' => ['x' => 260, 'y' => 0],
				'type' => 'hermiq.schedule-dispatch',
				'config' => ['scheduleId' => $scheduleUuid],
			],
			[
				'id' => 'done',
				'position' => ['x' => 520, 'y' => 0],
				'type' => 'openregister.end',
				'config' => [],
			],
		];
	}//end nodes()

	/**
	 * The mirror flow's two edges.
	 *
	 * @return array<int,array<string,string>> The edges.
	 *
	 * @spec openspec/changes/schedules-onto-engine-triggers/specs/schedule-engine-delegation/spec.md#requirement-eligible-schedules-delegate-their-clock-to-the-engine
	 */
	private function edges(): array {
		return [
			[
				'id' => 'trigger-dispatch',
				'from' => 'trigger',
				'to' => 'dispatch',
				'title' => 'due',
			],
			[
				'id' => 'dispatch-done',
				'from' => 'dispatch',
				'to' => 'done',
				'title' => 'dispatched',
			],
		];
	}//end edges()

	/**
	 * A fresh v4 uuid for a mirror flow row.
	 *
	 * @return string The uuid.
	 *
	 * @spec openspec/changes/schedules-onto-engine-triggers/specs/schedule-engine-delegation/spec.md#requirement-eligible-schedules-delegate-their-clock-to-the-engine
	 */
	private function newUuid(): string {
		$bytes = random_bytes(16);
		$bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
		$bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

		return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
	}//end newUuid()
}//end class

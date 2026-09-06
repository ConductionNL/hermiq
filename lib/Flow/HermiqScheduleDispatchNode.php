<?php

/**
 * The schedule dispatch step, contributed to OpenRegister's flow engine.
 *
 * This node is what lets the engine own a hermiq schedule's CLOCK without
 * owning its GOVERNANCE. A mirrored schedule's flow is
 * `openregister.trigger-schedule -> hermiq.schedule-dispatch -> openregister.end`,
 * and this step re-enters `ScheduleService::runNow()` — the same private
 * dispatch path a local tick used. So the kill switch, the budget hard cap,
 * the approval gate, commit-before-run, retry bookkeeping, delivery and the
 * per-run audit entry all apply byte-for-byte (schedules-onto-engine-triggers).
 *
 * The alternative — triggering straight into `hermiq.agent-step` — was
 * rejected in the change's design: it would drop the approval gate, the
 * budget cap, delivery and retry for exactly the runs that had them.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Flow
 * @package  OCA\Hermiq\Flow
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/schedules-onto-engine-triggers/specs/schedule-engine-delegation/spec.md#requirement-a-mirrored-run-declares-and-re-enters-its-governed-identity
 */

declare(strict_types=1);

namespace OCA\Hermiq\Flow;

use OCA\Hermiq\Service\ScheduleService;
use OCA\OpenRegister\Db\FlowMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Flow\IFlowNode;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\WorkflowEngine\IManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;
use UnexpectedValueException;

/**
 * Fires one hermiq schedule occurrence from an engine-timed flow.
 *
 * WHY A STALE DELEGATION REFUSES INSTEAD OF RUNNING. The schedule stores the
 * uuid of the ONE flow that owns its clock (`engineFlowId`). A firing flow
 * whose uuid the schedule no longer carries is a leftover: a crashed
 * rollback, a re-mirror, or a schedule someone deleted and recreated. Running
 * it would give the schedule two clocks — the exact double-fire the marker
 * exists to prevent — so the step disables the stale flow and fails loudly.
 *
 * @spec openspec/changes/schedules-onto-engine-triggers/specs/schedule-engine-delegation/spec.md#requirement-a-mirrored-run-declares-and-re-enters-its-governed-identity
 */
class HermiqScheduleDispatchNode implements IFlowNode {

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
	 * Constructor.
	 *
	 * @param ScheduleService $scheduleService The governed dispatch path (runNow).
	 * @param ObjectService $objectService Loads the schedule object.
	 * @param ContainerInterface $container Lazy FlowMapper resolution, so a stale
	 *                                      mirror can be disabled without making
	 *                                      the flow store a hard constructor
	 *                                      dependency of every node resolution.
	 * @param LoggerInterface $logger Diagnostics.
	 * @param IL10N $l10n Translations.
	 * @param IURLGenerator $urls For the palette icon.
	 */
	public function __construct(
		private readonly ScheduleService $scheduleService,
		private readonly ObjectService $objectService,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
		private readonly IL10N $l10n,
		private readonly IURLGenerator $urls,
	) {

	}//end __construct()

	/**
	 * The step type.
	 *
	 * @return string The id.
	 *
	 * @spec openspec/changes/schedules-onto-engine-triggers/specs/schedule-engine-delegation/spec.md#requirement-a-mirrored-run-declares-and-re-enters-its-governed-identity
	 */
	public function getId(): string {
		return 'hermiq.schedule-dispatch';
	}//end getId()

	/**
	 * Palette name.
	 *
	 * @return string The display name.
	 *
	 * @spec openspec/changes/schedules-onto-engine-triggers/specs/schedule-engine-delegation/spec.md#requirement-a-mirrored-run-declares-and-re-enters-its-governed-identity
	 */
	public function getDisplayName(): string {
		return $this->l10n->t('Run a schedule');
	}//end getDisplayName()

	/**
	 * Palette description.
	 *
	 * @return string The description.
	 *
	 * @spec openspec/changes/schedules-onto-engine-triggers/specs/schedule-engine-delegation/spec.md#requirement-a-mirrored-run-declares-and-re-enters-its-governed-identity
	 */
	public function getDescription(): string {
		return $this->l10n->t('Fire one occurrence of a hermiq schedule. Every gate the schedule has still applies.');
	}//end getDescription()

	/**
	 * Palette icon.
	 *
	 * @return string The icon URL.
	 *
	 * @spec openspec/changes/schedules-onto-engine-triggers/specs/schedule-engine-delegation/spec.md#requirement-a-mirrored-run-declares-and-re-enters-its-governed-identity
	 */
	public function getIcon(): string {
		return $this->urls->imagePath('hermiq', 'app-dark.svg');
	}//end getIcon()

	/**
	 * Available in both scopes; the dispatch path enforces its own gates.
	 *
	 * @param int $scope The scope constant.
	 *
	 * @return boolean Whether it is available.
	 *
	 * @spec openspec/changes/schedules-onto-engine-triggers/specs/schedule-engine-delegation/spec.md#requirement-a-mirrored-run-declares-and-re-enters-its-governed-identity
	 */
	public function isAvailableForScope(int $scope): bool {
		return in_array($scope, [IManager::SCOPE_ADMIN, IManager::SCOPE_USER], true);
	}//end isAvailableForScope()

	/**
	 * Reject a dispatch step that names no schedule.
	 *
	 * @param array $config The step configuration.
	 *
	 * @return void
	 *
	 * @throws UnexpectedValueException When no schedule is named.
	 *
	 * @spec openspec/changes/schedules-onto-engine-triggers/specs/schedule-engine-delegation/spec.md#requirement-a-mirrored-run-declares-and-re-enters-its-governed-identity
	 */
	public function validateConfig(array $config): void {
		if (trim((string)($config['scheduleId'] ?? '')) === '') {
			throw new UnexpectedValueException($this->l10n->t('A schedule dispatch step needs a schedule.'));
		}

	}//end validateConfig()

	/**
	 * Fire the schedule's occurrence through the governed dispatch path.
	 *
	 * One occurrence per FIRING, not per item: a schedule trigger starts its
	 * run with a single empty item, and a schedule is not a collection to fan
	 * out over. The occurrence's outcome (`ok`, `error`, `awaiting_approval`,
	 * `skipped_killswitch`, `skipped_budget`) is put on each item as
	 * `scheduleStatus` so the run log shows what the occurrence did.
	 *
	 * A DOMAIN-RECORDED failure is a step SUCCESS on purpose. `runNow()`
	 * catches an agent-turn error, records `lastStatus='error'` on the
	 * schedule, writes the audit entry and arms the schedule's own
	 * retry/dead-letter machinery. Failing the step too would double-report
	 * the same failure and mark the flow run failed for an occurrence the
	 * domain layer is already handling. Only a failure that escapes
	 * `runNow()` — one the domain layer could NOT record — fails the step.
	 *
	 * @param array $items The input items.
	 * @param array $config The step configuration.
	 * @param array $context Run-level metadata (carries the firing flow's id).
	 *
	 * @return array The items, each carrying the occurrence's status.
	 *
	 * @throws UnexpectedValueException When the schedule is missing, disabled,
	 *                                  or no longer delegated to this flow.
	 * @throws Throwable When the dispatch itself fails catastrophically.
	 *
	 * @spec openspec/changes/schedules-onto-engine-triggers/specs/schedule-engine-delegation/spec.md#requirement-a-mirrored-run-declares-and-re-enters-its-governed-identity
	 */
	public function execute(array $items, array $config, array $context): array {
		$scheduleId = trim((string)($config['scheduleId'] ?? ''));
		if ($scheduleId === '') {
			// Same defect class as the agent step: a flow seeded or imported
			// past validateConfig() must not turn this into a silent pass.
			throw new UnexpectedValueException($this->l10n->t('A schedule dispatch step needs a schedule.'));
		}

		$firingFlowId = trim((string)($context['payload']['flowId'] ?? ''));

		$schedule = $this->loadSchedule(scheduleId: $scheduleId);
		if ($schedule === null) {
			$this->disableStaleFlow(flowId: $firingFlowId, reason: 'its schedule no longer exists');
			throw new UnexpectedValueException(
				$this->l10n->t('Schedule %s no longer exists. The mirror flow has been disabled.', [$scheduleId])
			);
		}

		$data = $schedule->getObject();
		$delegatedTo = trim((string)($data['engineFlowId'] ?? ''));

		// The schedule names the ONE flow that owns its clock. A firing flow it
		// does not name is stale (crashed rollback, re-mirror): running it
		// would be a second clock. Refuse, and switch the leftover off.
		if ($delegatedTo === '' || ($firingFlowId !== '' && $firingFlowId !== $delegatedTo)) {
			$this->disableStaleFlow(flowId: $firingFlowId, reason: 'the schedule is no longer delegated to it');
			throw new UnexpectedValueException(
				$this->l10n->t('Schedule %s is not delegated to this flow any more.', [$scheduleId])
			);
		}

		if (($data['enabled'] ?? false) !== true) {
			// The bridge's next sync pass disables the mirror flow; until then
			// a paused schedule must simply not run.
			throw new UnexpectedValueException(
				$this->l10n->t('Schedule %s is disabled.', [$scheduleId])
			);
		}

		$this->scheduleService->runNow(schedule: $schedule);

		$status = $this->refreshedStatus(scheduleId: $scheduleId);

		$out = [];
		foreach ($items as $index => $item) {
			$json = (array)($item['json'] ?? []);
			$json['scheduleId'] = $scheduleId;
			$json['scheduleStatus'] = $status;

			$out[] = [
				'json' => $json,
				'binary' => (array)($item['binary'] ?? []),
				'pairedItem' => ['item' => $index],
			];
		}

		return $out;
	}//end execute()

	/**
	 * Load the schedule object, or null when it cannot be found.
	 *
	 * @param string $scheduleId The schedule uuid.
	 *
	 * @return ObjectEntity|null The schedule, or null.
	 *
	 * @spec openspec/changes/schedules-onto-engine-triggers/specs/schedule-engine-delegation/spec.md#requirement-a-mirrored-run-declares-and-re-enters-its-governed-identity
	 */
	private function loadSchedule(string $scheduleId): ?ObjectEntity {
		try {
			$object = $this->objectService->find(
				id: $scheduleId,
				register: self::REGISTER_SLUG,
				schema: self::SCHEMA_SLUG,
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			return null;
		}

		if (($object instanceof ObjectEntity) === false) {
			return null;
		}

		return $object;
	}//end loadSchedule()

	/**
	 * The schedule's lastStatus after the dispatch, re-read from the store.
	 *
	 * Re-read rather than taken from the in-memory entity: `runNow()` persists
	 * its outcome through ObjectService and the entity this step loaded does
	 * not see that write. `unknown` when the re-read fails; the audit entry on
	 * the schedule is the record, this value is a run-log convenience.
	 *
	 * @param string $scheduleId The schedule uuid.
	 *
	 * @return string The refreshed lastStatus, or 'unknown'.
	 *
	 * @spec openspec/changes/schedules-onto-engine-triggers/specs/schedule-engine-delegation/spec.md#requirement-a-mirrored-run-declares-and-re-enters-its-governed-identity
	 */
	private function refreshedStatus(string $scheduleId): string {
		$fresh = $this->loadSchedule(scheduleId: $scheduleId);
		if ($fresh === null) {
			return 'unknown';
		}

		return (string)($fresh->getObject()['lastStatus'] ?? 'unknown');
	}//end refreshedStatus()

	/**
	 * Switch a stale mirror flow off so it stops firing forever.
	 *
	 * Best-effort by contract: the refusal this step throws is the control,
	 * the disable is hygiene. A disable failure is logged, never thrown over
	 * the refusal it accompanies.
	 *
	 * @param string $flowId The firing flow's uuid ('' when unknown).
	 * @param string $reason Why the flow is stale, for the log line.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/schedules-onto-engine-triggers/specs/schedule-engine-delegation/spec.md#requirement-a-mirrored-run-declares-and-re-enters-its-governed-identity
	 */
	private function disableStaleFlow(string $flowId, string $reason): void {
		if ($flowId === '') {
			return;
		}

		try {
			$mapper = $this->container->get(FlowMapper::class);
			$flow = $mapper->findByUuid($flowId);
			$flow->setEnabled(false);
			$mapper->update($flow);
			$this->logger->warning(
				sprintf('[hermiq] Disabled stale schedule mirror flow %s: %s.', $flowId, $reason)
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				sprintf('[hermiq] Could not disable stale schedule mirror flow %s: %s', $flowId, $e->getMessage()),
				['exception' => $e]
			);
		}

	}//end disableStaleFlow()
}//end class

<?php

/**
 * Reads and stores eval schedules, and resolves what a schedule may run.
 *
 * The scheduled job has no user session, so schedules are read and saved as the
 * system. What a schedule runs is resolved under the run endpoint's own owner
 * rule (`SeedCustodyService::actsAsOwner()`): the schedule's owner must own both
 * the dataset and the agent, so a schedule never runs someone else's agent.
 *
 * @category Service
 * @package  OCA\Hermiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/2026-11-24-reuse-scheduled-bias-evals/specs/agent-evals/spec.md#requirement-a-dataset-can-run-against-an-agent-on-a-schedule-req-biaseval-003
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service;

use OCA\Hermiq\Kernel\Contracts\SeedCustodyServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\OpenRegister\Mcp\Db\Agent;
use OCA\OpenRegister\Mcp\Db\AgentMapper;
use OCA\OpenRegister\Service\ObjectService;
use Throwable;

/**
 * Storage and target resolution for `EvalSchedule` objects.
 *
 * @spec openspec/changes/archive/2026-11-24-reuse-scheduled-bias-evals/specs/agent-evals/spec.md#requirement-a-dataset-can-run-against-an-agent-on-a-schedule-req-biaseval-003
 */
class EvalSchedules {

	private const REGISTER = 'hermiq';

	private const SCHEMA = 'evalschedule';

	private const DATASET_SCHEMA = 'evaldataset';

	/**
	 * Constructor.
	 *
	 * @param ObjectService               $objectService Reads and saves schedules and datasets.
	 * @param AgentMapper                 $agentMapper   Resolves the target agent.
	 * @param SeedCustodyServiceInterface $seedCustody   The owner rule of the run endpoint.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-11-24-reuse-scheduled-bias-evals/specs/agent-evals/spec.md#requirement-a-dataset-can-run-against-an-agent-on-a-schedule-req-biaseval-003
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly AgentMapper $agentMapper,
		private readonly SeedCustodyServiceInterface $seedCustody,
	) {
	}//end __construct()

	/**
	 * The enabled schedules.
	 *
	 * @return array<int, ObjectEntity>
	 *
	 * @spec openspec/changes/archive/2026-11-24-reuse-scheduled-bias-evals/specs/agent-evals/spec.md#requirement-a-dataset-can-run-against-an-agent-on-a-schedule-req-biaseval-003
	 */
	public function enabled(): array {
		$found = $this->objectService->setRegister(self::REGISTER)->setSchema(self::SCHEMA)->findAll(
			config: ['filters' => ['enabled' => true], 'limit' => 1000],
			_rbac: false,
			_multitenancy: false
		);

		return array_values(
			array: array_filter(array: $found, callback: static fn (mixed $row): bool => $row instanceof ObjectEntity)
		);
	}//end enabled()

	/**
	 * Store a schedule's payload under its own uuid.
	 *
	 * @param ObjectEntity         $schedule The schedule.
	 * @param array<string, mixed> $data     The payload to store.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-11-24-reuse-scheduled-bias-evals/specs/agent-evals/spec.md#requirement-a-dataset-can-run-against-an-agent-on-a-schedule-req-biaseval-003
	 */
	public function save(ObjectEntity $schedule, array $data): void {
		unset($data['@self'], $data['id']);
		$this->objectService->saveObject(
			object: $data,
			options: new ObjectSaveOptions(
				target: new ObjectFindTarget(register: self::REGISTER, schema: self::SCHEMA),
				uuid: (string)$schedule->getUuid()
			),
			_rbac: false,
			_multitenancy: false
		);
	}//end save()

	/**
	 * The dataset and the agent a schedule runs, when its owner owns both.
	 *
	 * @param ObjectEntity $schedule The schedule.
	 *
	 * @return array{dataset: ObjectEntity, agent: Agent}|null The targets, or null when either is absent or not owned.
	 *
	 * @spec openspec/changes/archive/2026-11-24-reuse-scheduled-bias-evals/specs/agent-evals/spec.md#requirement-a-dataset-can-run-against-an-agent-on-a-schedule-req-biaseval-003
	 */
	public function targets(ObjectEntity $schedule): ?array {
		$owner = (string)$schedule->getOwner();
		$data  = $schedule->getObject();
		if ($owner === '') {
			return null;
		}

		$dataset = $this->dataset(datasetId: (string)($data['datasetId'] ?? ''));
		$agent   = $this->agent(agentId: (string)($data['agentId'] ?? ''));
		if ($dataset === null || $agent === null) {
			return null;
		}

		$ownsDataset = $this->seedCustody->actsAsOwner(owner: $dataset->getOwner(), uid: $owner);
		$ownsAgent   = $this->seedCustody->actsAsOwner(owner: $agent->getOwner(), uid: $owner);
		if ($ownsDataset === false || $ownsAgent === false) {
			return null;
		}

		return ['dataset' => $dataset, 'agent' => $agent];
	}//end targets()

	/**
	 * A dataset by uuid, read as the system.
	 *
	 * @param string $datasetId The dataset uuid.
	 *
	 * @return ObjectEntity|null The dataset, or null when absent.
	 */
	private function dataset(string $datasetId): ?ObjectEntity {
		if ($datasetId === '') {
			return null;
		}

		try {
			return $this->objectService->find(
				id: $datasetId,
				options: new ObjectFindOptions(register: self::REGISTER, schema: self::DATASET_SCHEMA),
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable) {
			return null;
		}
	}//end dataset()

	/**
	 * An agent by uuid.
	 *
	 * @param string $agentId The agent uuid.
	 *
	 * @return Agent|null The agent, or null when absent.
	 */
	private function agent(string $agentId): ?Agent {
		if ($agentId === '') {
			return null;
		}

		try {
			return $this->agentMapper->findByUuid(uuid: $agentId);
		} catch (Throwable) {
			return null;
		}
	}//end agent()
}//end class

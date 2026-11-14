<?php

/**
 * Finds an eval dataset or agent only when a user may act as its owner.
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
 * @spec openspec/changes/reuse-scheduled-bias-evals/specs/agent-evals/spec.md#requirement-a-dataset-can-run-against-an-agent-on-a-schedule-req-biaseval-003
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service;

use OCA\Hermiq\Kernel\Contracts\SeedCustodyServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Mcp\Db\Agent;
use OCA\OpenRegister\Mcp\Db\AgentMapper;
use OCA\OpenRegister\Service\ObjectService;
use Throwable;

/**
 * The ownership lookups the template copy, the eval schedules and the schedule
 * job share. The stored owner passes, and an instance admin passes for a
 * system-seeded object only (SeedCustodyService). A missing object and one the
 * user does not own both answer null, so a caller cannot learn which it was.
 *
 * @spec openspec/changes/reuse-scheduled-bias-evals/specs/agent-evals/spec.md#requirement-a-dataset-can-run-against-an-agent-on-a-schedule-req-biaseval-003
 */
class EvalOwnership {

	/**
	 * OpenRegister register slug that holds Hermiq objects.
	 *
	 * @var string
	 */
	public const REGISTER = 'hermiq';

	/**
	 * Schema slug for EvalDataset objects.
	 *
	 * @var string
	 */
	public const DATASET_SCHEMA = 'evaldataset';

	/**
	 * Constructor.
	 *
	 * @param ObjectService               $objectService Reads the dataset.
	 * @param AgentMapper                 $agentMapper   Reads the agent.
	 * @param SeedCustodyServiceInterface $seedCustody   Decides who acts as owner.
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly AgentMapper $agentMapper,
		private readonly SeedCustodyServiceInterface $seedCustody,
	) {
	}//end __construct()

	/**
	 * A dataset by uuid, read without RBAC, or null when it does not exist.
	 *
	 * @param string $datasetId The dataset uuid.
	 *
	 * @return ObjectEntity|null
	 *
	 * @spec openspec/changes/reuse-scheduled-bias-evals/specs/agent-evals/spec.md#requirement-a-dataset-can-run-against-an-agent-on-a-schedule-req-biaseval-003
	 */
	public function dataset(string $datasetId): ?ObjectEntity {
		if ($datasetId === '') {
			return null;
		}

		try {
			$dataset = $this->objectService->find(
				id: $datasetId,
				register: self::REGISTER,
				schema: self::DATASET_SCHEMA,
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable) {
			return null;
		}

		if (($dataset instanceof ObjectEntity) === false) {
			return null;
		}

		return $dataset;
	}//end dataset()

	/**
	 * A dataset the user may act as owner of, or null.
	 *
	 * @param string $datasetId The dataset uuid.
	 * @param string $uid       The user.
	 *
	 * @return ObjectEntity|null
	 *
	 * @spec openspec/changes/reuse-scheduled-bias-evals/specs/agent-evals/spec.md#requirement-a-dataset-can-run-against-an-agent-on-a-schedule-req-biaseval-003
	 */
	public function ownedDataset(string $datasetId, string $uid): ?ObjectEntity {
		$dataset = $this->dataset(datasetId: $datasetId);
		if ($dataset === null || $uid === '' || $this->seedCustody->actsAsOwner(owner: $dataset->getOwner(), uid: $uid) === false) {
			return null;
		}

		return $dataset;
	}//end ownedDataset()

	/**
	 * An agent the user may act as owner of, or null.
	 *
	 * @param string $agentId The agent uuid.
	 * @param string $uid     The user.
	 *
	 * @return Agent|null
	 *
	 * @spec openspec/changes/reuse-scheduled-bias-evals/specs/agent-evals/spec.md#requirement-a-dataset-can-run-against-an-agent-on-a-schedule-req-biaseval-003
	 */
	public function ownedAgent(string $agentId, string $uid): ?Agent {
		if ($agentId === '' || $uid === '') {
			return null;
		}

		try {
			$agent = $this->agentMapper->findByUuid($agentId);
		} catch (Throwable) {
			return null;
		}

		if ($this->seedCustody->actsAsOwner(owner: $agent->getOwner(), uid: $uid) === false) {
			return null;
		}

		return $agent;
	}//end ownedAgent()
}//end class

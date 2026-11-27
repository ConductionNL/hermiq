<?php

/**
 * Hermiq EvalOwnership.
 *
 * The owner rule of the caller-facing eval endpoints (reuse-scheduled-bias-evals):
 * a dataset is read with the caller's rights, and the caller must act as owner of
 * the dataset or agent they act on (`SeedCustodyService::actsAsOwner()`: the stored
 * owner, or an instance administrator for a system-seeded object). Anything absent,
 * unreadable or owned by someone else reads as null, so a caller learns nothing
 * about another person's objects (OWASP A01 IDOR).
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Eval
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
 * @spec openspec/changes/archive/2026-11-24-reuse-scheduled-bias-evals/specs/agent-evals/spec.md#requirement-a-dataset-can-run-against-an-agent-on-a-schedule-req-biaseval-003
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Eval;

use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Mcp\Db\AgentMapper;
use OCA\OpenRegister\Mcp\Db\Agent;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\Hermiq\Kernel\Contracts\SeedCustodyServiceInterface;
use Throwable;

/**
 * Reads eval objects for a caller and applies the owner rule.
 *
 * @spec openspec/changes/archive/2026-11-24-reuse-scheduled-bias-evals/specs/agent-evals/spec.md#requirement-a-dataset-can-run-against-an-agent-on-a-schedule-req-biaseval-003
 */
class EvalOwnership {

	/**
	 * The register that holds hermiq's objects.
	 *
	 * @var string
	 */
	public const REGISTER = 'hermiq';

	/**
	 * Constructor.
	 *
	 * @param ObjectService               $objectService Reads objects with the caller's rights.
	 * @param AgentMapper                 $agentMapper   Resolves agents.
	 * @param SeedCustodyServiceInterface $seedCustody   The owner rule.
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
	 * An object of a hermiq schema, read with the caller's rights; null when absent or unreadable.
	 *
	 * @param string $schema The schema slug.
	 * @param string $id     The object uuid.
	 *
	 * @return ObjectEntity|null
	 *
	 * @spec openspec/changes/archive/2026-11-24-reuse-scheduled-bias-evals/specs/agent-evals/spec.md#requirement-a-ready-made-bias-test-set-can-be-used-for-an-agent-req-biaseval-002
	 */
	public function readable(string $schema, string $id): ?ObjectEntity {
		if ($id === '') {
			return null;
		}

		try {
			$found = $this->objectService->find(id: $id, options: new ObjectFindOptions(register: self::REGISTER, schema: $schema));
		} catch (Throwable $e) {
			return null;
		}

		if (($found instanceof ObjectEntity) === false) {
			return null;
		}

		return $found;
	}//end readable()

	/**
	 * An object of a hermiq schema the caller acts as owner of; null otherwise.
	 *
	 * @param string $schema The schema slug.
	 * @param string $id     The object uuid.
	 * @param string $uid    The caller.
	 *
	 * @return ObjectEntity|null
	 *
	 * @spec openspec/changes/archive/2026-11-24-reuse-scheduled-bias-evals/specs/agent-evals/spec.md#requirement-a-dataset-can-run-against-an-agent-on-a-schedule-req-biaseval-003
	 */
	public function owned(string $schema, string $id, string $uid): ?ObjectEntity {
		$object = $this->readable(schema: $schema, id: $id);
		if ($object === null || $this->owns(object: $object, uid: $uid) === false) {
			return null;
		}

		return $object;
	}//end owned()

	/**
	 * Whether the caller acts as owner of an object already read.
	 *
	 * @param ObjectEntity $object The object.
	 * @param string       $uid    The caller.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/archive/2026-11-24-reuse-scheduled-bias-evals/specs/agent-evals/spec.md#requirement-a-dataset-can-run-against-an-agent-on-a-schedule-req-biaseval-003
	 */
	public function owns(ObjectEntity $object, string $uid): bool {
		return $this->seedCustody->actsAsOwner(owner: $object->getOwner(), uid: $uid);
	}//end owns()

	/**
	 * The agent, when the caller acts as its owner; null otherwise.
	 *
	 * @param string $agentId The agent uuid.
	 * @param string $uid     The caller.
	 *
	 * @return Agent|null
	 *
	 * @spec openspec/changes/archive/2026-11-24-reuse-scheduled-bias-evals/specs/agent-evals/spec.md#requirement-a-dataset-can-run-against-an-agent-on-a-schedule-req-biaseval-003
	 */
	public function ownedAgent(string $agentId, string $uid): ?Agent {
		$agent = null;
		if ($agentId !== '') {
			try {
				$agent = $this->agentMapper->findByUuid(uuid: $agentId);
			} catch (Throwable $e) {
				$agent = null;
			}
		}

		$owns = ($agent !== null && $this->seedCustody->actsAsOwner(owner: $agent->getOwner(), uid: $uid) === true);
		if ($owns === false) {
			return null;
		}

		return $agent;
	}//end ownedAgent()
}//end class

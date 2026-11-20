<?php

/**
 * Hermiq EvalTemplateCopier.
 *
 * "Use for this agent" (reuse-scheduled-bias-evals, REQ-BIASEVAL-002): copies a
 * seeded, read-only eval dataset template into a new dataset the caller owns and
 * edits, linked to the agent it was made for. The copy keeps the template's
 * cases, description, language and template key, so the factsheet and the
 * compliance dashboard can tell its runs apart as a bias check; it is not a
 * template itself.
 *
 * Security (OWASP A01 IDOR): any signed-in user can read a template, so it is read
 * with the caller's rights; the agent must be one the caller acts as owner of
 * (`SeedCustodyService::actsAsOwner()`, the rule of the eval run endpoint). An
 * unreadable template and an agent the caller does not own both answer null, so the
 * caller learns nothing about another person's agents.
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
 * @spec openspec/changes/reuse-scheduled-bias-evals/specs/agent-evals/spec.md#requirement-a-ready-made-bias-test-set-can-be-used-for-an-agent-req-biaseval-002
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Eval;

use InvalidArgumentException;
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
 * Copies an eval dataset template into an editable dataset for one agent.
 *
 * @spec openspec/changes/reuse-scheduled-bias-evals/specs/agent-evals/spec.md#requirement-a-ready-made-bias-test-set-can-be-used-for-an-agent-req-biaseval-002
 */
class EvalTemplateCopier {

	/**
	 * The register that holds hermiq's datasets.
	 *
	 * @var string
	 */
	private const REGISTER = 'hermiq';

	/**
	 * The dataset schema slug.
	 *
	 * @var string
	 */
	private const SCHEMA = 'evaldataset';

	/**
	 * Constructor.
	 *
	 * @param ObjectService               $objectService Reads the template and saves the copy as the signed-in user.
	 * @param AgentMapper                 $agentMapper   Resolves the agent.
	 * @param SeedCustodyServiceInterface $seedCustody   The owner rule of the eval endpoints.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/reuse-scheduled-bias-evals/specs/agent-evals/spec.md#requirement-a-ready-made-bias-test-set-can-be-used-for-an-agent-req-biaseval-002
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly AgentMapper $agentMapper,
		private readonly SeedCustodyServiceInterface $seedCustody,
	) {
	}//end __construct()

	/**
	 * Whether a dataset is a template that may be copied.
	 *
	 * @param ObjectEntity $dataset The dataset.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/reuse-scheduled-bias-evals/specs/agent-evals/spec.md#requirement-a-ready-made-bias-test-set-can-be-used-for-an-agent-req-biaseval-002
	 */
	public static function isTemplate(ObjectEntity $dataset): bool {
		return ($dataset->getObject()['template'] ?? false) === true;
	}//end isTemplate()

	/**
	 * The dataset a copy is stored as: the template's cases and key, the agent
	 * named in the title, and never a template itself.
	 *
	 * @param array<string, mixed> $template  The template's data.
	 * @param string               $agentId   The agent the copy is made for.
	 * @param string               $agentName The agent's name, for the title.
	 *
	 * @return array<string, mixed> The new dataset's data.
	 *
	 * @spec openspec/changes/reuse-scheduled-bias-evals/specs/agent-evals/spec.md#requirement-a-ready-made-bias-test-set-can-be-used-for-an-agent-req-biaseval-002
	 */
	public static function copyOf(array $template, string $agentId, string $agentName): array {
		// "Bias check: public services (template)" becomes "Bias check: public services — Permit intake helper".
		$title = trim((string)preg_replace('/\s*\([^()]*\)\s*$/u', '', (string)($template['name'] ?? '')));
		if ($agentName !== '') {
			$title .= ' — ' . $agentName;
		}

		return [
			'name'        => $title,
			'description' => (string)($template['description'] ?? ''),
			'language'    => (string)($template['language'] ?? ''),
			'templateKey' => (string)($template['templateKey'] ?? ''),
			'cases'       => array_values((array)($template['cases'] ?? [])),
			'skillRefs'   => [],
			'agentId'     => $agentId,
			'template'    => false,
		];
	}//end copyOf()

	/**
	 * Copy the template $datasetId for the agent $agentId, when $uid may read the
	 * template and acts as owner of the agent.
	 *
	 * @param string $datasetId The template's uuid.
	 * @param string $agentId   The agent's uuid.
	 * @param string $uid       The caller.
	 *
	 * @return ObjectEntity|null The new dataset; null when the template cannot be read
	 *                           or the caller does not own the agent.
	 *
	 * @throws InvalidArgumentException When the dataset is not a template.
	 *
	 * @spec openspec/changes/reuse-scheduled-bias-evals/specs/agent-evals/spec.md#requirement-a-ready-made-bias-test-set-can-be-used-for-an-agent-req-biaseval-002
	 */
	public function copyForOwner(string $datasetId, string $agentId, string $uid): ?ObjectEntity {
		$template = $this->readableDataset(datasetId: $datasetId);
		$agent    = $this->ownedAgent(agentId: $agentId, uid: $uid);
		if ($template === null || $agent === null) {
			return null;
		}

		return $this->copy(template: $template, agentId: (string)$agent->getUuid(), agentName: (string)$agent->getName());
	}//end copyForOwner()

	/**
	 * Store a copy of the template for the agent, owned by the signed-in user.
	 *
	 * @param ObjectEntity $template  The template.
	 * @param string       $agentId   The agent the copy is made for.
	 * @param string       $agentName The agent's name.
	 *
	 * @return ObjectEntity The new dataset.
	 *
	 * @throws InvalidArgumentException When the dataset is not a template.
	 *
	 * @spec openspec/changes/reuse-scheduled-bias-evals/specs/agent-evals/spec.md#requirement-a-ready-made-bias-test-set-can-be-used-for-an-agent-req-biaseval-002
	 */
	public function copy(ObjectEntity $template, string $agentId, string $agentName): ObjectEntity {
		if (self::isTemplate(dataset: $template) === false) {
			throw new InvalidArgumentException('Only a template can be used for an agent.');
		}

		return $this->objectService->saveObject(
			object: self::copyOf(template: $template->getObject(), agentId: $agentId, agentName: $agentName),
			options: new ObjectSaveOptions(target: new ObjectFindTarget(register: self::REGISTER, schema: self::SCHEMA))
		);
	}//end copy()

	/**
	 * The dataset, read with the caller's rights; null when absent or unreadable.
	 *
	 * @param string $datasetId The dataset uuid.
	 *
	 * @return ObjectEntity|null
	 */
	private function readableDataset(string $datasetId): ?ObjectEntity {
		try {
			$dataset = $this->objectService->find(
				id: $datasetId,
				options: new ObjectFindOptions(register: self::REGISTER, schema: self::SCHEMA)
			);
		} catch (Throwable $e) {
			return null;
		}

		if (($dataset instanceof ObjectEntity) === false) {
			return null;
		}

		return $dataset;
	}//end readableDataset()

	/**
	 * The agent, when the caller acts as its owner; null otherwise.
	 *
	 * @param string $agentId The agent uuid.
	 * @param string $uid     The caller.
	 *
	 * @return Agent|null
	 */
	private function ownedAgent(string $agentId, string $uid): ?Agent {
		if ($agentId === '') {
			return null;
		}

		try {
			$agent = $this->agentMapper->findByUuid($agentId);
		} catch (Throwable $e) {
			return null;
		}

		if ($this->seedCustody->actsAsOwner(owner: $agent->getOwner(), uid: $uid) === false) {
			return null;
		}

		return $agent;
	}//end ownedAgent()
}//end class

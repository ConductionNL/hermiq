<?php

/**
 * Copies a read-only eval template into an agent owner's own dataset.
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
 * @spec openspec/changes/reuse-scheduled-bias-evals/specs/agent-evals/spec.md#requirement-a-ready-made-bias-test-set-can-be-used-for-an-agent-req-biaseval-002
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service;

use OCA\Hermiq\Kernel\Contracts\EvalTemplateServiceInterface;
use OCA\OpenRegister\Service\ObjectService;

/**
 * "Use for this agent" (REQ-BIASEVAL-002). The template is any dataset marked
 * `template`; every signed-in user may read one, so the copy needs no ownership
 * of the template, only of the agent. The copy is a plain dataset the caller
 * owns: its cases, the template's key and language, the agent it is for and the
 * template it came from. Nothing is shared between the two afterwards.
 *
 * @spec openspec/changes/reuse-scheduled-bias-evals/specs/agent-evals/spec.md#requirement-a-ready-made-bias-test-set-can-be-used-for-an-agent-req-biaseval-002
 */
class EvalTemplateService implements EvalTemplateServiceInterface {

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService Writes the copy.
	 * @param EvalOwnership $ownership     Reads the template and the caller's agent.
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly EvalOwnership $ownership,
	) {
	}//end __construct()

	/**
	 * Copy the template into a new dataset owned by the caller and linked to the agent.
	 *
	 * @param string $templateId The template dataset's uuid.
	 * @param string $agentId    The agent the copy is for.
	 * @param string $uid        The caller.
	 *
	 * @return array{id: string, name: string, agentId: string, caseCount: int}
	 *
	 * @throws EvalActionException 404 when the template or the caller's agent is not found, 400 when the dataset is not a template.
	 *
	 * @spec openspec/changes/reuse-scheduled-bias-evals/specs/agent-evals/spec.md#requirement-a-ready-made-bias-test-set-can-be-used-for-an-agent-req-biaseval-002
	 */
	public function useForAgent(string $templateId, string $agentId, string $uid): array {
		$template = $this->ownership->dataset(datasetId: $templateId);
		if ($template === null || $uid === '') {
			throw new EvalActionException('Dataset not found', 404);
		}

		$data = $template->getObject();
		if (($data['template'] ?? false) !== true) {
			throw new EvalActionException('Only a template can be used for an agent', 400);
		}

		$agent = $this->ownership->ownedAgent(agentId: $agentId, uid: $uid);
		if ($agent === null) {
			throw new EvalActionException('Agent not found', 404);
		}

		$cases = $data['cases'] ?? [];
		if (is_array($cases) === false) {
			$cases = [];
		}

		$name = self::copyName(templateName: (string)($data['name'] ?? ''), agentName: (string)($agent->getName() ?? ''));
		$copy = [
			'name' => $name,
			'description' => (string)($data['description'] ?? ''),
			'cases' => array_values($cases),
			'template' => false,
			'templateKey' => ($data['templateKey'] ?? null),
			'language' => ($data['language'] ?? null),
			'agentId' => (string)$agent->getUuid(),
			'copiedFrom' => (string)$template->getUuid(),
		];

		// No RBAC: EvalDataset lists no create grant, so a write is owner-only; the
		// caller owns the agent (checked above) and becomes the copy's owner.
		$saved = $this->objectService->saveObject(
			object: $copy,
			register: EvalOwnership::REGISTER,
			schema: EvalOwnership::DATASET_SCHEMA,
			_rbac: false,
			_owner: $uid
		);

		return [
			'id' => (string)$saved->getUuid(),
			'name' => $name,
			'agentId' => (string)$agent->getUuid(),
			'caseCount' => count($copy['cases']),
		];
	}//end useForAgent()

	/**
	 * The copy's name: the template's name without its "(template)" marker, then the agent.
	 *
	 * @param string $templateName The template's name.
	 * @param string $agentName    The agent's name.
	 *
	 * @return string
	 */
	private static function copyName(string $templateName, string $agentName): string {
		$base = trim((string)preg_replace('/\s*\([^()]*\)\s*$/u', '', $templateName));
		if ($base === '') {
			$base = $templateName;
		}

		if (trim($agentName) === '') {
			return $base;
		}

		return $base . ' — ' . trim($agentName);
	}//end copyName()
}//end class

<?php

/**
 * Copies a read-only eval template into a dataset of an agent owner's own.
 *
 * @category Kernel
 * @package  OCA\Hermiq\Kernel\Contracts
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

namespace OCA\Hermiq\Kernel\Contracts;

use OCA\Hermiq\Service\EvalActionException;

/**
 * "Use for this agent": a template becomes an editable dataset linked to the agent.
 *
 * @spec openspec/changes/reuse-scheduled-bias-evals/specs/agent-evals/spec.md#requirement-a-ready-made-bias-test-set-can-be-used-for-an-agent-req-biaseval-002
 */
interface EvalTemplateServiceInterface {

	/**
	 * Copy the template into a new dataset owned by the caller and linked to the agent.
	 *
	 * @param string $templateId The template dataset's uuid.
	 * @param string $agentId    The agent the copy is for.
	 * @param string $uid        The caller.
	 *
	 * @return array{id: string, name: string, agentId: string, caseCount: int} The new dataset.
	 *
	 * @throws EvalActionException 404 when the template or the caller's agent is not found, 400 when the dataset is not a template.
	 *
	 * @spec openspec/changes/reuse-scheduled-bias-evals/specs/agent-evals/spec.md#requirement-a-ready-made-bias-test-set-can-be-used-for-an-agent-req-biaseval-002
	 */
	public function useForAgent(string $templateId, string $agentId, string $uid): array;
}//end interface

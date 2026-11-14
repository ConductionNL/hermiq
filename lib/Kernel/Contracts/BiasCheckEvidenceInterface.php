<?php

/**
 * The latest bias check of an agent, and how recent every scheduled agent's is.
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
 * @spec openspec/changes/reuse-scheduled-bias-evals/specs/agent-evals/spec.md#requirement-the-latest-bias-check-is-visible-as-evidence-req-biaseval-004
 */

declare(strict_types=1);

namespace OCA\Hermiq\Kernel\Contracts;

/**
 * Bias-check evidence for the factsheet and the compliance dashboard.
 *
 * @spec openspec/changes/reuse-scheduled-bias-evals/specs/agent-evals/spec.md#requirement-the-latest-bias-check-is-visible-as-evidence-req-biaseval-004
 */
interface BiasCheckEvidenceInterface {

	/**
	 * The agent's latest completed run of a bias check dataset, or null when it has none.
	 *
	 * @param string $agentId The agent.
	 *
	 * @return array{runId: string, datasetId: string, passRate: float, date: string}|null
	 *
	 * @spec openspec/changes/reuse-scheduled-bias-evals/specs/agent-evals/spec.md#requirement-the-latest-bias-check-is-visible-as-evidence-req-biaseval-004
	 */
	public function latestFor(string $agentId): ?array;

	/**
	 * The bias-eval-recency control status: satisfied when every agent with an
	 * enabled bias check schedule had a bias run in the last 45 days, partial when
	 * some did, unevidenced when none did or no agent has one.
	 *
	 * @return array{status: string, detail: string}
	 *
	 * @spec openspec/changes/reuse-scheduled-bias-evals/specs/agent-evals/spec.md#requirement-the-latest-bias-check-is-visible-as-evidence-req-biaseval-004
	 */
	public function recency(): array;
}//end interface

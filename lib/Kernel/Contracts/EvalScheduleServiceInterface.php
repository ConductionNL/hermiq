<?php

/**
 * Runs an eval dataset against an agent on a schedule.
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
 * @spec openspec/changes/reuse-scheduled-bias-evals/specs/agent-evals/spec.md#requirement-a-dataset-can-run-against-an-agent-on-a-schedule-req-biaseval-003
 */

declare(strict_types=1);

namespace OCA\Hermiq\Kernel\Contracts;

use OCA\Hermiq\Service\EvalActionException;

/**
 * Eval schedules: set by the owner of a dataset and an agent, run by EvalScheduleTask.
 *
 * @spec openspec/changes/reuse-scheduled-bias-evals/specs/agent-evals/spec.md#requirement-a-dataset-can-run-against-an-agent-on-a-schedule-req-biaseval-003
 */
interface EvalScheduleServiceInterface {

	/**
	 * Create or change a schedule of the dataset for the caller.
	 *
	 * @param string               $datasetId The dataset.
	 * @param array<string, mixed> $input     agentId, cronExpr, enabled, regressionThresholdPercent and, to change one, id.
	 * @param string               $uid       The caller.
	 *
	 * @return array<string, mixed> The schedule as the page shows it.
	 *
	 * @throws EvalActionException 404 when the dataset, the agent or the schedule is not the caller's; 400 on an unreadable schedule.
	 *
	 * @spec openspec/changes/reuse-scheduled-bias-evals/specs/agent-evals/spec.md#requirement-a-dataset-can-run-against-an-agent-on-a-schedule-req-biaseval-003
	 */
	public function save(string $datasetId, array $input, string $uid): array;

	/**
	 * The caller's schedules of the dataset.
	 *
	 * @param string $datasetId The dataset.
	 * @param string $uid       The caller.
	 *
	 * @return list<array<string, mixed>>
	 *
	 * @throws EvalActionException 404 when the dataset is not the caller's.
	 *
	 * @spec openspec/changes/reuse-scheduled-bias-evals/specs/agent-evals/spec.md#requirement-a-dataset-can-run-against-an-agent-on-a-schedule-req-biaseval-003
	 */
	public function listForDataset(string $datasetId, string $uid): array;

	/**
	 * Run every enabled schedule that is due, advancing each one's next run first.
	 *
	 * @return int How many runs were started.
	 *
	 * @spec openspec/changes/reuse-scheduled-bias-evals/specs/agent-evals/spec.md#requirement-a-dataset-can-run-against-an-agent-on-a-schedule-req-biaseval-003
	 */
	public function runDue(): int;
}//end interface

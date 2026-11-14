<?php

/**
 * What a scheduled eval run that failed its regression gate dropped.
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

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Mcp\Db\Agent;

/**
 * The dataset, the agent and the run result a drop notification is about.
 *
 * @spec openspec/changes/reuse-scheduled-bias-evals/specs/agent-evals/spec.md#requirement-a-dataset-can-run-against-an-agent-on-a-schedule-req-biaseval-003
 */
final class EvalScheduleDrop {

	/**
	 * Constructor.
	 *
	 * @param ObjectEntity         $dataset The dataset that ran.
	 * @param Agent                $agent   The agent it ran against.
	 * @param array<string, mixed> $result  What EvalRunService::run() returned.
	 */
	public function __construct(
		public readonly ObjectEntity $dataset,
		public readonly Agent $agent,
		public readonly array $result,
	) {
	}//end __construct()
}//end class

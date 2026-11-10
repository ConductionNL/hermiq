<?php

/**
 * Hermiq JudgedOutput
 *
 * The agent output a rubric judge grades, and the organisation the judge call runs for.
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
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/agent-evals/specs/agent-evals/spec.md#requirement-llm-as-judge-scoring-goes-through-the-existing-providerfactory-chokepoint
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service;

/**
 * What a judge call grades, and for whom.
 *
 * @spec openspec/changes/agent-evals/specs/agent-evals/spec.md#requirement-llm-as-judge-scoring-goes-through-the-existing-providerfactory-chokepoint
 */
final class JudgedOutput {

	/**
	 * Build the judged output.
	 *
	 * @param string      $actualOutput The agent's real output.
	 * @param string|null $organisation The triggering agent's organisation, threaded to the judge call.
	 *
	 * @spec openspec/changes/agent-evals/specs/agent-evals/spec.md#requirement-llm-as-judge-scoring-goes-through-the-existing-providerfactory-chokepoint
	 */
	public function __construct(
		public readonly string $actualOutput,
		public readonly ?string $organisation,
	) {
	}//end __construct()
}//end class

<?php

/**
 * Hermiq ResearchModel.
 *
 * The one model call a research run makes per phase (design D2): the plan, and
 * the report. A port, so the runner is tested with a stubbed model and the
 * adapter alone knows which provider path answers.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Research
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/tools-deep-research-report/specs/deep-research/spec.md#requirement-a-user-can-start-deep-research-and-sees-the-plan-req-research-001
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Research;

/**
 * Generates one completion for a research run.
 *
 * @spec openspec/changes/tools-deep-research-report/specs/deep-research/spec.md#requirement-a-user-can-start-deep-research-and-sees-the-plan-req-research-001
 */
interface ResearchModel {
	/**
	 * One completion of a single prompt, for this user in this organisation.
	 *
	 * @param string $prompt       The prompt.
	 * @param string $userId       The user the research runs for.
	 * @param string $organisation The organisation whose model policy applies.
	 *
	 * @return string The generated text.
	 *
	 * @spec openspec/changes/tools-deep-research-report/specs/deep-research/spec.md#requirement-a-user-can-start-deep-research-and-sees-the-plan-req-research-001
	 */
	public function generate(string $prompt, string $userId, string $organisation): string;
}//end interface

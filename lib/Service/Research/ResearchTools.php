<?php

/**
 * Hermiq ResearchTools.
 *
 * Opens the toolbox a research run gathers with, for one agent in one
 * conversation (design D2). A port, so the runner is tested with stubbed tools.
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
 * @spec openspec/changes/tools-deep-research-report/specs/deep-research/spec.md#requirement-the-research-goes-through-the-agents-governed-tools-and-records-every-source-req-research-002
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Research;

/**
 * Opens a research run's toolbox.
 *
 * @spec openspec/changes/tools-deep-research-report/specs/deep-research/spec.md#requirement-the-research-goes-through-the-agents-governed-tools-and-records-every-source-req-research-002
 */
interface ResearchTools {
	/**
	 * The toolbox for this agent, or null when the agent may not both search and read the web.
	 *
	 * @param string $agentId        The agent running the research.
	 * @param string $organisation   The run's organisation, whose tool policy applies.
	 * @param string $conversationId The conversation the run belongs to.
	 *
	 * @return ResearchToolbox|null The toolbox.
	 *
	 * @spec openspec/changes/tools-deep-research-report/specs/deep-research/spec.md#requirement-the-research-goes-through-the-agents-governed-tools-and-records-every-source-req-research-002
	 */
	public function open(string $agentId, string $organisation, string $conversationId): ?ResearchToolbox;
}//end interface

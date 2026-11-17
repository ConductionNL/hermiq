<?php

/**
 * Contract of AgentDraftService
 *
 * The public surface of AgentDraftService that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Hermiq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/hermiq
 *
 * @spec exclude Structural contract, no functional requirement.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Hermiq\Kernel\Contracts;

use OCA\Hermiq\Service\Agent\AgentDraftUnreadableException;

/**
 * Contract of AgentDraftService.
 *
 * @category Kernel
 * @package  OCA\Hermiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/hermiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface AgentDraftServiceInterface {

	/**
	 * The draft as the form reads it, with a finding per field that will not work.
	 *
	 * @param string $text The draft block's text.
	 * @param string $uid The person who will save the agent.
	 *
	 * @return array{draft: array<string, mixed>, findings: array<int, array{field: string, message: string, suggestion: string}>}
	 *
	 * @throws AgentDraftUnreadableException When the text is not a JSON object with a name.
	 *
	 * @spec openspec/changes/agents-plain-language-builder/specs/agent-management-ui/spec.md#requirement-a-draft-opens-in-the-full-agent-form-after-a-check-req-agbuild-002
	 */
	public function check(string $text, string $uid): array;
}//end interface

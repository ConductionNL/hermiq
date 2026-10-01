<?php

/**
 * Hermiq StartValuesRejectedException.
 *
 * The answers to an agent's start fields were not accepted: a required field
 * is empty, an answer is of the wrong kind, or the input filter blocked it.
 * Carries the reason per field key so the chat page can mark each field.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Agent
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
 * @spec openspec/changes/agents-instruction-variables/specs/agent-management-ui/spec.md#requirement-an-agent-can-ask-for-fields-before-a-conversation-starts-req-agvar-002
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Agent;

use RuntimeException;

/**
 * Start field answers that were not accepted, with the reason per field (HTTP 422).
 *
 * @spec openspec/changes/agents-instruction-variables/specs/agent-management-ui/spec.md#requirement-an-agent-can-ask-for-fields-before-a-conversation-starts-req-agvar-002
 */
class StartValuesRejectedException extends RuntimeException {

	/**
	 * Constructor.
	 *
	 * @param array<string, string> $problems Field key => reason.
	 */
	public function __construct(
		private readonly array $problems,
	) {
		parent::__construct(message: 'Some answers were not accepted.', code: 422);
	}//end __construct()

	/**
	 * The reason per field key.
	 *
	 * @return array<string, string>
	 */
	public function getProblems(): array {
		return $this->problems;
	}//end getProblems()
}//end class

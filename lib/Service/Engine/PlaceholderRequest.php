<?php

/**
 * Hermiq PlaceholderRequest.
 *
 * What the placeholder values of one turn are worked out from: the acting
 * person, the agent's data, the session's organisation, the companion's context
 * and the session's start field answers.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Engine
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
 * @spec openspec/specs/agent-management-ui/spec.md#requirement-placeholders-in-an-agents-instructions-are-filled-in-per-turn-req-agvar-001
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Engine;

/**
 * The inputs of one turn's placeholder values.
 *
 * @spec openspec/specs/agent-management-ui/spec.md#requirement-placeholders-in-an-agents-instructions-are-filled-in-per-turn-req-agvar-001
 */
final class PlaceholderRequest {

	/**
	 * The session's organisation UUID.
	 *
	 * @var string
	 */
	public string $organisation = '';

	/**
	 * The companion's context snapshot.
	 *
	 * @var array<string, mixed>
	 */
	public array $appContext = [];

	/**
	 * The session's start field answers.
	 *
	 * @var array<string, mixed>
	 */
	public array $startValues = [];

	/**
	 * Build the request.
	 *
	 * @param string               $userId    The acting person (a schedule's owner on a scheduled run).
	 * @param array<string, mixed> $agentData The agent's data (name, startFields).
	 */
	public function __construct(
		public readonly string $userId,
		public readonly array $agentData,
	) {
	}//end __construct()
}//end class

<?php

/**
 * Hermiq OutputHold
 *
 * Whether a scheduled run's output is held for review before it is
 * delivered: the schedule's `holdOutput` when it sets one, else the agent's.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Schedule
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
 * @spec openspec/changes/oversight-ask-and-hold/specs/human-approval-gate/spec.md#requirement-written-output-can-be-held-until-a-reviewer-releases-it-req-askhold-003
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Schedule;

/**
 * Resolves `holdOutput` across a schedule and its agent.
 */
final class OutputHold {

	/**
	 * Whether the output of a run is held for review.
	 *
	 * A schedule that sets `holdOutput` (true or false) decides; a schedule that
	 * leaves it unset follows its agent. Only a real boolean `true` holds.
	 *
	 * @param array<string, mixed> $schedule The schedule's data.
	 * @param array<string, mixed>|null $agent The agent's data, or null when it could not be read.
	 *
	 * @return bool True when the output is held.
	 *
	 * @spec openspec/changes/oversight-ask-and-hold/specs/human-approval-gate/spec.md#requirement-written-output-can-be-held-until-a-reviewer-releases-it-req-askhold-003
	 */
	public static function holds(array $schedule, ?array $agent): bool {
		if (is_bool($schedule['holdOutput'] ?? null) === true) {
			return $schedule['holdOutput'];
		}

		return (($agent['holdOutput'] ?? false) === true);
	}//end holds()
}//end class

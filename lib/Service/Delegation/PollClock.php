<?php

/**
 * Hermiq PollClock
 *
 * The wall clock and the pause the parent turn polls its delegated parts with,
 * behind one seam so a test can run the wait on a fake clock.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Delegation
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
 * @spec openspec/changes/delegation-runs-in-parallel/specs/sub-agent-delegation/spec.md#requirement-a-part-no-worker-picks-up-runs-inline-req-dlgpar-002
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Delegation;

/**
 * Seconds now, and a pause between polls.
 *
 * @spec openspec/changes/delegation-runs-in-parallel/specs/sub-agent-delegation/spec.md#requirement-a-part-no-worker-picks-up-runs-inline-req-dlgpar-002
 */
class PollClock {

	/**
	 * The current Unix time in seconds.
	 *
	 * @return int
	 *
	 * @spec openspec/changes/delegation-runs-in-parallel/specs/sub-agent-delegation/spec.md#requirement-a-part-no-worker-picks-up-runs-inline-req-dlgpar-002
	 */
	public function now(): int {
		return time();
	}//end now()

	/**
	 * Pause before the next poll.
	 *
	 * @param int $milliseconds How long to pause.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/delegation-runs-in-parallel/specs/sub-agent-delegation/spec.md#requirement-a-part-no-worker-picks-up-runs-inline-req-dlgpar-002
	 */
	public function wait(int $milliseconds): void {
		usleep(max(0, $milliseconds) * 1000);
	}//end wait()
}//end class

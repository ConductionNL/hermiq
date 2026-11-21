<?php

/**
 * Hermiq ResearchJob.
 *
 * Runs one deep research run in the background (tools-deep-research-report,
 * design D1), as the person who started it, so the request that started it
 * never waits on the research.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category BackgroundJob
 * @package  OCA\Hermiq\BackgroundJob
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

namespace OCA\Hermiq\BackgroundJob;

use OCA\Hermiq\Service\Research\ResearchAsUser;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;

/**
 * Background job that runs one research handoff.
 *
 * @psalm-api
 *
 * @spec openspec/changes/tools-deep-research-report/specs/deep-research/spec.md#requirement-a-user-can-start-deep-research-and-sees-the-plan-req-research-001
 */
class ResearchJob extends QueuedJob {
	/**
	 * Constructor.
	 *
	 * @param ITimeFactory   $time     Time factory for the base job.
	 * @param ResearchAsUser $research Runs the research as its person.
	 *
	 * @spec openspec/changes/tools-deep-research-report/specs/deep-research/spec.md#requirement-a-user-can-start-deep-research-and-sees-the-plan-req-research-001
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly ResearchAsUser $research,
	) {
		parent::__construct(time: $time);
	}//end __construct()

	/**
	 * Run the research the argument names; an argument without a handoff id runs nothing.
	 *
	 * @param mixed $argument `['handoffId' => uuid]`, a JSON round-trip.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/tools-deep-research-report/specs/deep-research/spec.md#requirement-a-user-can-start-deep-research-and-sees-the-plan-req-research-001
	 */
	protected function run($argument): void {
		$named = (array)$argument;
		if (is_string($named['handoffId'] ?? null) === false) {
			return;
		}

		$this->research->run(handoffId: $named['handoffId']);
	}//end run()
}//end class

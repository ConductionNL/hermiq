<?php

/**
 * Hermiq DelegatedRunJob
 *
 * Runs one part of a parallel delegation on a background worker
 * (delegation-runs-in-parallel D2). The job claims the part's DelegatedRun
 * first; when the parent or another worker already claimed it, the job does
 * nothing, so a part runs once.
 *
 * @category Cron
 * @package  OCA\Hermiq\BackgroundJob
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

namespace OCA\Hermiq\BackgroundJob;

use OCA\Hermiq\Kernel\Contracts\DelegationServiceInterface;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;

/**
 * Claims and runs one delegated part.
 *
 * @spec openspec/changes/delegation-runs-in-parallel/specs/sub-agent-delegation/spec.md#requirement-a-part-no-worker-picks-up-runs-inline-req-dlgpar-002
 */
class DelegatedRunJob extends QueuedJob {

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory               $time       Core time factory required by QueuedJob.
	 * @param DelegationServiceInterface $delegation Claims and runs the part.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly DelegationServiceInterface $delegation,
	) {
		parent::__construct(time: $time);
	}//end __construct()

	/**
	 * Claim and run the part named by `{delegatedRunId}`.
	 *
	 * @param mixed $argument The queued argument.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/delegation-runs-in-parallel/specs/sub-agent-delegation/spec.md#requirement-several-parts-of-one-task-run-on-sub-agents-at-the-same-time-req-dlgpar-001
	 * @spec openspec/changes/delegation-runs-in-parallel/specs/sub-agent-delegation/spec.md#requirement-a-part-no-worker-picks-up-runs-inline-req-dlgpar-002
	 */
	protected function run($argument): void {
		$delegatedRunId = '';
		if (is_array($argument) === true && is_string($argument['delegatedRunId'] ?? null) === true) {
			$delegatedRunId = $argument['delegatedRunId'];
		}

		if ($delegatedRunId === '') {
			return;
		}

		$this->delegation->runStoredPart(delegatedRunId: $delegatedRunId, claimant: 'worker');
	}//end run()
}//end class

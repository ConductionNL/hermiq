<?php

/**
 * Hermiq ResearchJob.
 *
 * Runs one deep research run in the background (tools-deep-research-report,
 * design D1), as the person who started it: the governed tools read their
 * grants and the guards under that person, and the report is theirs.
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

use OCA\Hermiq\Service\Handoff\HandoffService;
use OCA\Hermiq\Service\Research\ResearchRunner;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use OCP\IUserManager;
use OCP\IUserSession;

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
	 * @param ITimeFactory   $time        Time factory for the base job.
	 * @param ResearchRunner $runner      Runs the research.
	 * @param HandoffService $handoffs    Reads who started the run.
	 * @param IUserManager   $userManager Resolves that person.
	 * @param IUserSession   $userSession The session the run acts in.
	 *
	 * @spec openspec/changes/tools-deep-research-report/specs/deep-research/spec.md#requirement-a-user-can-start-deep-research-and-sees-the-plan-req-research-001
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly ResearchRunner $runner,
		private readonly HandoffService $handoffs,
		private readonly IUserManager $userManager,
		private readonly IUserSession $userSession,
	) {
		parent::__construct(time: $time);
	}//end __construct()

	/**
	 * Run the job: the research the argument names, as the person who started it.
	 *
	 * @param mixed $argument `['handoffId' => uuid]`; checked, as job arguments are a JSON round-trip.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/tools-deep-research-report/specs/deep-research/spec.md#requirement-a-user-can-start-deep-research-and-sees-the-plan-req-research-001
	 */
	protected function run($argument): void {
		$handoffId = '';
		if (is_array($argument) === true && is_string($argument['handoffId'] ?? null) === true) {
			$handoffId = $argument['handoffId'];
		}

		$this->runAs(handoffId: $handoffId);
	}//end run()

	/**
	 * Run the research as the person who started it; nothing runs when that person is gone.
	 *
	 * @param string $handoffId The research handoff.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/tools-deep-research-report/specs/deep-research/spec.md#requirement-a-user-can-start-deep-research-and-sees-the-plan-req-research-001
	 */
	public function runAs(string $handoffId): void {
		$handoff = $this->handoffs->find(handoffId: $handoffId);
		$user    = $this->userManager->get((string)($handoff['requestedBy'] ?? ''));
		if ($handoff === null || $user === null) {
			return;
		}

		$prior = $this->userSession->getUser();
		$this->userSession->setUser($user);
		try {
			$this->runner->run(handoffId: $handoffId);
		} finally {
			$this->userSession->setUser($prior);
		}
	}//end runAs()
}//end class

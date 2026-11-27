<?php

/**
 * Hermiq ResearchAsUser.
 *
 * Runs one research run as the person who started it (design D1): the
 * governed tools read their grants and guards under that person, and the
 * report is theirs. The session is restored afterwards, also when the run
 * throws; nothing runs when the handoff or its person is gone.
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

use OCA\Hermiq\Service\Handoff\HandoffService;
use OCP\IUserManager;
use OCP\IUserSession;

/**
 * Runs a research handoff in its person's session.
 *
 * @spec openspec/changes/tools-deep-research-report/specs/deep-research/spec.md#requirement-a-user-can-start-deep-research-and-sees-the-plan-req-research-001
 */
class ResearchAsUser {
	/**
	 * Constructor.
	 *
	 * @param ResearchRunner $runner      Runs the research.
	 * @param HandoffService $handoffs    Reads who started the run.
	 * @param IUserManager   $userManager Resolves that person.
	 * @param IUserSession   $userSession The session the run acts in.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ResearchRunner $runner,
		private readonly HandoffService $handoffs,
		private readonly IUserManager $userManager,
		private readonly IUserSession $userSession,
	) {
	}//end __construct()

	/**
	 * Run the research as the person who started it.
	 *
	 * @param string $handoffId The research handoff.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/tools-deep-research-report/specs/deep-research/spec.md#requirement-a-user-can-start-deep-research-and-sees-the-plan-req-research-001
	 */
	public function run(string $handoffId): void {
		$person = (string)($this->handoffs->find(handoffId: $handoffId)['requestedBy'] ?? '');
		$user   = $this->userManager->get($person);
		if ($person === '' || $user === null) {
			return;
		}

		$prior = $this->userSession->getUser();
		$this->userSession->setUser($user);
		try {
			$this->runner->run(handoffId: $handoffId);
		} finally {
			$this->userSession->setUser($prior);
		}
	}//end run()
}//end class

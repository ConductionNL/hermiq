<?php

/**
 * Hermiq ActingUserScope.
 *
 * Runs a piece of work as a given user and restores the prior identity afterwards,
 * whatever the outcome. The knowledge graph uses it so that "can this user read the
 * record behind a node" is answered by the record's own authorization with that
 * user in the session, and so that an extraction job reads only what the user who
 * enqueued it can read. Same impersonate-and-restore contract as the Talk turn path.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Graph
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
 * @spec openspec/specs/knowledge-graph/spec.md#requirement-extraction-runs-as-the-acting-user-in-audited-background-jobs
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Graph;

use OCP\IUserManager;
use OCP\IUserSession;
use RuntimeException;

/**
 * Impersonate-and-restore around one callable.
 *
 * @spec openspec/specs/knowledge-graph/spec.md#requirement-extraction-runs-as-the-acting-user-in-audited-background-jobs
 */
class ActingUserScope {

	/**
	 * Constructor.
	 *
	 * @param IUserSession $session The user session.
	 * @param IUserManager $users The user manager.
	 */
	public function __construct(
		private readonly IUserSession $session,
		private readonly IUserManager $users,
	) {
	}//end __construct()

	/**
	 * Run the work as the user, restoring the prior session user afterwards.
	 *
	 * @param string $uid The acting user id.
	 * @param callable $work The work; its return value is returned.
	 *
	 * @return mixed What the work returned.
	 *
	 * @throws RuntimeException When the user does not exist.
	 *
	 * @spec openspec/specs/knowledge-graph/spec.md#requirement-extraction-runs-as-the-acting-user-in-audited-background-jobs
	 */
	public function run(string $uid, callable $work): mixed {
		$prior = $this->session->getUser();
		if ($prior !== null && $prior->getUID() === $uid) {
			return $work();
		}

		$user = null;
		if ($uid !== '') {
			$user = $this->users->get($uid);
		}

		if ($user === null) {
			throw new RuntimeException('Unknown acting user.');
		}

		$this->session->setUser($user);
		try {
			return $work();
		} finally {
			$this->session->setUser($prior);
		}

	}//end run()
}//end class

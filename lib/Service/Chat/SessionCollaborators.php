<?php

/**
 * Hermiq SessionCollaborators.
 *
 * The services the session endpoints share beyond the object store: the Talk
 * room a session owns, the agent access check, the session's subject and the
 * approvals its agent waits on.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Chat
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
 * @spec openspec/changes/session-about-a-subject/tasks.md
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Chat;

use OCA\Hermiq\Service\AgentAccessService;
use OCA\Hermiq\Service\Talk\TalkSessionRoom;

/**
 * Holds the session endpoints' collaborators.
 *
 * @spec openspec/changes/session-about-a-subject/tasks.md
 */
class SessionCollaborators {

	/**
	 * Constructor.
	 *
	 * @param TalkSessionRoom         $sessionRoom      Creates and renames the Talk room a session owns.
	 * @param AgentAccessService      $agentAccess      Decides whether the caller may use an agent.
	 * @param SessionSubject          $subject          Reads and filters on the record a session is about.
	 * @param SessionPendingApprovals $pendingApprovals The approvals a session's agent waits on.
	 *
	 * @spec openspec/changes/session-about-a-subject/tasks.md
	 */
	public function __construct(
		public readonly TalkSessionRoom $sessionRoom,
		public readonly AgentAccessService $agentAccess,
		public readonly SessionSubject $subject,
		public readonly SessionPendingApprovals $pendingApprovals,
	) {
	}//end __construct()
}//end class
